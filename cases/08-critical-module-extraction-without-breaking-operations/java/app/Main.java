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
import java.util.Deque;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.CopyOnWriteArrayList;
import java.util.concurrent.Executors;
import java.util.concurrent.atomic.LongAdder;
import java.util.function.Consumer;
import java.util.function.Function;

/**
 * Caso 08 — Extraccion critica de modulo (cutover) — stack Java.
 *
 * Legacy big-bang: cambio de contrato rompe consumers sensibles (checkout, partners).
 * Compatible: proxy de compatibilidad traduce contrato viejo ↔ nuevo en vuelo;
 * EventEmitter-like (CopyOnWriteArrayList<Consumer<Event>>) publica cutover events.
 *
 * Primitivas Java distintivas:
 *   - Function<OldRequest, NewRequest> como proxy de compatibilidad de contrato.
 *   - CopyOnWriteArrayList<Consumer<Event>> como EventEmitter thread-safe.
 *   - record types para Old/New request shapes.
 */
public class Main {

    private static final String CASE_NAME = "08 - Extraccion critica de modulo";
    private static final String STACK = "Java 21";
    private static final int PORT = Integer.parseInt(System.getenv().getOrDefault("PORT", "8080"));
    private static final int MAX_EVENTS = 50;

    /** Contrato viejo: usa cost_usd. */
    private record PriceRequestOld(String sku, double costUsd) {}
    /** Contrato nuevo: usa price + currency. */
    private record PriceRequestNew(String sku, double price, String currency) {}

    /** Adapter de compatibilidad: traduce old → new en vuelo. */
    private static final Function<PriceRequestOld, PriceRequestNew> compatProxy = old ->
            new PriceRequestNew(old.sku(), old.costUsd() * 1.0, "USD");

    /** Consumers sensibles ya migrados al nuevo contrato. */
    private static final Map<String, Boolean> cutoverProgress = new ConcurrentHashMap<>();
    /** Event bus thread-safe (espejo de EventEmitter Node). */
    private static final List<Consumer<String>> cutoverBus = new CopyOnWriteArrayList<>();
    private static final Deque<String> events = new ArrayDeque<>();

    private static final LongAdder bigbangCalls = new LongAdder();
    private static final LongAdder bigbangBroken = new LongAdder();
    private static final LongAdder compatibleCalls = new LongAdder();
    private static final LongAdder proxyHits = new LongAdder();
    private static final LongAdder contractTestsPassed = new LongAdder();

    static {
        cutoverProgress.put("checkout", false);
        cutoverProgress.put("partners", false);
        cutoverProgress.put("backoffice", false);
        // suscriptor por defecto: registrar evento al buffer
        cutoverBus.add(evt -> {
            synchronized (events) {
                events.addFirst(evt);
                while (events.size() > MAX_EVENTS) events.removeLast();
            }
        });
    }

