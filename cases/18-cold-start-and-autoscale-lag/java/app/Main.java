// Caso 18 — Arranque en frio y retraso del autoescalado — stack Java 21.
//
// Frio: el autoescalador levanta instancias cuando el trafico ya subio. El
// proceso queda vivo al instante y /health responde 200 — pero la instancia no
// sirve nada hasta terminar de inicializar. El balanceador que mira liveness en
// vez de readiness manda trafico a ese hueco. Ahi nacen los 503.
//
// Templado: pool tibio ya inicializado y ya ejercitado, y balanceador que
// enruta por /ready.
//
// Que es real y que esta modelado:
//
//   La curva de calentamiento se MIDE, no se simula. El trabajo por peticion es
//   un lazo entero puro, identico en los siete stacks, sin sleep de ninguna
//   clase. En Java la curva es la mas pronunciada de los siete — y no hace
//   falta ayudarla: el efecto esta ahi.
//
//   La parte de I/O de la inicializacion (abrir el pool, DNS, TLS) es un sleep
//   de io_ms: esperar a la red no quema CPU, y fijarlo hace comparables a los
//   siete stacks. La parte de CPU —construir la tabla— es trabajo real.
//
// Primitiva Java distintiva — y aqui el stack es el problema, no la solucion:
//
//   La JVM compila EN CAPAS. El bytecode arranca interpretado; a los ~200
//   llamados C1 lo compila rapido y sin optimizar; a los ~10.000 C2 lo
//   reoptimiza con el perfil recolectado. El mismo metodo, sin tocar una linea,
//   corre varias veces mas rapido a la peticion 10.000 que a la primera.
//
//   Eso convierte a Java en el caso canonico de cold start: la instancia que el
//   autoescalador acaba de levantar no solo tarda en estar lista, sino que
//   ademas atiende LENTO las primeras miles de peticiones. Con trafico ya
//   encima, esa lentitud vuelve a disparar el autoescalador.
//
//   La contraparte es que Java tiene la caja de herramientas mas profunda
//   contra su propio problema: AppCDS para saltarse el classloading,
//   -XX:TieredStopAtLevel=1 para llegar rapido a C1 y quedarse ahi, y
//   GraalVM native-image para compilar AOT y eliminar la curva entera.
//   Ninguna viene puesta por defecto, y esa es la queja legitima.

import com.sun.net.httpserver.HttpExchange;
import com.sun.net.httpserver.HttpServer;

import java.io.OutputStream;
import java.net.InetSocketAddress;
import java.nio.charset.StandardCharsets;
import java.time.Instant;
import java.time.temporal.ChronoUnit;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.Executors;
import java.util.concurrent.atomic.AtomicBoolean;
import java.util.concurrent.atomic.AtomicInteger;
import java.util.concurrent.atomic.AtomicLong;

public class Main {

    static final String APP_STACK = System.getenv().getOrDefault("APP_STACK", "Java 21");
    static final String CASE_NAME = "18 - Arranque en frio y retraso del autoescalado";

    static final int WORK_ITERS = 250_000;      // calibrado para ~0.3 ms caliente
    static final int INIT_TABLE_ROWS = 2_000_000; // parte de CPU de la init: trabajo real

    static final long START = System.nanoTime();

    static double nowMs() {
        return (System.nanoTime() - START) / 1_000_000.0;
    }

    /**
     * Trabajo por peticion: lazo entero puro, sin sleep, sin I/O.
     * Identico en los siete stacks. Lo que cambia es lo que el runtime hace con
     * el mismo codigo repetido mil veces — que es lo que este caso mide.
     */
    static int work(int iters) {
        int h = (int) 2166136261L;
        for (int i = 0; i < iters; i++) {
            h = (h ^ i) * 16777619;
        }
        return h;
    }

    /** Una instancia del servicio. Vive apenas arranca; esta lista mucho despues. */
    static final class Instance {
        final String id;
        final AtomicBoolean live = new AtomicBoolean(true); // /health responde 200 YA
        final AtomicBoolean ready = new AtomicBoolean(false);
        final double liveAt = nowMs();
        volatile double readyAt = -1;
        final AtomicLong served = new AtomicLong();
        int[] table;

