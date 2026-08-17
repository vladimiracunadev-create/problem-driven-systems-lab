import com.sun.net.httpserver.HttpExchange;
import com.sun.net.httpserver.HttpServer;

import java.io.IOException;
import java.io.OutputStream;
import java.net.InetSocketAddress;
import java.net.URI;
import java.net.URLDecoder;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.ArrayBlockingQueue;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicInteger;
import java.util.concurrent.atomic.LongAdder;

/**
 * Caso 14 — Agotamiento del pool de conexiones — stack Java 21.
 *
 * Leaky: sin timeout de adquisicion y con el `release()` solo en el camino
 * feliz. Cada excepcion se lleva una conexion que nunca vuelve al pool.
 * Managed: `poll(timeout)` para el deadline y **try-with-resources** para la
 * devolucion garantizada.
 *
 * Primitiva Java distintiva:
 *   `ArrayBlockingQueue<Conn>` como pool — es la estructura sobre la que estan
 *   construidos HikariCP y compañia — mas un `Lease implements AutoCloseable`.
 *
 *   Lo decisivo es que try-with-resources **no depende de que el programador se
 *   acuerde**: el compilador genera el `finally` que llama a `close()`, y lo
 *   genera para todos los caminos de salida, incluida una excepcion lanzada
 *   dentro del propio bloque. La unica forma de fugar una conexion con
 *   try-with-resources es no usarlo.
 *
 *   `poll(timeout, unit)` es la otra mitad. Sin el, `take()` espera para
 *   siempre: un hilo del pool de HTTP bloqueado indefinidamente, que en un
 *   thread dump aparece como `WAITING (parking)` sobre el `ArrayBlockingQueue`
 *   y no dice por que.
 *
 * El "query" es un `Thread.sleep` a proposito, al reves que en el caso 13. Una
 * conexion se retiene mientras se espera a la red, no mientras se quema CPU.
 */
public class Main {

    private static final String CASE_NAME = "14 - Agotamiento del pool de conexiones";
    private static final String STACK = System.getenv().getOrDefault("APP_STACK", "Java 21");
    private static final int PORT = Integer.parseInt(System.getenv().getOrDefault("PORT", "8080"));

    private static final long ACQUIRE_TIMEOUT_MS = 200;
    /** Sin timeout la variante leaky no terminaria. El watchdog permite medirla. */
    private static final long LEAKY_WATCHDOG_MS = 2000;

    private static final class Conn {
        final int id;
        int uses;
        Conn(int id) { this.id = id; }
    }

    /** Pool sobre ArrayBlockingQueue: la misma base que usan los pools reales. */
    private static final class Pool {
        final int size;
        final ArrayBlockingQueue<Conn> free;
        final LongAdder acquired = new LongAdder();
        final LongAdder released = new LongAdder();
        final AtomicInteger waiting = new AtomicInteger();
        final AtomicInteger waitingPeak = new AtomicInteger();

        Pool(int size) {
            this.size = size;
            this.free = new ArrayBlockingQueue<>(size);
            for (int i = 1; i <= size; i++) free.offer(new Conn(i));
        }

        /** Devuelve null si vencio el deadline. */
        Conn acquire(long timeoutMs) {
            int w = waiting.incrementAndGet();
            waitingPeak.accumulateAndGet(w, Math::max);
            try {
                Conn c = free.poll(timeoutMs, TimeUnit.MILLISECONDS);
                if (c != null) { c.uses++; acquired.increment(); }
                return c;
            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
                return null;
            } finally {
                waiting.decrementAndGet();
            }
        }

        void release(Conn c) {
            if (c == null) return;
            released.increment();
            free.offer(c);
        }

        /** Lease AutoCloseable: el compilador genera el finally que lo cierra. */
        Lease lease(long timeoutMs) {
            Conn c = acquire(timeoutMs);
            if (c == null) return null;
            return new Lease(this, c);
        }

        long leaked() { return acquired.sum() - released.sum(); }
        int available() { return free.size(); }
    }

    private static final class Lease implements AutoCloseable {
        private final Pool pool;
        final Conn conn;
        Lease(Pool pool, Conn conn) { this.pool = pool; this.conn = conn; }
        @Override public void close() { pool.release(conn); }
    }

    private static volatile Pool pool = new Pool(4);

    private static final class Slot {
        final LongAdder runs = new LongAdder();
        final LongAdder completed = new LongAdder();
        final LongAdder failedQuery = new LongAdder();
        final LongAdder failedTimeout = new LongAdder();
        final LongAdder hung = new LongAdder();
        final AtomicInteger maxLeaked = new AtomicInteger();
        final List<Double> waitSamples = new ArrayList<>();
    }