    public static void main(String[] args) throws Exception {
        HttpServer server = HttpServer.create(new InetSocketAddress(PORT), 0);
        server.createContext("/", Main::route);
        server.setExecutor(Executors.newCachedThreadPool());
        server.start();
        System.out.println("[case08-java] listening on " + PORT);
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
                            "\",\"routes\":[\"/health\",\"/pricing-bigbang?consumer=checkout&sku=ABC&cost_usd=100\",\"/pricing-compatible?consumer=checkout&sku=ABC&cost_usd=100\",\"/flows\",\"/diagnostics/summary\",\"/reset-lab\"]}";
                    break;
                case "/health":
                    body = "{\"status\":\"ok\",\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\"}";
                    break;
                case "/pricing-bigbang":
                    body = pricingBigbang(q.getOrDefault("consumer", "checkout"),
                            q.getOrDefault("sku", "ABC"),
                            parseDoubleOr(q.getOrDefault("cost_usd", "100"), 100.0));
                    bigbangCalls.increment();
                    break;
                case "/pricing-compatible":
                    body = pricingCompatible(q.getOrDefault("consumer", "checkout"),
                            q.getOrDefault("sku", "ABC"),
                            parseDoubleOr(q.getOrDefault("cost_usd", "100"), 100.0));
                    compatibleCalls.increment();
                    break;
                case "/flows":
                    body = flowsJson();
                    break;
                case "/diagnostics/summary":
                    body = diagnosticsJson();
                    break;
                case "/reset-lab":
                    bigbangCalls.reset(); bigbangBroken.reset();
                    compatibleCalls.reset(); proxyHits.reset(); contractTestsPassed.reset();
                    synchronized (events) { events.clear(); }
                    cutoverProgress.replaceAll((k, v) -> false);
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

    /** Big-bang: cambia el contrato, consumers que esperan cost_usd se rompen. */
    private static String pricingBigbang(String consumer, String sku, double costUsd) {
        // Nuevo modulo solo entiende {price, currency}, consumer manda {sku, cost_usd}
        // → 400 contract_violation
        bigbangBroken.increment();
        emit("bigbang_broke:" + consumer);
        return "{\"variant\":\"bigbang\",\"consumer\":\"" + consumer + "\",\"sku\":\"" + sku +
                "\",\"status\":\"contract_violation\"," +
                "\"reason\":\"new module expects {price, currency}; consumer sent {sku, cost_usd}\"," +
                "\"note\":\"cutover sin compat layer rompe consumers sensibles.\"}";
    }

    /** Compatible: proxy traduce old→new, EventEmitter notifica avance. */
    private static String pricingCompatible(String consumer, String sku, double costUsd) {
        PriceRequestOld old = new PriceRequestOld(sku, costUsd);
        PriceRequestNew translated = compatProxy.apply(old);
        proxyHits.increment();
        contractTestsPassed.increment();
        // marca consumer como migrado (cutover gradual)
        if (cutoverProgress.containsKey(consumer) && !cutoverProgress.get(consumer)) {
            cutoverProgress.put(consumer, true);
            emit("cutover_done:" + consumer);
        }
        return "{\"variant\":\"compatible\",\"consumer\":\"" + consumer +
                "\",\"sku\":\"" + translated.sku() + "\"," +
                "\"price\":" + translated.price() + "," +
                "\"currency\":\"" + translated.currency() + "\"," +
                "\"compatibility_proxy_hit\":true," +
                "\"cutover_done\":" + cutoverProgress.getOrDefault(consumer, false) +
                ",\"note\":\"proxy traduce {cost_usd}→{price,currency}; consumer no rompe.\"}";
    }

    private static void emit(String event) {
        String tagged = "{\"at\":\"" + Instant.now() + "\",\"event\":\"" + escape(event) + "\"}";
        for (Consumer<String> sub : cutoverBus) sub.accept(tagged);
    }

    private static String flowsJson() {
        StringBuilder sb = new StringBuilder(512);
        sb.append("{\"cutover_progress\":{");
        boolean first = true;
        for (Map.Entry<String, Boolean> e : cutoverProgress.entrySet()) {
            if (!first) sb.append(',');
            sb.append("\"").append(e.getKey()).append("\":").append(e.getValue());
            first = false;
        }
        sb.append("},\"recent_events\":[");
        synchronized (events) {
            first = true;
            for (String e : events) {
                if (!first) sb.append(',');
                sb.append(e);
                first = false;
            }
        }
        sb.append("]}");
        return sb.toString();
    }

    private static String diagnosticsJson() {
        return "{\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\"," +
                "\"bigbang\":{\"calls\":" + bigbangCalls.sum() + ",\"broken\":" + bigbangBroken.sum() + "}," +
                "\"compatible\":{\"calls\":" + compatibleCalls.sum() +
                ",\"compatibility_proxy_hits\":" + proxyHits.sum() +
                ",\"contract_tests_passed\":" + contractTestsPassed.sum() + "}," +
                "\"flows\":" + flowsJson() + "}";
    }

    private static double parseDoubleOr(String raw, double dflt) {
        try { return Double.parseDouble(raw); } catch (Exception e) { return dflt; }
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
