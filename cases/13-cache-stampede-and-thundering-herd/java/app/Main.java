import com.sun.net.httpserver.HttpExchange;
import com.sun.net.httpserver.HttpServer;

import java.io.IOException;
import java.io.OutputStream;
import java.net.InetSocketAddress;
import java.net.URI;
import java.net.URLDecoder;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.CyclicBarrier;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.ThreadLocalRandom;
import java.util.concurrent.atomic.AtomicInteger;
import java.util.concurrent.atomic.LongAdder;

/**
 * Caso 13 — Cache stampede (thundering herd) — stack Java 21.
 *
 * Naive: la clave expira y los N llamadores concurrentes recalculan el origen.
 * `origin_computations == concurrency`.
 * Single-flight: `origin_computations == 1` sin importar cuantos lleguen.
 *
 * Primitiva Java distintiva:
 *   `ConcurrentHashMap.computeIfAbsent(key, k -> CompletableFuture.supplyAsync(...))`.
 *
 *   Lo decisivo es que `computeIfAbsent` es ATOMICO por clave: el mapa mantiene
 *   el bin de esa clave bloqueado mientras corre la funcion de mapeo, asi que
 *   exactamente un hilo crea el Future y todos los demas reciben el MISMO
 *   objeto. No hay ventana entre "mirar si existe" y "crear": las dos cosas son
 *   una sola operacion. En Node hay que ordenar `Map.set` antes del `await`
 *   a mano; aca el contrato del mapa lo garantiza.
 *
 *   Sutileza que el codigo respeta: la funcion de mapeo NO debe bloquear, o el
 *   bin queda tomado mientras el origen trabaja. Por eso adentro solo se crea
 *   el `CompletableFuture` (barato) y el trabajo caro corre en el executor.
 *
 * El origen es CPU real (digest iterativo), no `Thread.sleep`. Un sleep no
 * modela lo que duele: que el origen HACE el trabajo N veces.
 */
public class Main {

    private static final String CASE_NAME = "13 - Cache stampede y thundering herd";
    private static final String STACK = System.getenv().getOrDefault("APP_STACK", "Java 21");
    private static final int PORT = Integer.parseInt(System.getenv().getOrDefault("PORT", "8080"));

    private static final long TTL_BASE_MS = 4000;
    private static final int JITTER_PCT = 25;
    private static final double SOFT_FRACTION = 0.6;

    private record Entry(String value, long computedAt, long softMs, long hardMs) {}

    private static final Map<String, Entry> cache = new ConcurrentHashMap<>();
    /**
     * El single-flight entero: un Future compartido por clave en vuelo.
     * El Boolean dice si ese vuelo REALMENTE tuvo que tocar el origen.
     */
    private static final ConcurrentHashMap<String, CompletableFuture<Boolean>> inflight = new ConcurrentHashMap<>();

    private static final ExecutorService originPool = Executors.newCachedThreadPool();
    private static final AtomicInteger originActive = new AtomicInteger();
    private static final AtomicInteger originPeak = new AtomicInteger();

    private static final Map<String, Slot> metrics = new ConcurrentHashMap<>();

    private static final class Slot {
        final LongAdder runs = new LongAdder();
        final LongAdder originComputations = new LongAdder();
        final LongAdder cacheHits = new LongAdder();
        final LongAdder coalescedWaiters = new LongAdder();
        final LongAdder servedStale = new LongAdder();
        final AtomicInteger maxStampedeDepth = new AtomicInteger();
        final List<Double> wallSamples = new ArrayList<>();
    }

    static {
        metrics.put("naive", new Slot());
        metrics.put("singleflight", new Slot());
    }

    public static void main(String[] args) throws Exception {
        HttpServer server = HttpServer.create(new InetSocketAddress(PORT), 0);
        server.createContext("/", Main::route);
        server.setExecutor(Executors.newCachedThreadPool());
        server.start();
        System.out.println("[case13-java] listening on " + PORT);
        Runtime.getRuntime().addShutdownHook(new Thread(() -> server.stop(0)));
    }

    // ------------------------------------------------------------------
    // Origen: trabajo real
    // ------------------------------------------------------------------

    private static String digestWork(String key, int rounds) {
        int h = 0;
        int salt = Math.max(1, key.length());
        long iterations = (long) rounds * 2000L;
        for (long i = 0; i < iterations; i++) {
            h = h * 31 + (int) (i ^ salt);
        }
        return String.format("%08x", h);
    }