    private static final Map<String, Slot> metrics = new ConcurrentHashMap<>();
    static {
        metrics.put("leaky", new Slot());
        metrics.put("managed", new Slot());
    }

    public static void main(String[] args) throws Exception {
        HttpServer server = HttpServer.create(new InetSocketAddress(PORT), 0);
        server.createContext("/", Main::route);
        server.setExecutor(Executors.newCachedThreadPool());
        server.start();
        System.out.println("[case14-java] listening on " + PORT);
        Runtime.getRuntime().addShutdownHook(new Thread(() -> server.stop(0)));
    }

    /**
     * Reparto determinista de fallos.
     *
     * `idx % 100 < failRate` parece equivalente y no lo es: con 24 requests y
     * failRate=25 fallarian las 24, porque todos los indices son menores que 25.
     */
    private static boolean fails(int idx, int failRate) {
        return (idx * 37) % 100 < failRate;
    }

    /** El trabajo que retiene la conexion: una espera, no CPU. */
    private static void runQuery(Conn conn, int queryMs, boolean shouldFail) {
        try {
            Thread.sleep(queryMs);
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
        }
        if (shouldFail) throw new RuntimeException("query fallo en la conexion " + conn.id);
    }

    private record Outcome(String kind, double waitMs) {}

    // ------------------------------------------------------------------
    // Variante leaky: sin deadline, release solo en el camino feliz
    // ------------------------------------------------------------------

    private static Outcome workerLeaky(int idx, int queryMs, int failRate) {
        long t0 = System.nanoTime();
        Conn conn = pool.acquire(LEAKY_WATCHDOG_MS);
        double waitMs = ms(t0);
        if (conn == null) return new Outcome("hung", waitMs);

        // El bug: no hay try-with-resources ni finally. Si runQuery lanza, la
        // linea de release nunca se ejecuta. Nada en los logs dice "se fugo una
        // conexion" — el pool simplemente se achica en silencio.
        try {
            runQuery(conn, queryMs, fails(idx, failRate));
        } catch (RuntimeException e) {
            return new Outcome("failed_query", waitMs);
        }
        pool.release(conn);
        return new Outcome("completed", waitMs);
    }

    // ------------------------------------------------------------------
    // Variante managed: deadline + try-with-resources
    // ------------------------------------------------------------------

    private static Outcome workerManaged(int idx, int queryMs, int failRate) {
        long t0 = System.nanoTime();
        Lease lease = pool.lease(ACQUIRE_TIMEOUT_MS);
        double waitMs = ms(t0);
        if (lease == null) {
            // Falla rapido y de forma contable, en vez de dejar un hilo del
            // pool HTTP bloqueado indefinidamente sobre la cola.
            return new Outcome("failed_timeout", waitMs);
        }
        // El compilador genera el finally que llama a lease.close() en todos
        // los caminos de salida, incluida la excepcion de abajo.
        try (Lease l = lease) {
            runQuery(l.conn, queryMs, fails(idx, failRate));
            return new Outcome("completed", waitMs);
        } catch (RuntimeException e) {
            return new Outcome("failed_query", waitMs);
        }
    }

    private static double ms(long t0) {
        return Math.round((System.nanoTime() - t0) / 10_000.0) / 100.0;
    }

    // ------------------------------------------------------------------
    // Orquestacion
    // ------------------------------------------------------------------