        Instance(String id) {
            this.id = id;
        }

        void boot(int ioMs) {
            // Parte de CPU: construir la tabla de configuracion. Trabajo de verdad,
            // y en Java es ademas donde el classloader hace su parte.
            int[] t = new int[256];
            int h = (int) 2166136261L;
            for (int i = 0; i < INIT_TABLE_ROWS; i++) {
                h = (h ^ i) * 16777619;
                t[h & 0xFF] = h;
            }
            // Parte de I/O: abrir el pool, resolver DNS, negociar TLS.
            try {
                Thread.sleep(ioMs);
            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
            }
            this.table = t;
            this.readyAt = nowMs();
            this.ready.set(true);
        }

        double gapMs() {
            double end = readyAt < 0 ? nowMs() : readyAt;
            return round(end - liveAt, 2);
        }
    }

    static final class Slot {
        int runs;
        long served;
        long rejectedColdStart;
        int coldStarts;
        double maxReadyAtMs;
    }

    static final Object LOCK = new Object();
    static List<Instance> fleet = new ArrayList<>();
    static List<Instance> warmPool = new ArrayList<>();
    static Map<String, Slot> metrics = newMetrics();

    static Map<String, Slot> newMetrics() {
        Map<String, Slot> m = new ConcurrentHashMap<>();
        m.put("cold", new Slot());
        m.put("warmed", new Slot());
        return m;
    }

    static double round(double v, int d) {
        double f = Math.pow(10, d);
        return Math.round(v * f) / f;
    }

    static double percentile(List<Double> values, double pct) {
        if (values.isEmpty()) return 0;
        double[] sv = values.stream().mapToDouble(Double::doubleValue).toArray();
        Arrays.sort(sv);
        int idx = (int) Math.ceil(pct / 100 * sv.length) - 1;
        idx = Math.max(0, Math.min(sv.length - 1, idx));
        return round(sv[idx], 3);
    }

    // -----------------------------------------------------------------------
    // El pool tibio: instancias ya inicializadas Y ya ejercitadas
    // -----------------------------------------------------------------------

    static Map<String, Object> buildWarmPool(int instances, int ioMs, int prime, int iters) {
        double t0 = nowMs();
        List<Instance> pool = new ArrayList<>();
        List<Thread> boots = new ArrayList<>();
        for (int i = 0; i < instances; i++) {
            Instance in = new Instance("warm-" + i);
            pool.add(in);
            Thread t = new Thread(() -> in.boot(ioMs));
            t.start();
            boots.add(t);
        }
        join(boots);
        double initMs = nowMs() - t0;

        // Ejercitar. En Java esta mitad es la que MAS importa: es lo que empuja
        // los metodos calientes de interpretado a C1 y de C1 a C2.
        int sink = 0;
        for (int i = 0; i < prime; i++) sink ^= work(iters);
        if (sink == 42) System.out.print("");   // impide que el JIT elimine el lazo
        for (Instance in : pool) in.served.addAndGet(prime / Math.max(1, instances));

        synchronized (LOCK) {
            warmPool = pool;
        }
        Map<String, Object> out = new LinkedHashMap<>();
        out.put("warm_pool_size", pool.size());
        out.put("init_ms", round(initMs, 2));
        out.put("prime_requests", prime);
        out.put("warmup_duration_ms", round(nowMs() - t0, 2));
        return out;
    }

    static void join(List<Thread> ts) {
        for (Thread t : ts) {
            try {
                t.join();
            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
            }
        }
    }

    // -----------------------------------------------------------------------
    // El balanceador: la diferencia entre mirar /health y mirar /ready
    // -----------------------------------------------------------------------

    static Instance pick(List<Instance> pool, boolean byReadiness, int counter) {
        int n = pool.size();
        for (int k = 0; k < n; k++) {
            Instance in = pool.get(Math.floorMod(counter + k, n));
            if (byReadiness ? in.ready.get() : in.live.get()) return in;
        }
        return null;
    }