    /** Recalculo instrumentado: registra el pico de llamadores coincidentes. */
    private static String computeOrigin(String key, int rounds) {
        int active = originActive.incrementAndGet();
        originPeak.accumulateAndGet(active, Math::max);
        try {
            String digest = digestWork(key, rounds);
            cacheStore(key, digest);
            return digest;
        } finally {
            originActive.decrementAndGet();
        }
    }

    private static void cacheStore(String key, String value) {
        int spread = (int) (TTL_BASE_MS * JITTER_PCT / 100);
        long jitter = ThreadLocalRandom.current().nextInt(-spread, spread + 1);
        long hard = TTL_BASE_MS + jitter;
        cache.put(key, new Entry(value, System.nanoTime() / 1_000_000L, (long) (hard * SOFT_FRACTION), hard));
    }

    /** fresh | stale | miss */
    private static String cacheState(String key) {
        Entry e = cache.get(key);
        if (e == null) return "miss";
        long age = System.nanoTime() / 1_000_000L - e.computedAt();
        if (age <= e.softMs()) return "fresh";
        if (age <= e.hardMs()) return "stale";
        return "miss";
    }

    // ------------------------------------------------------------------
    // Los dos llamadores
    // ------------------------------------------------------------------

    private record Outcome(double waitMs, boolean computed, boolean stale, boolean waited) {}

    private static Outcome callerNaive(String key, int rounds, CyclicBarrier gate) {
        awaitGate(gate);
        long t0 = System.nanoTime();
        String state = cacheState(key);
        // Segunda fase: los N ya leyeron la cache antes de que ninguno escriba.
        awaitGate(gate);
        if ("fresh".equals(state)) {
            return new Outcome(ms(t0), false, false, false);
        }
        computeOrigin(key, rounds);
        return new Outcome(ms(t0), true, false, false);
    }

    private static Outcome callerSingleflight(String key, int rounds, CyclicBarrier gate) {
        awaitGate(gate);
        long t0 = System.nanoTime();
        String state = cacheState(key);
        awaitGate(gate);
        if ("fresh".equals(state)) {
            return new Outcome(ms(t0), false, false, false);
        }

        boolean[] leader = {false};
        // computeIfAbsent atomico: exactamente un hilo crea el Future. La lambda
        // NO bloquea — solo arranca el trabajo en otro executor y devuelve.
        CompletableFuture<Boolean> flight = inflight.computeIfAbsent(key, k -> {
            leader[0] = true;
            return CompletableFuture.supplyAsync(() -> {
                // Double check dentro del vuelo. Sin esto el patron funciona
                // pero no alcanza: el lider de la primera generacion termina,
                // el whenComplete borra su entrada, y los llamadores que
                // todavia no habian llegado al computeIfAbsent se vuelven
                // lideres de una segunda generacion. Con `cost` chico eso da
                // 3 o 4 recalculos en vez de 1 — falta este `if`, no el patron.
                if ("fresh".equals(cacheState(k))) return false;
                computeOrigin(k, rounds);
                return true;
            }, originPool).whenComplete((v, err) -> inflight.remove(k));
        });

        if (leader[0]) {
            boolean didCompute = Boolean.TRUE.equals(flight.join());
            return new Outcome(ms(t0), didCompute, false, !didCompute);
        }
        if ("stale".equals(state)) {
            // Soft TTL vencida: se sirve el valor viejo sin esperar el refresh.
            return new Outcome(ms(t0), false, true, false);
        }
        flight.join();
        return new Outcome(ms(t0), false, false, true);
    }

    private static void awaitGate(CyclicBarrier gate) {
        try {
            gate.await();
        } catch (Exception ignored) {
            Thread.currentThread().interrupt();
        }
    }

    private static double ms(long t0) {
        return Math.round((System.nanoTime() - t0) / 10_000.0) / 100.0;
    }

    // ------------------------------------------------------------------
    // Orquestacion de la rafaga
    // ------------------------------------------------------------------

