import com.sun.net.httpserver.HttpExchange;
import com.sun.net.httpserver.HttpServer;

import java.io.IOException;
import java.io.OutputStream;
import java.net.InetSocketAddress;
import java.net.URI;
import java.net.URLDecoder;
import java.nio.charset.StandardCharsets;
import java.time.Instant;
import java.util.HashMap;
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.Executors;
import java.util.concurrent.Semaphore;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicReference;
import java.util.concurrent.atomic.LongAdder;

/**
 * Caso 09 — Integracion externa inestable — stack Java.
 *
 * Legacy: cada request pega al provider sin cache, sin budget, sin breaker.
 * Hardened: ConcurrentHashMap como snapshot cache + Semaphore como budget de cuota +
 * AtomicReference<String> como breaker state + schema mapping defensivo.
 *
 * Primitivas Java distintivas:
 *   - Semaphore para budget de cuota (max N requests/ventana).
 *   - ConcurrentHashMap como snapshot cache thread-safe.
 *   - AtomicReference<String> para estado del breaker (closed/open/half_open).
 */
public class Main {

    private static final String CASE_NAME = "09 - Integracion externa inestable";
    private static final String STACK = "Java 21";
    private static final int PORT = Integer.parseInt(System.getenv().getOrDefault("PORT", "8080"));
    private static final int BUDGET_PER_WINDOW = 5;

    /** Snapshot cache leida por hardened cuando el provider esta agotado. */
    private static final Map<String, String> snapshotCache = new ConcurrentHashMap<>();
    /** Budget de cuota: max BUDGET_PER_WINDOW requests reales al provider por ventana. */
    private static final Semaphore providerBudget = new Semaphore(BUDGET_PER_WINDOW);
    /** Breaker state. */
    private static final AtomicReference<String> breaker = new AtomicReference<>("closed");

    private static final LongAdder legacyCalls = new LongAdder();
    private static final LongAdder legacyFailures = new LongAdder();
    private static final LongAdder hardenedCalls = new LongAdder();
    private static final LongAdder hardenedFromCache = new LongAdder();
    private static final LongAdder hardenedBudgetDenied = new LongAdder();

    static {
        // pre-poblar cache con snapshots de schema viejo
        snapshotCache.put("widget-A", "{\"name\":\"Widget A\",\"price\":42.0,\"snapshot_at\":\"2026-05-01T00:00:00Z\"}");
        snapshotCache.put("widget-B", "{\"name\":\"Widget B\",\"price\":13.5,\"snapshot_at\":\"2026-05-01T00:00:00Z\"}");
    }

    public static void main(String[] args) throws Exception {
        HttpServer server = HttpServer.create(new InetSocketAddress(PORT), 0);
        server.createContext("/", Main::route);
        server.setExecutor(Executors.newCachedThreadPool());
        server.start();
        System.out.println("[case09-java] listening on " + PORT);
        Runtime.getRuntime().addShutdownHook(new Thread(() -> server.stop(0)));
    }

    private static void route(HttpExchange ex) throws IOException {
        URI uri = ex.getRequestURI();
        String path = uri.getPath();
        Map<String, String> q = queryParams(uri.getRawQuery());
        int status = 200;
        String body;

        try {
            switch (path) {
                case "/":
                case "/index":
                    body = "{\"case\":\"" + CASE_NAME + "\",\"stack\":\"" + STACK +
                            "\",\"routes\":[\"/health\",\"/catalog-legacy?sku=widget-A&scenario=drift\",\"/catalog-hardened?sku=widget-A&scenario=drift\",\"/sync-events\",\"/diagnostics/summary\",\"/reset-lab\"]}";
                    break;
                case "/health":
                    body = "{\"status\":\"ok\",\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\"}";
                    break;
                case "/catalog-legacy":
                    body = catalogLegacy(q.getOrDefault("sku", "widget-A"), q.getOrDefault("scenario", "ok"));
                    legacyCalls.increment();
                    break;
                case "/catalog-hardened":
                    body = catalogHardened(q.getOrDefault("sku", "widget-A"), q.getOrDefault("scenario", "ok"));
                    hardenedCalls.increment();
                    break;
                case "/sync-events":
                    body = stateJson();
                    break;
                case "/diagnostics/summary":
                    body = diagnosticsJson();
                    break;
                case "/reset-lab":
                    legacyCalls.reset(); legacyFailures.reset();
                    hardenedCalls.reset(); hardenedFromCache.reset(); hardenedBudgetDenied.reset();
                    providerBudget.drainPermits();
                    providerBudget.release(BUDGET_PER_WINDOW);
                    breaker.set("closed");
                    body = "{\"status\":\"reset\"}";
                    break;
                default:
                    status = 404;
                    body = "{\"error\":\"not_found\",\"path\":\"" + escape(path) + "\"}";
            }
        } catch (Exception e) {
            status = 500;
            body = "{\"error\":\"internal\",\"detail\":\"" + escape(e.getMessage()) + "\"}";
        }

        byte[] out = body.getBytes(StandardCharsets.UTF_8);
        ex.getResponseHeaders().add("Content-Type", "application/json; charset=utf-8");
        ex.sendResponseHeaders(status, out.length);
        try (OutputStream os = ex.getResponseBody()) { os.write(out); }
    }