    static Map<String, Object> runScenario(String variant, int requests, int instances, int clients,
                                           int ioMs, int paceMs, int iters, int prime) {
        Map<String, Object> warmInfo = null;
        boolean byReadiness;
        int coldStarts;
        List<Thread> boots = new ArrayList<>();
        List<Instance> local;

        if ("cold".equals(variant)) {
            // El autoescalador reacciona tarde: las instancias arrancan CON el
            // trafico encima, no antes.
            local = new ArrayList<>();
            for (int i = 0; i < instances; i++) {
                Instance in = new Instance("cold-" + i);
                local.add(in);
                Thread t = new Thread(() -> in.boot(ioMs));
                t.start();
                boots.add(t);
            }
            byReadiness = false;   // el balanceador ingenuo mira /health
            coldStarts = instances;
        } else {
            boolean havePool;
            synchronized (LOCK) {
                havePool = warmPool.size() >= instances;
            }
            if (!havePool) warmInfo = buildWarmPool(instances, ioMs, prime, iters);
            synchronized (LOCK) {
                local = new ArrayList<>(warmPool.subList(0, instances));
            }
            byReadiness = true;    // el balanceador correcto mira /ready
            coldStarts = 0;
        }

        synchronized (LOCK) {
            fleet = local;
        }

        List<Double> ordered = java.util.Collections.synchronizedList(new ArrayList<>(requests));
        AtomicLong served = new AtomicLong();
        AtomicLong rejected = new AtomicLong();
        CountDownLatch gate = new CountDownLatch(clients);
        List<Thread> workers = new ArrayList<>();
        final boolean routeByReadiness = byReadiness;

        double t0 = nowMs();
        for (int c = 0; c < clients; c++) {
            final int idx = c;
            Thread t = new Thread(() -> {
                gate.countDown();
                try {
                    gate.await();   // largada comun
                } catch (InterruptedException e) {
                    Thread.currentThread().interrupt();
                }
                int mine = requests / clients + (idx < requests % clients ? 1 : 0);
                for (int k = 0; k < mine; k++) {
                    Instance in = pick(local, routeByReadiness, idx + k);
                    double st = nowMs();
                    if (in == null || !in.ready.get()) {
                        // El proceso esta vivo, el healthcheck da verde, y la
                        // peticion se cae igual. Nada dispara una alerta.
                        rejected.incrementAndGet();
                    } else {
                        work(iters);
                        in.served.incrementAndGet();
                        ordered.add(nowMs() - st);
                        served.incrementAndGet();
                    }
                    if (paceMs > 0) {
                        try {
                            Thread.sleep(paceMs);
                        } catch (InterruptedException e) {
                            Thread.currentThread().interrupt();
                        }
                    }
                }
            });
            t.start();
            workers.add(t);
        }
        join(workers);
        join(boots);
        double wall = nowMs() - t0;

        List<Double> snapshot;
        synchronized (ordered) {
            snapshot = new ArrayList<>(ordered);
        }
        List<Double> first100 = snapshot.subList(0, Math.min(100, snapshot.size()));
        List<Double> after1000;
        if (snapshot.size() > 1000) after1000 = snapshot.subList(1000, snapshot.size());
        else if (snapshot.size() > 100) after1000 = snapshot.subList(snapshot.size() - 100, snapshot.size());
        else after1000 = snapshot;

        double p99First = percentile(first100, 99);
        double p99After = percentile(after1000, 99);
        double readyAt = 0;
        for (Instance in : local) readyAt = Math.max(readyAt, in.gapMs());

        int warmSize;
        synchronized (LOCK) {
            Slot s = metrics.get(variant);
            s.runs++;
            s.served += served.get();
            s.rejectedColdStart += rejected.get();
            s.coldStarts += coldStarts;
            s.maxReadyAtMs = Math.max(s.maxReadyAtMs, readyAt);
            warmSize = warmPool.size();
        }

        Map<String, Object> out = new LinkedHashMap<>();
        out.put("variant", variant);
        out.put("instances", instances);
        out.put("requests", requests);
        out.put("clients", clients);
        out.put("lb_routes_by", byReadiness ? "readiness (/ready)" : "liveness (/health)");
        out.put("cold_start_count", coldStarts);
        out.put("warm_pool_size", warmSize);
        out.put("ready_at_ms", round(readyAt, 2));
        out.put("health_vs_ready_gap_ms", coldStarts > 0 ? round(readyAt, 2) : 0.0);
        out.put("first_response_ms", snapshot.isEmpty() ? 0.0 : round(snapshot.get(0), 3));
        out.put("p99_first_100_ms", p99First);
        out.put("p99_after_1000_ms", p99After);
        out.put("warmup_speedup_x", p99After > 0 ? round(p99First / p99After, 2) : 1.0);
        out.put("p50_ms", percentile(snapshot, 50));
        out.put("served", served.get());
        out.put("rejected_cold_start", rejected.get());
        out.put("availability_pct", round(served.get() * 100.0 / Math.max(1, served.get() + rejected.get()), 2));
        out.put("work_iters", iters);
        out.put("io_ms", ioMs);
        out.put("pace_ms", paceMs);
        out.put("wall_ms", round(wall, 2));
        if (warmInfo != null) out.put("warm_pool_built_now", warmInfo);
        out.put("note", "cold".equals(variant)
                ? "El proceso esta vivo desde el milisegundo cero y /health lo confirma, pero la instancia no sirve "
                + "nada hasta terminar de inicializar. El balanceador que enruta por liveness manda trafico a ese "
                + "hueco: los 503 salen de una instancia que ninguna alerta considera caida."
                : "El pool ya estaba inicializado y ya ejercitado, y el balanceador enruta por readiness. Ninguna "
                + "peticion cae en una instancia a medio levantar: 0 rechazos y la latencia parte donde la otra "
                + "variante recien termina.");
        out.put("java_note", "La JVM compila en capas: interpretado, luego C1, luego C2 con el perfil recolectado. "
                + "warmup_speedup_x mide ese efecto sin simularlo, y en Java sale el mas alto de los siete. Es el "
                + "caso canonico de cold start — y tambien el stack con mas herramientas contra su propio problema: "
                + "AppCDS, -XX:TieredStopAtLevel=1 y GraalVM native-image.");
        return out;
    }