    private static String runBurst(String variant, String key, int concurrency, int rounds) {
        originPeak.set(0);
        CyclicBarrier gate = new CyclicBarrier(concurrency);
        ExecutorService pool = Executors.newFixedThreadPool(concurrency);
        List<CompletableFuture<Outcome>> futures = new ArrayList<>(concurrency);
        long t0 = System.nanoTime();
        for (int i = 0; i < concurrency; i++) {
            futures.add(CompletableFuture.supplyAsync(
                    () -> variant.equals("naive")
                            ? callerNaive(key, rounds, gate)
                            : callerSingleflight(key, rounds, gate),
                    pool));
        }
        List<Outcome> results = new ArrayList<>(concurrency);
        for (CompletableFuture<Outcome> f : futures) results.add(f.join());
        double wallMs = ms(t0);
        pool.shutdown();

        long computations = results.stream().filter(Outcome::computed).count();
        long stale = results.stream().filter(Outcome::stale).count();
        long waiters = results.stream().filter(Outcome::waited).count();
        long hits = results.size() - computations - stale - waiters;
        double[] waits = results.stream().mapToDouble(Outcome::waitMs).sorted().toArray();
        int depth = originPeak.get();

        Slot s = metrics.get(variant);
        s.runs.increment();
        s.originComputations.add(computations);
        s.cacheHits.add(hits);
        s.coalescedWaiters.add(waiters);
        s.servedStale.add(stale);
        s.maxStampedeDepth.accumulateAndGet(depth, Math::max);
        synchronized (s.wallSamples) {
            s.wallSamples.add(wallMs);
            while (s.wallSamples.size() > 200) s.wallSamples.remove(0);
        }

        Entry current = cache.get(key);
        return "{\"variant\":\"" + variant + "\",\"key\":\"" + escape(key) + "\""
                + ",\"concurrency\":" + concurrency
                + ",\"cost_rounds\":" + rounds
                + ",\"origin_computations\":" + computations
                + ",\"cache_hits\":" + hits
                + ",\"coalesced_waiters\":" + waiters
                + ",\"served_stale\":" + stale
                + ",\"stampede_depth\":" + depth
                + ",\"wall_ms\":" + wallMs
                + ",\"p99_wait_ms\":" + percentile(waits, 99)
                + ",\"max_wait_ms\":" + (waits.length > 0 ? waits[waits.length - 1] : 0.0)
                + ",\"value_digest\":\"" + (current == null ? "" : current.value()) + "\""
                + ",\"ttl_base_ms\":" + TTL_BASE_MS
                + ",\"jitter_pct\":" + JITTER_PCT
                + ",\"note\":\"" + (variant.equals("naive")
                        ? "Sin coordinacion: cada llamador que vio el miss recalcula. El origen recibe la rafaga entera."
                        : "computeIfAbsent atomico + CompletableFuture compartido: un solo recalculo por expiracion.")
                + "\"}";
    }

    private static double percentile(double[] sorted, int pct) {
        if (sorted.length == 0) return 0.0;
        int idx = (int) Math.ceil(pct / 100.0 * sorted.length) - 1;
        idx = Math.max(0, Math.min(sorted.length - 1, idx));
        return sorted[idx];
    }

    // ------------------------------------------------------------------
    // Rutas
    // ------------------------------------------------------------------

    private static String cacheStateJson() {
        StringBuilder sb = new StringBuilder(512);
        sb.append("{\"entries\":{");
        boolean first = true;
        long now = System.nanoTime() / 1_000_000L;
        for (Map.Entry<String, Entry> me : cache.entrySet()) {
            if (!first) sb.append(',');
            Entry e = me.getValue();
            long age = now - e.computedAt();
            sb.append('"').append(escape(me.getKey())).append("\":{")
              .append("\"age_ms\":").append(age)
              .append(",\"soft_ttl_ms\":").append(e.softMs())
              .append(",\"hard_ttl_ms\":").append(e.hardMs())
              .append(",\"soft_expired\":").append(age > e.softMs())
              .append(",\"hard_expired\":").append(age > e.hardMs())
              .append(",\"value_digest\":\"").append(e.value()).append("\"}");
            first = false;
        }
        sb.append("},\"ttl_base_ms\":").append(TTL_BASE_MS)
          .append(",\"jitter_pct\":").append(JITTER_PCT)
          .append(",\"soft_fraction\":").append(SOFT_FRACTION)
          .append(",\"inflight_keys\":[");
        first = true;
        for (String k : inflight.keySet()) {
            if (!first) sb.append(',');
            sb.append('"').append(escape(k)).append('"');
            first = false;
        }
        sb.append("]}");
        return sb.toString();
    }