    /** Legacy: cada request golpea al provider sin cache; si scenario=drift, falla. */
    private static String catalogLegacy(String sku, String scenario) {
        boolean drift = scenario.equals("drift") || scenario.equals("rate_limit") || scenario.equals("maintenance");
        if (drift) {
            legacyFailures.increment();
            return "{\"variant\":\"legacy\",\"sku\":\"" + sku + "\",\"status\":\"failed\"," +
                    "\"scenario\":\"" + scenario + "\"," +
                    "\"note\":\"provider devuelve drift de esquema / rate limit / maintenance; sin cache, falla.\"}";
        }
        return "{\"variant\":\"legacy\",\"sku\":\"" + sku + "\",\"status\":\"ok\"," +
                "\"data\":{\"name\":\"" + sku + " Live\",\"price\":42.0}," +
                "\"note\":\"hit directo al provider, sin budget ni cache.\"}";
    }

    /** Hardened: budget + snapshot cache + breaker + schema mapping defensivo. */
    private static String catalogHardened(String sku, String scenario) {
        boolean drift = scenario.equals("drift") || scenario.equals("rate_limit") || scenario.equals("maintenance");

        // budget check
        if (!providerBudget.tryAcquire()) {
            hardenedBudgetDenied.increment();
            return fromSnapshot(sku, "budget_exhausted", "budget de cuota agotado; sirviendo snapshot cacheado.");
        }
        try {
            if (drift) {
                // provider falla, abrimos breaker y servimos snapshot
                breaker.set("open");
                return fromSnapshot(sku, "provider_failing", "provider con drift/rate_limit/maintenance; snapshot cacheado.");
            }
            // success path: refresca cache
            String fresh = "{\"name\":\"" + sku + " Live\",\"price\":42.0,\"snapshot_at\":\"" + Instant.now() + "\"}";
            snapshotCache.put(sku, fresh);
            breaker.set("closed");
            return "{\"variant\":\"hardened\",\"sku\":\"" + sku + "\",\"status\":\"ok\"," +
                    "\"data\":" + fresh + ",\"served_from\":\"provider\"," +
                    "\"budget_remaining\":" + providerBudget.availablePermits() +
                    ",\"breaker\":\"" + breaker.get() + "\"}";
        } finally {
            // No liberamos el permit (cuenta como uso). Reset manual via /reset-lab.
        }
    }

    private static String fromSnapshot(String sku, String reason, String note) {
        hardenedFromCache.increment();
        String cached = snapshotCache.getOrDefault(sku, "null");
        return "{\"variant\":\"hardened\",\"sku\":\"" + sku + "\",\"status\":\"served_from_cache\"," +
                "\"reason\":\"" + reason + "\"," +
                "\"data\":" + cached + "," +
                "\"served_from\":\"snapshot_cache\"," +
                "\"budget_remaining\":" + providerBudget.availablePermits() +
                ",\"breaker\":\"" + breaker.get() + "\"," +
                "\"note\":\"" + note + "\"}";
    }

    private static String stateJson() {
        return "{\"breaker\":\"" + breaker.get() + "\"," +
                "\"budget_remaining\":" + providerBudget.availablePermits() +
                ",\"budget_max\":" + BUDGET_PER_WINDOW +
                ",\"snapshot_cache_size\":" + snapshotCache.size() + "}";
    }

    private static String diagnosticsJson() {
        return "{\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\"," +
                "\"legacy\":{\"calls\":" + legacyCalls.sum() + ",\"failures\":" + legacyFailures.sum() + "}," +
                "\"hardened\":{\"calls\":" + hardenedCalls.sum() +
                ",\"served_from_cache\":" + hardenedFromCache.sum() +
                ",\"budget_denied\":" + hardenedBudgetDenied.sum() + "}," +
                "\"state\":" + stateJson() + "}";
    }

    private static String escape(String v) { return v == null ? "" : v.replace("\\", "\\\\").replace("\"", "\\\""); }

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
