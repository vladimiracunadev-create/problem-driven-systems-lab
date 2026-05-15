import com.sun.net.httpserver.HttpExchange;
import com.sun.net.httpserver.HttpServer;

import java.io.IOException;
import java.io.OutputStream;
import java.net.InetSocketAddress;
import java.net.URI;
import java.net.URLDecoder;
import java.nio.charset.StandardCharsets;
import java.time.Instant;
import java.util.ArrayDeque;
import java.util.ArrayList;
import java.util.Collections;
import java.util.Deque;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.Executors;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.LongAdder;

/**
 * Caso 01 — API lenta bajo carga (stack Java).
 *
 * Problema: N+1 + filtro no sargable bajo carga concurrente vs worker que refresca
 * un resumen. Misma logica que los stacks PHP/Python/Node, primitivas Java distintas.
 *
 * Primitivas Java que aporta este stack:
 *   - ConcurrentHashMap como cache de summary actualizada por el worker
 *     (lectores de /report-optimized no se bloquean con el worker).
 *   - LongAdder para contadores sin lock contention bajo carga.
 *   - ScheduledExecutorService para el worker (shutdown limpio en SIGTERM).
 *
 * Datos en memoria — un solo `docker compose up` sin PostgreSQL externo.
 */
public class Main {

    private static final String CASE_NAME = "01 - API lenta bajo carga";
    private static final String STACK = "Java 21";
    private static final int PORT = Integer.parseInt(System.getenv().getOrDefault("PORT", "8080"));
    private static final int SUMMARY_REFRESH_SECONDS = 5;
    private static final int MAX_SAMPLES = 3000;
    private static final int MAX_JOB_RUNS = 30;

    private static final List<Customer> customers = new ArrayList<>();
    private static final List<Order> orders = new ArrayList<>();
    /** Cache de summary leida por /report-optimized. Escrita SOLO por el worker. */
    private static final Map<Integer, CustomerSummary> summaryCache = new ConcurrentHashMap<>();
    private static final Map<Integer, Customer> customerById = new HashMap<>();
    private static final Map<String, List<Order>> ordersByRegionPrefix = new HashMap<>();

    private static final Metrics legacyMetrics = new Metrics();
    private static final Metrics optimizedMetrics = new Metrics();
    private static final WorkerState workerState = new WorkerState();
    private static final Deque<JobRun> jobRuns = new ArrayDeque<>();

    public static void main(String[] args) throws Exception {
        seedData();
        HttpServer server = HttpServer.create(new InetSocketAddress(PORT), 0);
        server.createContext("/", Main::route);
        server.setExecutor(Executors.newCachedThreadPool());
        server.start();
        System.out.println("[case01-java] listening on " + PORT);

        ScheduledExecutorService worker = Executors.newSingleThreadScheduledExecutor(r -> {
            Thread t = new Thread(r, "report-refresh-java");
            t.setDaemon(true);
            return t;
        });
        worker.scheduleAtFixedRate(Main::refreshSummary, 1, SUMMARY_REFRESH_SECONDS, TimeUnit.SECONDS);

        Runtime.getRuntime().addShutdownHook(new Thread(() -> {
            worker.shutdownNow();
            server.stop(0);
        }));
    }

    // ---------- routing ----------

