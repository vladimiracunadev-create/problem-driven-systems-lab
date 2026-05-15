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
import java.util.concurrent.atomic.LongAdder;
import java.util.function.Function;

/**
 * Caso 07 — Modernizacion incremental de monolito (strangler) — stack Java.
 *
 * Legacy: cambio toca shared_schema acoplado, blast radius alto.
 * Strangler: routing por consumer via Map<String, Function> mutable en runtime.
 * Registrar el routing del nuevo modulo es una linea, sin reload del proceso.
 *
 * Primitivas Java distintivas:
 *   - ConcurrentHashMap<String, Function<Request,Response>> como tabla de routing
 *     mutable bajo escrituras concurrentes — espejo del Map<consumer,handler> Node.
 *   - Function como ACL (closure que filtra contrato).
 *   - record types para Request/Response (inmutables, audit-friendly).
 */
public class Main {

    private static final String CASE_NAME = "07 - Modernizacion incremental de monolito";
    private static final String STACK = "Java 21";
    private static final int PORT = Integer.parseInt(System.getenv().getOrDefault("PORT", "8080"));

    private record Request(String consumer, String op, Map<String, String> payload) {}
    private record Response(String result, String routedTo, int blastRadiusScore, int riskScore) {}

    /** Routing table: clave = "consumer:op", valor = handler. Modificable en runtime. */
    private static final Map<String, Function<Request, Response>> routingTable = new ConcurrentHashMap<>();
    private static final Map<String, Integer> migrationProgress = new ConcurrentHashMap<>();

    private static final LongAdder legacyCalls = new LongAdder();
    private static final LongAdder stranglerCalls = new LongAdder();
    private static final LongAdder routedToNewModule = new LongAdder();

    static {
        // Routing inicial: billing migrado al nuevo modulo, otros aun al legacy
        routingTable.put("billing:change", req -> new Response("ok-new-module", "new-billing-svc", 1, 1));
        migrationProgress.put("billing", 100);
        migrationProgress.put("orders", 0);
        migrationProgress.put("inventory", 0);
        migrationProgress.put("reporting", 0);
    }

    public static void main(String[] args) throws Exception {
        HttpServer server = HttpServer.create(new InetSocketAddress(PORT), 0);
        server.createContext("/", Main::route);
        server.setExecutor(Executors.newCachedThreadPool());
        server.start();
        System.out.println("[case07-java] listening on " + PORT);
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
                            "\",\"routes\":[\"/health\",\"/change-legacy?consumer=billing&op=change\",\"/change-strangler?consumer=billing&op=change\",\"/flows\",\"/diagnostics/summary\",\"/reset-lab\"]}";
                    break;
                case "/health":
                    body = "{\"status\":\"ok\",\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\"}";
                    break;
                case "/change-legacy":
                    body = changeLegacy(q.getOrDefault("consumer", "billing"), q.getOrDefault("op", "change"));
                    legacyCalls.increment();
                    break;
                case "/change-strangler":
                    body = changeStrangler(q.getOrDefault("consumer", "billing"), q.getOrDefault("op", "change"));
                    stranglerCalls.increment();
                    break;
                case "/flows":
                    body = flowsJson();
                    break;
                case "/diagnostics/summary":
                    body = diagnosticsJson();
                    break;
                case "/reset-lab":
                    legacyCalls.reset(); stranglerCalls.reset(); routedToNewModule.reset();
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

    /** Legacy: cambio toca shared_schema, blast radius alto, riesgo alto. */
    private static String changeLegacy(String consumer, String op) {
        // todos los consumers pegan al mismo monolito; cambio en uno propaga a todos
        int blastRadius = 4;  // 4 modulos afectados (billing+orders+inventory+reporting)
        int risk = 8;
        return "{\"variant\":\"legacy\",\"consumer\":\"" + consumer + "\",\"op\":\"" + op +
                "\",\"routed_to\":\"shared-monolith\"," +
                "\"blast_radius_score\":" + blastRadius +
                ",\"risk_score\":" + risk +
                ",\"note\":\"cambio en shared_schema afecta los 4 modulos del monolito.\"}";
    }

    /** Strangler: routing table consulta primero si hay handler nuevo. */
    private static String changeStrangler(String consumer, String op) {
        Request req = new Request(consumer, op, Map.of());
        String key = consumer + ":" + op;
        Function<Request, Response> handler = routingTable.get(key);
        if (handler != null) {
            Response r = handler.apply(req);
            routedToNewModule.increment();
            return "{\"variant\":\"strangler\",\"consumer\":\"" + consumer + "\",\"op\":\"" + op +
                    "\",\"routed_to\":\"" + r.routedTo + "\"," +
                    "\"blast_radius_score\":" + r.blastRadiusScore +
                    ",\"risk_score\":" + r.riskScore +
                    ",\"note\":\"routing table apunta a nuevo modulo; monolito intocado.\"}";
        }
        // fallback al monolito legacy con blast radius pero acotado al consumer
        return "{\"variant\":\"strangler\",\"consumer\":\"" + consumer + "\",\"op\":\"" + op +
                "\",\"routed_to\":\"legacy-monolith\"," +
                "\"blast_radius_score\":2," +
                "\"risk_score\":4," +
                "\"note\":\"consumer aun no migrado; routing cae al legacy pero con ACL.\"}";
    }

    private static String flowsJson() {
        StringBuilder sb = new StringBuilder(512);
        sb.append("{\"migration_progress\":{");
        boolean first = true;
        for (Map.Entry<String, Integer> e : migrationProgress.entrySet()) {
            if (!first) sb.append(',');
            sb.append("\"").append(e.getKey()).append("\":").append(e.getValue());
            first = false;
        }
        sb.append("},\"routing_table_size\":").append(routingTable.size()).append('}');
        return sb.toString();
    }

    private static String diagnosticsJson() {
        return "{\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\"," +
                "\"legacy\":{\"calls\":" + legacyCalls.sum() + ",\"avg_blast_radius\":4,\"avg_risk\":8}," +
                "\"strangler\":{\"calls\":" + stranglerCalls.sum() +
                ",\"routed_to_new_module\":" + routedToNewModule.sum() +
                ",\"routing_table_size\":" + routingTable.size() + "}," +
                "\"flows\":" + flowsJson() + "}";
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