    private static String runLoad(String variant, int requests, int poolSize, int queryMs, int failRate) {
        pool = new Pool(poolSize);
        ExecutorService exec = Executors.newFixedThreadPool(Math.min(requests, 256));
        List<CompletableFuture<Outcome>> futures = new ArrayList<>(requests);
        long t0 = System.nanoTime();
        for (int i = 0; i < requests; i++) {
            final int idx = i;
            futures.add(CompletableFuture.supplyAsync(
                    () -> variant.equals("leaky")
                            ? workerLeaky(idx, queryMs, failRate)
                            : workerManaged(idx, queryMs, failRate),
                    exec));
        }
        List<Outcome> results = new ArrayList<>(requests);
        for (CompletableFuture<Outcome> f : futures) results.add(f.join());
        double wallMs = ms(t0);
        exec.shutdown();

        long completed = results.stream().filter(o -> o.kind().equals("completed")).count();
        long failedQuery = results.stream().filter(o -> o.kind().equals("failed_query")).count();
        long failedTimeout = results.stream().filter(o -> o.kind().equals("failed_timeout")).count();
        long hung = results.stream().filter(o -> o.kind().equals("hung")).count();
        double[] waits = results.stream().mapToDouble(Outcome::waitMs).sorted().toArray();

        Slot s = metrics.get(variant);
        s.runs.increment();
        s.completed.add(completed);
        s.failedQuery.add(failedQuery);
        s.failedTimeout.add(failedTimeout);
        s.hung.add(hung);
        s.maxLeaked.accumulateAndGet((int) pool.leaked(), Math::max);
        synchronized (s.waitSamples) {
            for (double w : waits) s.waitSamples.add(w);
            while (s.waitSamples.size() > 500) s.waitSamples.remove(0);
        }

        String note = variant.equals("leaky")
                ? "Sin deadline y con release solo en el camino feliz: cada excepcion se lleva una conexion y el pool se achica en silencio."
                : "poll(timeout) + try-with-resources: los fallos siguen ocurriendo, pero fallan rapido y devuelven la conexion.";

        return "{\"variant\":\"" + variant + "\",\"requests\":" + requests
                + ",\"pool_size\":" + poolSize
                + ",\"query_ms\":" + queryMs
                + ",\"fail_rate_pct\":" + failRate
                + ",\"acquire_timeout_ms\":" + (variant.equals("managed") ? ACQUIRE_TIMEOUT_MS : "null")
                + ",\"completed\":" + completed
                + ",\"failed_query\":" + failedQuery
                + ",\"failed_timeout\":" + failedTimeout
                + ",\"hung\":" + hung
                + ",\"acquired\":" + pool.acquired.sum()
                + ",\"released\":" + pool.released.sum()
                + ",\"leaked\":" + pool.leaked()
                + ",\"pool_available_after\":" + pool.available()
                + ",\"pool_waiting_peak\":" + pool.waitingPeak.get()
                + ",\"pool_wait_ms_p99\":" + percentile(waits, 99)
                + ",\"pool_wait_ms_max\":" + (waits.length > 0 ? waits[waits.length - 1] : 0.0)
                + ",\"wall_ms\":" + wallMs
                + ",\"littles_law\":" + littlesLaw(requests, queryMs, wallMs)
                + ",\"note\":\"" + note + "\"}";
    }

    private static String littlesLaw(int requests, int queryMs, double wallMs) {
        if (wallMs <= 0) {
            return "{\"avg_throughput_rps\":0,\"avg_query_ms\":" + queryMs + ",\"recommended_pool_size\":1}";
        }
        double rps = requests / (wallMs / 1000.0);
        int recommended = Math.max(1, (int) Math.ceil(rps * (queryMs / 1000.0)) + 2);
        return "{\"avg_throughput_rps\":" + Math.round(rps * 100.0) / 100.0
                + ",\"avg_query_ms\":" + queryMs
                + ",\"recommended_pool_size\":" + recommended
                + ",\"formula\":\"ceil(throughput_rps * query_s) + 2 de buffer\"}";
    }

    private static double percentile(double[] sorted, int pct) {
        if (sorted.length == 0) return 0.0;
        int idx = (int) Math.ceil(pct / 100.0 * sorted.length) - 1;
        return sorted[Math.max(0, Math.min(sorted.length - 1, idx))];
    }

    private static String poolStateJson() {
        return "{\"initialized\":true,\"pool_size\":" + pool.size
                + ",\"available\":" + pool.available()
                + ",\"acquired_total\":" + pool.acquired.sum()
                + ",\"released_total\":" + pool.released.sum()
                + ",\"leaked\":" + pool.leaked()
                + ",\"waiting_now\":" + pool.waiting.get()
                + ",\"waiting_peak\":" + pool.waitingPeak.get()
                + ",\"acquire_timeout_ms\":" + ACQUIRE_TIMEOUT_MS
                + ",\"leaky_watchdog_ms\":" + LEAKY_WATCHDOG_MS + "}";
    }

    private static String variantJson(String name) {
        Slot s = metrics.get(name);
        double avg;
        double p99;
        synchronized (s.waitSamples) {
            double[] arr = s.waitSamples.stream().mapToDouble(Double::doubleValue).sorted().toArray();
            double sum = 0;
            for (double v : arr) sum += v;
            avg = arr.length == 0 ? 0.0 : Math.round(sum / arr.length * 100.0) / 100.0;
            p99 = percentile(arr, 99);
        }
        return "\"" + name + "\":{\"runs\":" + s.runs.sum()
                + ",\"completed\":" + s.completed.sum()
                + ",\"failed_query\":" + s.failedQuery.sum()
                + ",\"failed_timeout\":" + s.failedTimeout.sum()
                + ",\"hung\":" + s.hung.sum()
                + ",\"max_leaked\":" + s.maxLeaked.get()
                + ",\"avg_wait_ms\":" + avg
                + ",\"p99_wait_ms\":" + p99 + "}";
    }