    private static void route(HttpExchange ex) throws IOException {
        long start = System.nanoTime();
        URI uri = ex.getRequestURI();
        String path = uri.getPath();
        Map<String, String> q = queryParams(uri.getRawQuery());
        int status = 200;
        String body;
        Metrics tracked = null;

        try {
            switch (path) {
                case "/":
                case "/index":
                    body = indexJson();
                    break;
                case "/health":
                    body = "{\"status\":\"ok\",\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\"}";
                    break;
                case "/report-legacy":
                    body = reportLegacy(bounded(q.getOrDefault("limit", "20"), 1, 200));
                    tracked = legacyMetrics;
                    break;
                case "/report-optimized":
                    body = reportOptimized(bounded(q.getOrDefault("limit", "20"), 1, 200));
                    tracked = optimizedMetrics;
                    break;
                case "/batch/status":
                    body = workerState.toJson();
                    break;
                case "/job-runs":
                    body = jobRunsJson();
                    break;
                case "/diagnostics/summary":
                    body = diagnosticsJson();
                    break;
                case "/metrics":
                    body = metricsJson();
                    break;
                case "/reset-lab":
                    legacyMetrics.reset();
                    optimizedMetrics.reset();
                    synchronized (jobRuns) { jobRuns.clear(); }
                    body = "{\"status\":\"reset\",\"stack\":\"" + STACK + "\"}";
                    break;
                default:
                    status = 404;
                    body = "{\"error\":\"not_found\",\"path\":\"" + escape(path) + "\"}";
            }
        } catch (Exception e) {
            status = 500;
            body = "{\"error\":\"internal\",\"detail\":\"" + escape(e.getMessage()) + "\"}";
        }

        double elapsedMs = round2((System.nanoTime() - start) / 1_000_000.0);
        if (tracked != null) tracked.record(elapsedMs);

        byte[] out = body.getBytes(StandardCharsets.UTF_8);
        ex.getResponseHeaders().add("Content-Type", "application/json; charset=utf-8");
        ex.sendResponseHeaders(status, out.length);
        try (OutputStream os = ex.getResponseBody()) { os.write(out); }
    }

    // ---------- endpoints ----------

    private static String indexJson() {
        return "{" +
                "\"lab\":\"Problem-Driven Systems Lab\"," +
                "\"case\":\"" + CASE_NAME + "\"," +
                "\"stack\":\"" + STACK + "\"," +
                "\"native_primitives\":[\"ConcurrentHashMap (summary cache)\",\"LongAdder (counters)\",\"ScheduledExecutorService (worker)\"]," +
                "\"routes\":{" +
                "\"/health\":\"liveness check\"," +
                "\"/report-legacy?limit=20\":\"N+1 + filtro no sargable\"," +
                "\"/report-optimized?limit=20\":\"batch en memoria + lectura O(1) de summary cache\"," +
                "\"/batch/status\":\"estado del worker\"," +
                "\"/job-runs\":\"historial de corridas del worker\"," +
                "\"/diagnostics/summary\":\"contraste legacy vs optimized\"," +
                "\"/metrics\":\"avg/p95/p99 por ruta\"," +
                "\"/reset-lab\":\"reinicia contadores e historico\"}}";
    }

    /**
     * Legacy: scan lineal (filtro no sargable) + N+1 lookup contra customers.
     * Cada hit cobra ~1.2ms (sleep) para que el costo sea medible bajo carga.
     */
    private static String reportLegacy(int limit) {
        long dbHits = 0;
        long ms0 = System.nanoTime();

        List<Order> scanned = new ArrayList<>();
        for (Order o : orders) {
            if (lowerRegion(o.region).startsWith("n")) scanned.add(o);
        }
        dbHits++;

        int take = Math.min(limit, scanned.size());
        StringBuilder sb = new StringBuilder(8192);
        sb.append("{\"variant\":\"legacy\",\"rows\":[");
        for (int i = 0; i < take; i++) {
            Order o = scanned.get(i);
            Customer c = lookupCustomerOneByOne(o.customerId);
            dbHits++;
            sleepMicros(1200);
            if (i > 0) sb.append(',');
            sb.append("{\"order_id\":").append(o.id)
              .append(",\"customer\":\"").append(escape(c == null ? "" : c.name)).append('"')
              .append(",\"tier\":\"").append(c == null ? "" : c.tier).append('"')
              .append(",\"region\":\"").append(o.region).append('"')
              .append(",\"amount\":").append(o.amount).append('}');
        }
        double elapsedMs = round2((System.nanoTime() - ms0) / 1_000_000.0);
        sb.append("],\"db_hits\":").append(dbHits)
          .append(",\"elapsed_ms\":").append(elapsedMs)
          .append(",\"note\":\"N+1 + scan no sargable; cada hit cuesta tiempo real.\"}");
        return sb.toString();
    }

