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
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.Executors;
import java.util.concurrent.atomic.LongAdder;

/**
 * Caso 05 — Presion de memoria y fugas de recursos (stack Java).
 *
 * Legacy: arraylist estatico crece sin limite por request → leak real cross-request.
 * Optimized: cache LRU acotada con LinkedHashMap removeEldestEntry → memoria estable.
 *
 * Primitivas Java distintivas:
 *   - Runtime.getRuntime().totalMemory()/freeMemory() para medir presion real.
 *   - LinkedHashMap con removeEldestEntry como LRU primitivo built-in.
 *   - System.gc() opcional para forzar comparacion antes/despues.
 */
public class Main {

    private static final String CASE_NAME = "05 - Presion de memoria y fugas de recursos";
    private static final String STACK = "Java 21";
    private static final int PORT = Integer.parseInt(System.getenv().getOrDefault("PORT", "8080"));
    private static final int OPTIMIZED_CAP = 1000;

    /** Leak real: lista estatica que crece para siempre. */
    private static final List<byte[]> legacyAccumulator = Collections.synchronizedList(new ArrayList<>());

    /** Optimized: LRU acotada built-in. */
    private static final Map<Integer, byte[]> optimizedCache = Collections.synchronizedMap(
            new LinkedHashMap<>(OPTIMIZED_CAP, 0.75f, true) {
                @Override
                protected boolean removeEldestEntry(Map.Entry<Integer, byte[]> eldest) {
                    return size() > OPTIMIZED_CAP;
                }
            });

    private static final LongAdder legacyRequests = new LongAdder();
    private static final LongAdder optimizedRequests = new LongAdder();
    private static final LongAdder optimizedEvictions = new LongAdder();

    public static void main(String[] args) throws Exception {
        HttpServer server = HttpServer.create(new InetSocketAddress(PORT), 0);
        server.createContext("/", Main::route);
        server.setExecutor(Executors.newCachedThreadPool());
        server.start();
        System.out.println("[case05-java] listening on " + PORT);
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
                            "\",\"routes\":[\"/health\",\"/batch-legacy?size_kb=64\",\"/batch-optimized?size_kb=64\",\"/state\",\"/diagnostics/summary\",\"/reset-lab\"]}";
                    break;
                case "/health":
                    body = "{\"status\":\"ok\",\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\"}";
                    break;
                case "/batch-legacy":
                    body = batchLegacy(bounded(q.getOrDefault("size_kb", "64"), 1, 4096));
                    legacyRequests.increment();
                    break;
                case "/batch-optimized":
                    body = batchOptimized(bounded(q.getOrDefault("size_kb", "64"), 1, 4096));
                    optimizedRequests.increment();
                    break;
                case "/state":
                    body = stateJson();
                    break;
                case "/diagnostics/summary":
                    body = diagnosticsJson();
                    break;
                case "/reset-lab":
                    synchronized (legacyAccumulator) { legacyAccumulator.clear(); }
                    synchronized (optimizedCache) { optimizedCache.clear(); }
                    legacyRequests.reset(); optimizedRequests.reset(); optimizedEvictions.reset();
                    System.gc();
                    body = "{\"status\":\"reset\",\"note\":\"acumuladores limpios + System.gc() invocado.\"}";
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

    private static String batchLegacy(int sizeKb) {
        byte[] payload = new byte[sizeKb * 1024];
        for (int i = 0; i < payload.length; i++) payload[i] = (byte) (i & 0xff);
        legacyAccumulator.add(payload); // nunca se libera
        return "{\"variant\":\"legacy\",\"appended_kb\":" + sizeKb +
                ",\"retained_count\":" + legacyAccumulator.size() +
                ",\"retained_kb_estimate\":" + (legacyAccumulator.size() * sizeKb) +
                ",\"note\":\"se acumula en lista estatica sin eviccion → fuga real cross-request.\"}";
    }

    private static String batchOptimized(int sizeKb) {
        int beforeSize = optimizedCache.size();
        byte[] payload = new byte[sizeKb * 1024];
        for (int i = 0; i < payload.length; i++) payload[i] = (byte) (i & 0xff);
        int key = (int) (System.nanoTime() & 0x7FFFFFFF);
        optimizedCache.put(key, payload);
        int afterSize = optimizedCache.size();
        if (afterSize < beforeSize + 1) optimizedEvictions.increment();
        return "{\"variant\":\"optimized\",\"appended_kb\":" + sizeKb +
                ",\"retained_count\":" + afterSize +
                ",\"cap\":" + OPTIMIZED_CAP +
                ",\"evictions_total\":" + optimizedEvictions.sum() +
                ",\"note\":\"LinkedHashMap removeEldestEntry mantiene cap fijo, memoria estable.\"}";
    }

    private static String stateJson() {
        Runtime rt = Runtime.getRuntime();
        long totalMb = rt.totalMemory() / (1024 * 1024);
        long freeMb = rt.freeMemory() / (1024 * 1024);
        long usedMb = totalMb - freeMb;
        long maxMb = rt.maxMemory() / (1024 * 1024);
        return "{\"stack\":\"" + STACK + "\"," +
                "\"heap_used_mb\":" + usedMb + ",\"heap_total_mb\":" + totalMb +
                ",\"heap_max_mb\":" + maxMb + ",\"heap_free_mb\":" + freeMb +
                ",\"legacy_retained_count\":" + legacyAccumulator.size() +
                ",\"optimized_retained_count\":" + optimizedCache.size() +
                ",\"optimized_cap\":" + OPTIMIZED_CAP + "}";
    }

    private static String diagnosticsJson() {
        return "{\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\"," +
                "\"legacy\":{\"requests\":" + legacyRequests.sum() +
                ",\"retained_count\":" + legacyAccumulator.size() +
                ",\"behavior\":\"sin eviccion, leak monoticamente creciente\"}," +
                "\"optimized\":{\"requests\":" + optimizedRequests.sum() +
                ",\"retained_count\":" + optimizedCache.size() +
                ",\"evictions\":" + optimizedEvictions.sum() +
                ",\"cap\":" + OPTIMIZED_CAP +
                ",\"behavior\":\"LRU built-in con cap fijo\"}," +
                "\"runtime\":" + stateJson() + "}";
    }

    private static int bounded(String raw, int min, int max) {
        try {
            int n = Integer.parseInt(raw);
            return Math.max(min, Math.min(n, max));
        } catch (NumberFormatException e) { return min; }
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