    private static String diagnosticsJson() {
        return "{\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\",\"variants\":{"
                + variantJson("leaky") + "," + variantJson("managed") + "}"
                + ",\"pool\":" + poolStateJson()
                + ",\"interpretation\":{"
                + "\"leaky\":\"leaked > 0 y hung > 0: las conexiones perdidas en el camino de excepcion no vuelven, y lo que llega despues espera a algo que ya no existe.\","
                + "\"managed\":\"leaked = 0 siempre. Los fallos de query se siguen contando, pero la conexion vuelve al pool y el que no alcanza recibe un timeout rapido.\","
                + "\"java_note\":\"try-with-resources no depende de que el programador se acuerde: el compilador genera el finally para todos los caminos de salida.\"}}";
    }

    private static void route(HttpExchange ex) throws IOException {
        URI uri = ex.getRequestURI();
        String path = uri.getPath();
        Map<String, String> q = queryParams(uri.getRawQuery());
        int requests = clamp(parseInt(q.get("requests"), 24), 1, 200);
        int poolSize = clamp(parseInt(q.get("pool"), 4), 1, 64);
        int queryMs = clamp(parseInt(q.get("query_ms"), 25), 1, 500);
        int failRate = clamp(parseInt(q.get("fail_rate"), 25), 0, 100);

        int status = 200;
        String body;
        try {
            switch (path) {
                case "/":
                case "/index":
                    body = "{\"case\":\"" + CASE_NAME + "\",\"stack\":\"" + STACK
                            + "\",\"java_specific\":\"ArrayBlockingQueue como pool + Lease AutoCloseable con try-with-resources; poll(timeout) para el deadline.\""
                            + ",\"routes\":[\"/health\",\"/pool-leaky?requests=24&pool=4&query_ms=25&fail_rate=25\",\"/pool-managed?requests=24&pool=4&query_ms=25&fail_rate=25\",\"/pool/state\",\"/diagnostics/summary\",\"/reset-lab\"]}";
                    break;
                case "/health":
                    body = "{\"status\":\"ok\",\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\"}";
                    break;
                case "/pool-leaky":
                    body = runLoad("leaky", requests, poolSize, queryMs, failRate);
                    break;
                case "/pool-managed":
                    body = runLoad("managed", requests, poolSize, queryMs, failRate);
                    break;
                case "/pool/state":
                    body = poolStateJson();
                    break;
                case "/diagnostics/summary":
                    body = diagnosticsJson();
                    break;
                case "/reset-lab":
                    pool = new Pool(poolSize);
                    metrics.put("leaky", new Slot());
                    metrics.put("managed", new Slot());
                    body = "{\"status\":\"reset\",\"message\":\"Pool reconstruido y metricas reiniciadas.\"}";
                    break;
                default:
                    status = 404;
                    body = "{\"error\":\"Ruta no encontrada\",\"path\":\"" + escape(path) + "\"}";
            }
        } catch (Exception e) {
            status = 500;
            body = "{\"error\":\"internal\",\"detail\":\"" + escape(String.valueOf(e.getMessage())) + "\"}";
        }

        byte[] out = body.getBytes(StandardCharsets.UTF_8);
        ex.getResponseHeaders().add("Content-Type", "application/json; charset=utf-8");
        ex.sendResponseHeaders(status, out.length);
        try (OutputStream os = ex.getResponseBody()) {
            os.write(out);
        }
    }

    private static int parseInt(String raw, int fallback) {
        if (raw == null || raw.isBlank()) return fallback;
        try { return Integer.parseInt(raw); } catch (NumberFormatException e) { return fallback; }
    }

    private static int clamp(int v, int lo, int hi) { return Math.max(lo, Math.min(hi, v)); }

    private static String escape(String v) {
        return v == null ? "" : v.replace("\\", "\\\\").replace("\"", "\\\"");
    }

    private static Map<String, String> queryParams(String rawQuery) {
        Map<String, String> params = new HashMap<>();
        if (rawQuery == null || rawQuery.isBlank()) return params;
        for (String pair : rawQuery.split("&")) {
            String[] parts = pair.split("=", 2);
            params.put(URLDecoder.decode(parts[0], StandardCharsets.UTF_8),
                    parts.length > 1 ? URLDecoder.decode(parts[1], StandardCharsets.UTF_8) : "");
        }
        return params;
    }
}