    /**
     * Optimized: 1 lookup indexado + 1 batch de customers + lectura O(1) del
     * ConcurrentHashMap que el worker actualiza periodicamente.
     */
    private static String reportOptimized(int limit) {
        long dbHits = 0;
        long ms0 = System.nanoTime();

        List<Order> matched = ordersByRegionPrefix.getOrDefault("n", Collections.emptyList());
        dbHits++;
        int take = Math.min(limit, matched.size());

        Map<Integer, Customer> batch = new HashMap<>();
        for (int i = 0; i < take; i++) {
            int cid = matched.get(i).customerId;
            if (!batch.containsKey(cid)) batch.put(cid, customerById.get(cid));
        }
        dbHits++;
        sleepMicros(700);

        StringBuilder sb = new StringBuilder(8192);
        sb.append("{\"variant\":\"optimized\",\"rows\":[");
        for (int i = 0; i < take; i++) {
            Order o = matched.get(i);
            Customer c = batch.get(o.customerId);
            CustomerSummary s = summaryCache.get(o.customerId);
            if (i > 0) sb.append(',');
            sb.append("{\"order_id\":").append(o.id)
              .append(",\"customer\":\"").append(escape(c == null ? "" : c.name)).append('"')
              .append(",\"tier\":\"").append(c == null ? "" : c.tier).append('"')
              .append(",\"region\":\"").append(o.region).append('"')
              .append(",\"amount\":").append(o.amount)
              .append(",\"lifetime_orders\":").append(s == null ? 0 : s.orderCount)
              .append(",\"lifetime_amount\":").append(s == null ? 0.0 : s.totalAmount)
              .append('}');
        }
        double elapsedMs = round2((System.nanoTime() - ms0) / 1_000_000.0);
        sb.append("],\"db_hits\":").append(dbHits)
          .append(",\"elapsed_ms\":").append(elapsedMs)
          .append(",\"summary_cache_size\":").append(summaryCache.size())
          .append(",\"note\":\"1 lookup indexado + 1 batch + O(1) sobre summary cache mantenida por worker.\"}");
        return sb.toString();
    }

    private static String diagnosticsJson() {
        return "{" +
                "\"stack\":\"" + STACK + "\"," +
                "\"case\":\"" + CASE_NAME + "\"," +
                "\"legacy\":" + legacyMetrics.toJson("legacy") + "," +
                "\"optimized\":" + optimizedMetrics.toJson("optimized") + "," +
                "\"summary_cache_size\":" + summaryCache.size() + "," +
                "\"worker\":" + workerState.toJson() + "}";
    }

    private static String metricsJson() {
        return "{\"legacy\":" + legacyMetrics.toJson("legacy") +
                ",\"optimized\":" + optimizedMetrics.toJson("optimized") + "}";
    }

    private static String jobRunsJson() {
        StringBuilder sb = new StringBuilder(1024);
        sb.append("{\"runs\":[");
        synchronized (jobRuns) {
            boolean first = true;
            for (JobRun r : jobRuns) {
                if (!first) sb.append(',');
                sb.append(r.toJson());
                first = false;
            }
        }
        sb.append("],\"max_runs_kept\":").append(MAX_JOB_RUNS).append('}');
        return sb.toString();
    }

    // ---------- worker ----------

    private static void refreshSummary() {
        long t0 = System.nanoTime();
        Map<Integer, CustomerSummary> next = new HashMap<>();
        for (Order o : orders) {
            CustomerSummary s = next.computeIfAbsent(o.customerId, id -> new CustomerSummary());
            s.orderCount++;
            s.totalAmount = round2(s.totalAmount + o.amount);
        }
        for (Map.Entry<Integer, CustomerSummary> e : next.entrySet()) {
            summaryCache.put(e.getKey(), e.getValue());
        }
        long durMs = (System.nanoTime() - t0) / 1_000_000L;
        workerState.update("ok", durMs, "refreshed " + next.size() + " customer summaries");
        synchronized (jobRuns) {
            jobRuns.addFirst(new JobRun(Instant.now().toString(), "ok", durMs, next.size()));
            while (jobRuns.size() > MAX_JOB_RUNS) jobRuns.removeLast();
        }
    }

    // ---------- seed ----------

    private static void seedData() {
        long seed = 102030L;
        String[] regions = {"north", "south", "east", "west"};
        String[] tiers = {"bronze", "silver", "gold"};

        for (int i = 1; i <= 1600; i++) {
            seed = (seed * 9301 + 49297) % 233280;
            String tier = tiers[(int) (seed % tiers.length)];
            Customer c = new Customer(i, "Customer " + i, tier);
            customers.add(c);
            customerById.put(i, c);
        }
        for (int i = 1; i <= 4800; i++) {
            seed = (seed * 9301 + 49297) % 233280;
            int cid = 1 + (int) (seed % customers.size());
            String region = regions[(int) ((seed / 7) % regions.length)];
            double amount = round2(20.0 + (seed % 1000));
            Order o = new Order(i, cid, region, amount);
            orders.add(o);
            ordersByRegionPrefix.computeIfAbsent(region.substring(0, 1), k -> new ArrayList<>()).add(o);
        }
    }