    static Map<String, Object> readyState() {
        List<Instance> local;
        int warmSize;
        synchronized (LOCK) {
            local = new ArrayList<>(fleet);
            warmSize = warmPool.size();
        }
        List<Map<String, Object>> items = new ArrayList<>();
        boolean allReady = !local.isEmpty();
        for (Instance in : local) {
            boolean r = in.ready.get();
            if (!r) allReady = false;
            Map<String, Object> m = new LinkedHashMap<>();
            m.put("id", in.id);
            m.put("live", in.live.get());
            m.put("ready", r);
            m.put("ready_at_ms", in.gapMs());
            m.put("requests_served", in.served.get());
            items.add(m);
        }
        Map<String, Object> out = new LinkedHashMap<>();
        out.put("ready", allReady);
        out.put("instances", items);
        out.put("warm_pool_size", warmSize);
        out.put("note", "`/health` responde 200 apenas el proceso arranca. `/ready` responde 200 recien cuando la "
                + "instancia puede servir. Si el balanceador mira la primera en vez de la segunda, el hueco entre "
                + "las dos es tiempo de caida que nadie registra como caida.");
        return out;
    }

    static Map<String, Object> diagnostics() {
        Map<String, Object> variants = new LinkedHashMap<>();
        synchronized (LOCK) {
            for (String name : new String[]{"cold", "warmed"}) {
                Slot s = metrics.get(name);
                Map<String, Object> m = new LinkedHashMap<>();
                m.put("runs", s.runs);
                m.put("served", s.served);
                m.put("rejected_cold_start", s.rejectedColdStart);
                m.put("cold_starts", s.coldStarts);
                m.put("max_ready_at_ms", round(s.maxReadyAtMs, 2));
                variants.put(name, m);
            }
        }
        Map<String, Object> fidelity = new LinkedHashMap<>();
        fidelity.put("medido", "La curva de calentamiento. El trabajo por peticion es un lazo entero puro sin sleep, "
                + "identico en los 7 stacks; p99_first_100_ms vs p99_after_1000_ms es lo que la JVM hace de verdad.");
        fidelity.put("modelado", "La parte de I/O de la inicializacion (abrir pool, DNS, TLS) es un sleep de io_ms: "
                + "esperar a la red no quema CPU, y fijarlo es lo que hace comparables a los 7 stacks.");
        fidelity.put("real", "La parte de CPU de la inicializacion recorre 2.000.000 de iteraciones, con el "
                + "classloader haciendo su parte por debajo.");

        Map<String, Object> interpretation = new LinkedHashMap<>();
        interpretation.put("cold", "rejected_cold_start > 0 con el proceso vivo todo el tiempo. "
                + "health_vs_ready_gap_ms es la ventana exacta en la que el balanceador mando trafico a una "
                + "instancia que no podia servirlo.");
        interpretation.put("warmed", "rejected_cold_start = 0. El pool ya estaba, y el balanceador enruta por readiness.");
        interpretation.put("java_note", "En Java el cold start no termina cuando la instancia queda lista: sigue "
                + "durante miles de peticiones mientras C2 recompila. Con trafico encima, esa lentitud vuelve a "
                + "disparar el autoescalador.");

        Map<String, Object> out = new LinkedHashMap<>();
        out.put("stack", APP_STACK);
        out.put("case", CASE_NAME);
        out.put("variants", variants);
        out.put("fleet", readyState());
        out.put("fidelity", fidelity);
        out.put("interpretation", interpretation);
        return out;
    }

