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
import java.util.Collections;
import java.util.Deque;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.UUID;
import java.util.concurrent.Executors;
import java.util.concurrent.atomic.LongAdder;

/**
 * Caso 03 — Observabilidad deficiente y logs inutiles (stack Java).
 *
 * Legacy: System.out.println sin correlation, sin contexto. Errores opacos.
 * Observable: log estructurado JSON con correlationId + ScopedValue-like via
 * ThreadLocal, mas /logs endpoint que devuelve los ultimos N logs estructurados.
 *
 * Primitiva Java distintiva:
 *   - ThreadLocal<RequestContext> como contexto de correlation (espejo del
 *     ScopedValue de JDK 21 que requiere preview flags). Cada handler arranca
 *     un contexto y lo limpia al final.
 *   - LongAdder para contadores por tipo de evento.
 */
public class Main {

    private static final String CASE_NAME = "03 - Observabilidad deficiente y logs inutiles";
    private static final String STACK = "Java 21";
    private static final int PORT = Integer.parseInt(System.getenv().getOrDefault("PORT", "8080"));
    private static final int MAX_LOG_ENTRIES = 200;

    private static final Deque<String> recentLogs = new ArrayDeque<>();
    private static final ThreadLocal<RequestContext> CTX = new ThreadLocal<>();

    private static final LongAdder legacyErrors = new LongAdder();
    private static final LongAdder observableErrors = new LongAdder();
    private static final LongAdder legacyRequests = new LongAdder();
    private static final LongAdder observableRequests = new LongAdder();

    public static void main(String[] args) throws Exception {
        HttpServer server = HttpServer.create(new InetSocketAddress(PORT), 0);
        server.createContext("/", Main::route);
        server.setExecutor(Executors.newCachedThreadPool());
        server.start();
        System.out.println("[case03-java] listening on " + PORT);
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
                            "\",\"routes\":[\"/health\",\"/checkout-legacy?total=100\",\"/checkout-observable?total=100\",\"/logs\",\"/metrics\",\"/diagnostics/summary\",\"/reset-lab\"]}";
                    break;
                case "/health":
                    body = "{\"status\":\"ok\",\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\"}";
                    break;
                case "/checkout-legacy":
                    body = checkoutLegacy(q.getOrDefault("total", "100"));
                    legacyRequests.increment();
                    break;
                case "/checkout-observable":
                    body = checkoutObservable(q.getOrDefault("total", "100"));
                    observableRequests.increment();
                    break;
                case "/logs":
                    body = logsJson();
                    break;
                case "/metrics":
                case "/diagnostics/summary":
                    body = diagnosticsJson();
                    break;
                case "/reset-lab":
                    legacyErrors.reset(); observableErrors.reset();
                    legacyRequests.reset(); observableRequests.reset();
                    synchronized (recentLogs) { recentLogs.clear(); }
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

    /** Legacy: log opaco, sin correlation, sin ID, sin contexto. */
    private static String checkoutLegacy(String totalRaw) {
        double total = parseDoubleOr(totalRaw, 100.0);
        // log inutil
        System.out.println("[INFO] processing checkout");
        if (total > 500) {
            System.out.println("[ERROR] checkout failed");
            legacyErrors.increment();
            return "{\"variant\":\"legacy\",\"status\":\"error\",\"note\":\"log dice 'checkout failed' sin id, sin total, sin causa.\"}";
        }
        System.out.println("[INFO] checkout ok");
        return "{\"variant\":\"legacy\",\"status\":\"ok\",\"note\":\"log dice 'checkout ok' sin contexto util.\"}";
    }

    /** Observable: correlation ID propagado, log estructurado JSON, /logs endpoint. */
    private static String checkoutObservable(String totalRaw) {
        String corrId = UUID.randomUUID().toString();
        CTX.set(new RequestContext(corrId, "checkout-observable", Instant.now().toString()));
        try {
            double total = parseDoubleOr(totalRaw, 100.0);
            structuredLog("info", "checkout_start", Map.of("total", String.valueOf(total)));
            if (total > 500) {
                structuredLog("error", "checkout_failed", Map.of(
                        "total", String.valueOf(total),
                        "reason", "exceeds_limit",
                        "limit", "500"));
                observableErrors.increment();
                return "{\"variant\":\"observable\",\"status\":\"error\",\"correlation_id\":\"" + corrId +
                        "\",\"reason\":\"exceeds_limit\",\"limit\":500,\"total\":" + total + "}";
            }
            structuredLog("info", "checkout_ok", Map.of("total", String.valueOf(total)));
            return "{\"variant\":\"observable\",\"status\":\"ok\",\"correlation_id\":\"" + corrId +
                    "\",\"total\":" + total + ",\"note\":\"correlation_id propagado en logs estructurados.\"}";
        } finally {
            CTX.remove();
        }
    }

    private static void structuredLog(String level, String event, Map<String, String> fields) {
        RequestContext c = CTX.get();
        StringBuilder sb = new StringBuilder(256);
        sb.append("{\"ts\":\"").append(Instant.now()).append('"');
        sb.append(",\"level\":\"").append(level).append('"');
        sb.append(",\"event\":\"").append(event).append('"');
        if (c != null) {
            sb.append(",\"correlation_id\":\"").append(c.corrId).append('"');
            sb.append(",\"route\":\"").append(c.route).append('"');
        }
        for (Map.Entry<String, String> e : fields.entrySet()) {
            sb.append(",\"").append(e.getKey()).append("\":\"").append(escape(e.getValue())).append('"');
        }
        sb.append('}');
        String line = sb.toString();
        synchronized (recentLogs) {
            recentLogs.addFirst(line);
            while (recentLogs.size() > MAX_LOG_ENTRIES) recentLogs.removeLast();
        }
    }

    private static String logsJson() {
        StringBuilder sb = new StringBuilder(1024);
        sb.append("{\"entries\":[");
        synchronized (recentLogs) {
            boolean first = true;
            for (String line : recentLogs) {
                if (!first) sb.append(',');
                sb.append(line);
                first = false;
            }
        }
        sb.append("],\"max_kept\":").append(MAX_LOG_ENTRIES).append('}');
        return sb.toString();
    }

    private static String diagnosticsJson() {
        return "{\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\"," +
                "\"legacy\":{\"requests\":" + legacyRequests.sum() + ",\"errors\":" + legacyErrors.sum() +
                ",\"observability\":\"println sin correlation, sin contexto\"}," +
                "\"observable\":{\"requests\":" + observableRequests.sum() + ",\"errors\":" + observableErrors.sum() +
                ",\"observability\":\"log estructurado JSON con correlation_id, /logs endpoint\"}}";
    }

    private record RequestContext(String corrId, String route, String startedAt) {}

    private static double parseDoubleOr(String raw, double dflt) {
        try { return Double.parseDouble(raw); } catch (Exception e) { return dflt; }
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
