import com.sun.net.httpserver.HttpExchange;
import com.sun.net.httpserver.HttpServer;

import java.io.IOException;
import java.io.OutputStream;
import java.net.InetSocketAddress;
import java.net.URI;
import java.net.URLDecoder;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.Collections;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.Executors;
import java.util.concurrent.atomic.LongAdder;

/**
 * Caso 10 — Arquitectura cara para un problema simple — stack Java.
 *
 * Legacy complex: N hops simulados (queue → cache → service → DB) con
 * serializacion JSON-like (StringBuilder loops) en cada hop, alto CPU.
 * Right-sized: HashMap directo, O(1), CPU minimo.
 *
 * Primitivas Java distintivas:
 *   - Costo CPU medido en iteraciones de StringBuilder + parse manual
 *     (espejo del JSON.stringify/parse Node con costo real, no simulado).
 *   - System.nanoTime() para medir CPU time por request.
 */
public class Main {

    private static final String CASE_NAME = "10 - Arquitectura cara para algo simple";
    private static final String STACK = "Java 21";
    private static final int PORT = Integer.parseInt(System.getenv().getOrDefault("PORT", "8080"));

    private static final Map<String, Long> directStore = new HashMap<>();
    private static final List<String> decisions = Collections.synchronizedList(new ArrayList<>());

    private static final LongAdder complexCalls = new LongAdder();
    private static final LongAdder complexTimeouts = new LongAdder();
    private static final LongAdder rightSizedCalls = new LongAdder();

    static {
        for (int i = 1; i <= 100; i++) directStore.put("feature-" + i, (long) (i * 10));
        decisions.add("ADR-001: empezar con monolito + HashMap; revisitar si pasa de 10k QPS sostenido");
        decisions.add("ADR-002: posponer queue distribuida hasta que el modelo de datos lo requiera");
    }

    public static void main(String[] args) throws Exception {
        HttpServer server = HttpServer.create(new InetSocketAddress(PORT), 0);
        server.createContext("/", Main::route);
        server.setExecutor(Executors.newCachedThreadPool());
        server.start();
        System.out.println("[case10-java] listening on " + PORT);
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
                            "\",\"routes\":[\"/health\",\"/feature-complex?key=feature-1&hops=8\",\"/feature-right-sized?key=feature-1\",\"/decisions\",\"/diagnostics/summary\",\"/reset-lab\"]}";
                    break;
                case "/health":
                    body = "{\"status\":\"ok\",\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\"}";
                    break;
                case "/feature-complex":
                    body = featureComplex(q.getOrDefault("key", "feature-1"),
                            bounded(q.getOrDefault("hops", "8"), 1, 50));
                    complexCalls.increment();
                    break;
                case "/feature-right-sized":
                    body = featureRightSized(q.getOrDefault("key", "feature-1"));
                    rightSizedCalls.increment();
                    break;
                case "/decisions":
                    body = decisionsJson();
                    break;
                case "/diagnostics/summary":
                    body = diagnosticsJson();
                    break;
                case "/reset-lab":
                    complexCalls.reset(); complexTimeouts.reset(); rightSizedCalls.reset();
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

    /** Complex: simula N hops con serializacion costosa por hop. */
    private static String featureComplex(String key, int hops) {
        long t0 = System.nanoTime();
        // pasada inicial: payload "viaja" por N servicios, cada uno hace stringify/parse
        StringBuilder payload = new StringBuilder("{\"key\":\"").append(key).append("\",\"trace\":[");
        for (int h = 0; h < hops; h++) {
            // simula serializacion + dependencia: construye un array grande y lo recorre
            StringBuilder hop = new StringBuilder(2048);
            hop.append("\"hop-").append(h).append("-");
            for (int i = 0; i < 200; i++) hop.append((char) ('A' + (i % 26)));
            hop.append("\"");
            payload.append(hop);
            if (h < hops - 1) payload.append(',');
        }
        payload.append("],\"final_lookup\":");
        Long value = directStore.get(key);
        payload.append(value == null ? "null" : value).append('}');

        long elapsedMs = (System.nanoTime() - t0) / 1_000_000L;
        // simula timeout interno si hops es alto (>20)
        if (hops > 20) {
            complexTimeouts.increment();
            return "{\"variant\":\"complex\",\"status\":\"internal_timeout\"," +
                    "\"hops\":" + hops + ",\"elapsed_ms\":" + elapsedMs +
                    ",\"services_touched\":" + hops + ",\"cost_usd_month_est\":" + (hops * 25) +
                    ",\"lead_time_days\":" + (hops * 2) +
                    ",\"note\":\"sobrearquitectura: muchos hops, timeout interno bajo seasonal_peak.\"}";
        }
        return "{\"variant\":\"complex\",\"key\":\"" + key + "\"," +
                "\"hops\":" + hops + ",\"elapsed_ms\":" + elapsedMs +
                ",\"services_touched\":" + hops + ",\"cost_usd_month_est\":" + (hops * 25) +
                ",\"lead_time_days\":" + (hops * 2) +
                ",\"value\":" + (value == null ? "null" : value) +
                ",\"payload_bytes\":" + payload.length() +
                ",\"note\":\"N hops con serializacion en cada uno; CPU real medido.\"}";
    }

    /** Right-sized: HashMap lookup O(1). */
    private static String featureRightSized(String key) {
        long t0 = System.nanoTime();
        Long value = directStore.get(key);
        long elapsedMs = (System.nanoTime() - t0) / 1_000_000L;
        return "{\"variant\":\"right_sized\",\"key\":\"" + key + "\"," +
                "\"elapsed_ms\":" + elapsedMs +
                ",\"services_touched\":1,\"cost_usd_month_est\":3" +
                ",\"lead_time_days\":1" +
                ",\"value\":" + (value == null ? "null" : value) +
                ",\"note\":\"HashMap O(1); proporcional al problema real.\"}";
    }

    private static String decisionsJson() {
        StringBuilder sb = new StringBuilder(1024);
        sb.append("{\"decisions\":[");
        synchronized (decisions) {
            boolean first = true;
            for (String d : decisions) {
                if (!first) sb.append(',');
                sb.append('"').append(escape(d)).append('"');
                first = false;
            }
        }
        sb.append("]}");
        return sb.toString();
    }

    private static String diagnosticsJson() {
        return "{\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\"," +
                "\"complex\":{\"calls\":" + complexCalls.sum() +
                ",\"timeouts\":" + complexTimeouts.sum() +
                ",\"behavior\":\"N hops, serializacion costosa por hop, CPU/lead time altos\"}," +
                "\"right_sized\":{\"calls\":" + rightSizedCalls.sum() +
                ",\"behavior\":\"HashMap O(1), cost minimo, lead time minimo\"}," +
                "\"decisions\":" + decisionsJson() + "}";
    }

    private static int bounded(String raw, int min, int max) {
        try {
            int n = Integer.parseInt(raw);
            return Math.max(min, Math.min(n, max));
        } catch (NumberFormatException e) { return min; }
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