    // -----------------------------------------------------------------------
    // HTTP
    // -----------------------------------------------------------------------

    static int clampInt(int v, int lo, int hi) {
        return Math.max(lo, Math.min(hi, v));
    }

    static int queryInt(Map<String, String> q, String key, int def) {
        String raw = q.get(key);
        if (raw == null) return def;
        try {
            return Integer.parseInt(raw);
        } catch (NumberFormatException e) {
            return def;
        }
    }

    static Map<String, String> parseQuery(String raw) {
        Map<String, String> out = new LinkedHashMap<>();
        if (raw == null || raw.isEmpty()) return out;
        for (String pair : raw.split("&")) {
            int i = pair.indexOf('=');
            if (i > 0) out.put(pair.substring(0, i), pair.substring(i + 1));
        }
        return out;
    }

    @SuppressWarnings("unchecked")
    static String toJson(Object v, int indent) {
        String pad = "  ".repeat(indent);
        String padIn = "  ".repeat(indent + 1);
        if (v == null) return "null";
        if (v instanceof Map<?, ?> m) {
            if (m.isEmpty()) return "{}";
            StringBuilder sb = new StringBuilder("{\n");
            int i = 0;
            for (Map.Entry<?, ?> e : m.entrySet()) {
                sb.append(padIn).append(quote(String.valueOf(e.getKey()))).append(": ")
                  .append(toJson(e.getValue(), indent + 1));
                if (++i < m.size()) sb.append(',');
                sb.append('\n');
            }
            return sb.append(pad).append('}').toString();
        }
        if (v instanceof List<?> l) {
            if (l.isEmpty()) return "[]";
            StringBuilder sb = new StringBuilder("[\n");
            for (int i = 0; i < l.size(); i++) {
                sb.append(padIn).append(toJson(l.get(i), indent + 1));
                if (i < l.size() - 1) sb.append(',');
                sb.append('\n');
            }
            return sb.append(pad).append(']').toString();
        }
        if (v instanceof Number || v instanceof Boolean) return String.valueOf(v);
        return quote(String.valueOf(v));
    }

    static String quote(String s) {
        StringBuilder sb = new StringBuilder("\"");
        for (char c : s.toCharArray()) {
            switch (c) {
                case '"' -> sb.append("\\\"");
                case '\\' -> sb.append("\\\\");
                case '\n' -> sb.append("\\n");
                case '\r' -> sb.append("\\r");
                case '\t' -> sb.append("\\t");
                default -> sb.append(c);
            }
        }
        return sb.append('"').toString();
    }

    public static void main(String[] args) throws Exception {
        int port = Integer.parseInt(System.getenv().getOrDefault("PORT", "8080"));
        HttpServer server = HttpServer.create(new InetSocketAddress("0.0.0.0", port), 0);
        server.setExecutor(Executors.newCachedThreadPool());
        server.createContext("/", Main::handle);
        System.out.println("Servidor Java escuchando en " + port);
        server.start();
    }