    private static String variantJson(String name) {
        Slot s = metrics.get(name);
        double avg;
        double p99;
        synchronized (s.wallSamples) {
            double[] arr = s.wallSamples.stream().mapToDouble(Double::doubleValue).sorted().toArray();
            avg = arr.length == 0 ? 0.0
                    : Math.round(Arrays.stream(arr).sum() / arr.length * 100.0) / 100.0;
            p99 = percentile(arr, 99);
        }
        return "\"" + name + "\":{\"runs\":" + s.runs.sum()
                + ",\"origin_computations\":" + s.originComputations.sum()
                + ",\"cache_hits\":" + s.cacheHits.sum()
                + ",\"coalesced_waiters\":" + s.coalescedWaiters.sum()
                + ",\"served_stale\":" + s.servedStale.sum()
                + ",\"max_stampede_depth\":" + s.maxStampedeDepth.get()
                + ",\"avg_wall_ms\":" + avg
                + ",\"p99_wall_ms\":" + p99 + "}";
    }

    private static String diagnosticsJson() {
        return "{\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\",\"variants\":{"
                + variantJson("naive") + "," + variantJson("singleflight") + "}"
                + ",\"origin_total_computations\":"
                + (metrics.get("naive").originComputations.sum() + metrics.get("singleflight").originComputations.sum())
                + ",\"interpretation\":{"
                + "\"naive\":\"origin_computations crece linealmente con la concurrencia: el origen ve la rafaga completa.\","
                + "\"singleflight\":\"origin_computations se mantiene en 1 por expiracion, sin importar cuantos llamadores lleguen.\","
                + "\"java_note\":\"computeIfAbsent es atomico por clave: no existe la ventana check-then-act que hay que ordenar a mano en otros stacks.\"}}";
    }

    private static void route(HttpExchange ex) throws IOException {
        URI uri = ex.getRequestURI();
        String path = uri.getPath();
        Map<String, String> q = queryParams(uri.getRawQuery());
        String key = q.getOrDefault("key", "report-alpha");
        if (key.length() > 60) key = key.substring(0, 60);
        int concurrency = clamp(parseInt(q.get("concurrency"), 16), 1, 128);
        int rounds = clamp(parseInt(q.get("cost"), 40), 1, 400);

        int status = 200;
        String body;
        try {
            switch (path) {
                case "/":
                case "/index":
                    body = "{\"case\":\"" + CASE_NAME + "\",\"stack\":\"" + STACK
                            + "\",\"java_specific\":\"ConcurrentHashMap.computeIfAbsent + CompletableFuture compartido; atomicidad por clave sin lock explicito.\""
                            + ",\"routes\":[\"/health\",\"/cache-naive?key=report-alpha&concurrency=16&cost=40\",\"/cache-singleflight?key=report-alpha&concurrency=16&cost=40\",\"/cache/state\",\"/diagnostics/summary\",\"/reset-lab\"]}";
                    break;
                case "/health":
                    body = "{\"status\":\"ok\",\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\"}";
                    break;
                case "/cache-naive":
                    body = runBurst("naive", key, concurrency, rounds);
                    break;
                case "/cache-singleflight":
                    body = runBurst("singleflight", key, concurrency, rounds);
                    break;
                case "/cache/state":
                    body = cacheStateJson();
                    break;
                case "/diagnostics/summary":
                    body = diagnosticsJson();
                    break;
                case "/reset-lab":
                    cache.clear();
                    inflight.clear();
                    metrics.put("naive", new Slot());
                    metrics.put("singleflight", new Slot());
                    originPeak.set(0);
                    body = "{\"status\":\"reset\",\"message\":\"Cache y metricas reiniciadas.\"}";
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
        try {
            return Integer.parseInt(raw);
        } catch (NumberFormatException e) {
            return fallback;
        }
    }

    private static int clamp(int v, int lo, int hi) {
        return Math.max(lo, Math.min(hi, v));
    }

    private static String escape(String v) {
        return v == null ? "" : v.replace("\\", "\\\\").replace("\"", "\\\"");
    }

    private static Map<String, String> queryParams(String rawQuery) {
        Map<String, String> params = new HashMap<>();
        if (rawQuery == null || rawQuery.isBlank()) return params;
        for (String pair : rawQuery.split("&")) {
            String[] parts = pair.split("=", 2);
            String k = URLDecoder.decode(parts[0], StandardCharsets.UTF_8);
            String v = parts.length > 1 ? URLDecoder.decode(parts[1], StandardCharsets.UTF_8) : "";
            params.put(k, v);
        }
        return params;
    }
}