    private static Customer lookupCustomerOneByOne(int id) {
        for (Customer c : customers) if (c.id == id) return c;
        return null;
    }

    private static String lowerRegion(String r) { return r == null ? "" : r.toLowerCase(); }

    // ---------- types ----------

    private record Customer(int id, String name, String tier) {}
    private record Order(int id, int customerId, String region, double amount) {}

    private static final class CustomerSummary {
        int orderCount;
        double totalAmount;
    }

    private static final class WorkerState {
        private volatile String status = "init";
        private volatile long lastDurationMs = -1;
        private volatile String lastMessage = "worker not started yet";
        private volatile String lastHeartbeat = null;

        void update(String s, long durMs, String msg) {
            this.status = s;
            this.lastDurationMs = durMs;
            this.lastMessage = msg;
            this.lastHeartbeat = Instant.now().toString();
        }

        String toJson() {
            return "{" +
                    "\"worker_name\":\"report-refresh-java\"," +
                    "\"last_status\":\"" + escape(status) + "\"," +
                    "\"last_duration_ms\":" + lastDurationMs + "," +
                    "\"last_message\":\"" + escape(lastMessage) + "\"," +
                    "\"last_heartbeat\":\"" + escape(lastHeartbeat) + "\"}";
        }
    }

    private static final class JobRun {
        final String at;
        final String status;
        final long durationMs;
        final int customersRefreshed;

        JobRun(String at, String status, long durationMs, int customersRefreshed) {
            this.at = at;
            this.status = status;
            this.durationMs = durationMs;
            this.customersRefreshed = customersRefreshed;
        }

        String toJson() {
            return "{\"at\":\"" + escape(at) + "\",\"status\":\"" + escape(status) +
                    "\",\"duration_ms\":" + durationMs +
                    ",\"customers_refreshed\":" + customersRefreshed + "}";
        }
    }

    private static final class Metrics {
        private final LongAdder requests = new LongAdder();
        private final List<Double> samples = Collections.synchronizedList(new ArrayList<>());

        void record(double elapsedMs) {
            requests.increment();
            synchronized (samples) {
                samples.add(elapsedMs);
                while (samples.size() > MAX_SAMPLES) samples.remove(0);
            }
        }

        void reset() {
            requests.reset();
            synchronized (samples) { samples.clear(); }
        }

        String toJson(String label) {
            List<Double> snap;
            synchronized (samples) { snap = new ArrayList<>(samples); }
            return "{\"label\":\"" + label + "\"," +
                    "\"requests\":" + requests.sum() + "," +
                    "\"sample_count\":" + snap.size() + "," +
                    "\"avg_ms\":" + avg(snap) + "," +
                    "\"p95_ms\":" + percentile(snap, 95) + "," +
                    "\"p99_ms\":" + percentile(snap, 99) + "}";
        }
    }

    // ---------- helpers ----------

    private static double avg(List<Double> values) {
        if (values.isEmpty()) return 0.0;
        double s = 0.0;
        for (double v : values) s += v;
        return round2(s / values.size());
    }

    private static double percentile(List<Double> values, int percent) {
        if (values.isEmpty()) return 0.0;
        List<Double> ordered = new ArrayList<>(values);
        Collections.sort(ordered);
        int idx = Math.max(0, Math.min(ordered.size() - 1,
                (int) Math.ceil((percent / 100.0) * ordered.size()) - 1));
        return round2(ordered.get(idx));
    }

    private static double round2(double v) { return Math.round(v * 100.0) / 100.0; }

    private static int bounded(String raw, int min, int max) {
        try {
            int n = Integer.parseInt(raw);
            return Math.max(min, Math.min(n, max));
        } catch (NumberFormatException e) { return min; }
    }

    private static void sleepMicros(int micros) {
        try { TimeUnit.MICROSECONDS.sleep(micros); }
        catch (InterruptedException ignored) { Thread.currentThread().interrupt(); }
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