    static void handle(HttpExchange ex) throws java.io.IOException {
        String uri = ex.getRequestURI().getPath();
        Map<String, String> q = parseQuery(ex.getRequestURI().getRawQuery());

        int requests = clampInt(queryInt(q, "requests", 2400), 100, 20000);
        int instances = clampInt(queryInt(q, "instances", 3), 1, 32);
        int clients = clampInt(queryInt(q, "clients", 8), 1, 64);
        int ioMs = clampInt(queryInt(q, "io_ms", 150), 0, 5000);
        int paceMs = clampInt(queryInt(q, "pace_ms", 1), 0, 100);
        int iters = clampInt(queryInt(q, "work_iters", WORK_ITERS), 100, 5_000_000);
        int prime = clampInt(queryInt(q, "prime", 1500), 0, 100_000);

        int status = 200;
        Map<String, Object> payload;

        switch (uri) {
            case "/", "/index" -> {
                Map<String, String> routes = new LinkedHashMap<>();
                routes.put("/health", "Liveness: responde 200 apenas el proceso arranca.");
                routes.put("/ready", "Readiness: responde 200 recien cuando la instancia puede servir.");
                routes.put("/boot-cold?requests=2400&instances=3", "Instancias frias con el trafico ya encima.");
                routes.put("/boot-warmed?requests=2400&instances=3", "Pool tibio y balanceador que mira readiness.");
                routes.put("/warmup?instances=3&prime=1500", "Construye el pool tibio antes de que llegue el trafico.");
                routes.put("/diagnostics/summary", "Comparativa entre variantes.");
                routes.put("/reset-lab", "Vacia la flota, el pool tibio y las metricas.");
                payload = new LinkedHashMap<>();
                payload.put("lab", "Problem-Driven Systems Lab");
                payload.put("case", CASE_NAME);
                payload.put("stack", APP_STACK);
                payload.put("goal", "Mostrar que el hueco entre 'el proceso esta vivo' y 'la instancia puede servir' "
                        + "es tiempo de caida real que ningun healthcheck registra como caida.");
                payload.put("java_specific", "La JVM compila en capas: el mismo metodo se vuelve mas rapido solo por "
                        + "repetirse. Es el caso canonico de cold start, y el stack con mas herramientas contra el.");
                payload.put("routes", routes);
            }
            case "/health" -> {
                payload = new LinkedHashMap<>();
                payload.put("status", "ok");
                payload.put("stack", APP_STACK);
                payload.put("case", CASE_NAME);
                payload.put("note", "Liveness. Esto responde 200 aunque la instancia no pueda servir una sola peticion.");
            }
            case "/ready" -> payload = readyState();
            case "/boot-cold" -> payload = runScenario("cold", requests, instances, clients, ioMs, paceMs, iters, prime);
            case "/boot-warmed" -> payload = runScenario("warmed", requests, instances, clients, ioMs, paceMs, iters, prime);
            case "/warmup" -> {
                payload = buildWarmPool(instances, ioMs, prime, iters);
                payload.put("status", "warm");
                payload.put("note", "Inicializar deja la instancia lista. Ejercitarla deja al runtime listo. Las dos "
                        + "mitades hacen falta, y solo la segunda depende del lenguaje.");
            }
            case "/diagnostics/summary" -> payload = diagnostics();
            case "/reset-lab" -> {
                synchronized (LOCK) {
                    fleet = new ArrayList<>();
                    warmPool = new ArrayList<>();
                    metrics = newMetrics();
                }
                payload = new LinkedHashMap<>();
                payload.put("status", "reset");
                payload.put("message", "Flota, pool tibio y metricas reiniciados.");
            }
            default -> {
                status = 404;
                payload = new LinkedHashMap<>();
                payload.put("error", "Ruta no encontrada");
                payload.put("path", uri);
            }
        }

        payload.put("timestamp_utc", Instant.now().truncatedTo(ChronoUnit.SECONDS).toString());
        payload.put("pid", ProcessHandle.current().pid());

        byte[] body = toJson(payload, 0).getBytes(StandardCharsets.UTF_8);
        ex.getResponseHeaders().set("Content-Type", "application/json; charset=utf-8");
        ex.sendResponseHeaders(status, body.length);
        try (OutputStream os = ex.getResponseBody()) {
            os.write(body);
        }
    }
}
