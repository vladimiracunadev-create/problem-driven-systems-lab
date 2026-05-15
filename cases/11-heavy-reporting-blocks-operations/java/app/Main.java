import com.sun.net.httpserver.HttpExchange;
import com.sun.net.httpserver.HttpServer;

import java.io.IOException;
import java.io.OutputStream;
import java.net.InetSocketAddress;
import java.net.URI;
import java.net.URLDecoder;
import java.nio.charset.StandardCharsets;
import java.util.HashMap;
import java.util.Map;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.ThreadPoolExecutor;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.LongAdder;

/**
 * Caso 11 — Reportes pesados que bloquean operacion — stack Java.
 *
 * Legacy: report-legacy corre sincrono en el pool principal y bloquea threads
 * del mismo pool que sirve /order-write → operacion se degrada.
 * Isolated: report-isolated corre en ExecutorService separado (reporting pool),
 * el pool principal queda libre para servir /order-write con latencia normal.
 *
 * Primitivas Java distintivas:
 *   - ExecutorService dedicado para reporting (aislamiento por pool).
 *   - CompletableFuture.supplyAsync con executor explicito.
 *   - ThreadPoolExecutor.getActiveCount() y getQueue().size() para observar
 *     saturacion sin agente externo.
 */
public class Main {

    private static final String CASE_NAME = "11 - Reportes pesados que bloquean operacion";
    private static final String STACK = "Java 21";
    private static final int PORT = Integer.parseInt(System.getenv().getOrDefault("PORT", "8080"));

    // pool principal limitado a 4 threads — saturar es realista
    private static final ThreadPoolExecutor mainPool =
            (ThreadPoolExecutor) Executors.newFixedThreadPool(4);
    private static final ExecutorService reportingPool = Executors.newFixedThreadPool(2);

    private static final LongAdder legacyReports = new LongAdder();
    private static final LongAdder isolatedReports = new LongAdder();
    private static final LongAdder orderWrites = new LongAdder();
    private static final LongAdder orderWritesDegraded = new LongAdder();

    public static void main(String[] args) throws Exception {
        HttpServer server = HttpServer.create(new InetSocketAddress(PORT), 0);
        server.createContext("/", Main::route);
        server.setExecutor(mainPool); // intencional: pool acotado para mostrar saturacion
        server.start();
        System.out.println("[case11-java] listening on " + PORT);
        Runtime.getRuntime().addShutdownHook(new Thread(() -> {
            reportingPool.shutdownNow();
            server.stop(0);
        }));
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
                            "\",\"routes\":[\"/health\",\"/report-legacy?rows=200000\",\"/report-isolated?rows=200000\",\"/order-write\",\"/activity\",\"/diagnostics/summary\",\"/reset-lab\"]}";
                    break;
                case "/health":
                    body = "{\"status\":\"ok\",\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\"}";
                    break;
                case "/report-legacy":
                    body = reportLegacy(bounded(q.getOrDefault("rows", "200000"), 1000, 5_000_000));
                    legacyReports.increment();
                    break;
                case "/report-isolated":
                    body = reportIsolated(bounded(q.getOrDefault("rows", "200000"), 1000, 5_000_000));
                    isolatedReports.increment();
                    break;
                case "/order-write":
                    body = orderWrite();
                    orderWrites.increment();
                    break;
                case "/activity":
                    body = activityJson();
                    break;
                case "/diagnostics/summary":
                    body = diagnosticsJson();
                    break;
                case "/reset-lab":
                    legacyReports.reset(); isolatedReports.reset();
                    orderWrites.reset(); orderWritesDegraded.reset();
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

    /** Legacy: trabajo CPU bloquea el thread del pool principal. */
    private static String reportLegacy(int rows) {
        long t0 = System.nanoTime();
        long checksum = 0;
        for (int i = 0; i < rows; i++) {
            checksum += (i * 13L) % 7;
            if ((i & 0xFFFF) == 0) Thread.yield(); // simula trabajo serio
        }
        long elapsedMs = (System.nanoTime() - t0) / 1_000_000L;
        return "{\"variant\":\"legacy\",\"rows\":" + rows +
                ",\"checksum\":" + checksum +
                ",\"elapsed_ms\":" + elapsedMs +
                ",\"ran_on_pool\":\"main\"," +
                "\"main_pool_active\":" + mainPool.getActiveCount() +
                ",\"main_pool_queue\":" + mainPool.getQueue().size() +
                ",\"note\":\"corre en el pool que sirve /order-write; mas reportes = mas saturacion del primario.\"}";
    }

    /** Isolated: trabajo sale a reportingPool, main pool intacto. */
    private static String reportIsolated(int rows) {
        long t0 = System.nanoTime();
        CompletableFuture<Long> fut = CompletableFuture.supplyAsync(() -> {
            long checksum = 0;
            for (int i = 0; i < rows; i++) checksum += (i * 13L) % 7;
            return checksum;
        }, reportingPool);
        try {
            long checksum = fut.get(30, TimeUnit.SECONDS);
            long elapsedMs = (System.nanoTime() - t0) / 1_000_000L;
            return "{\"variant\":\"isolated\",\"rows\":" + rows +
                    ",\"checksum\":" + checksum +
                    ",\"elapsed_ms\":" + elapsedMs +
                    ",\"ran_on_pool\":\"reporting\"," +
                    "\"main_pool_active\":" + mainPool.getActiveCount() +
                    ",\"main_pool_queue\":" + mainPool.getQueue().size() +
                    ",\"note\":\"corre en reportingPool dedicado; /order-write conserva su latencia normal.\"}";
        } catch (Exception e) {
            return "{\"variant\":\"isolated\",\"status\":\"error\",\"detail\":\"" + escape(e.getMessage()) + "\"}";
        }
    }

    /** Verifica si la operacion conserva aire. */
    private static String orderWrite() {
        int activeBefore = mainPool.getActiveCount();
        long t0 = System.nanoTime();
        // simulacion de escritura corta
        try { Thread.sleep(20); } catch (InterruptedException ignored) {}
        long elapsedMs = (System.nanoTime() - t0) / 1_000_000L;
        boolean degraded = elapsedMs > 100;
        if (degraded) orderWritesDegraded.increment();
        return "{\"variant\":\"order-write\"," +
                "\"elapsed_ms\":" + elapsedMs +
                ",\"degraded\":" + degraded +
                ",\"main_pool_active_at_entry\":" + activeBefore +
                ",\"note\":\"" + (degraded
                    ? "la latencia subio por saturacion del pool principal"
                    : "operacion responde con latencia normal") + "\"}";
    }

    private static String activityJson() {
        return "{\"main_pool_active\":" + mainPool.getActiveCount() +
                ",\"main_pool_queue\":" + mainPool.getQueue().size() +
                ",\"main_pool_size\":" + mainPool.getPoolSize() +
                ",\"main_pool_max\":" + mainPool.getMaximumPoolSize() +
                ",\"order_writes\":" + orderWrites.sum() +
                ",\"order_writes_degraded\":" + orderWritesDegraded.sum() + "}";
    }

    private static String diagnosticsJson() {
        return "{\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\"," +
                "\"legacy\":{\"reports\":" + legacyReports.sum() +
                ",\"behavior\":\"bloquea thread del main pool, /order-write se degrada\"}," +
                "\"isolated\":{\"reports\":" + isolatedReports.sum() +
                ",\"behavior\":\"corre en reportingPool dedicado, /order-write intacto\"}," +
                "\"activity\":" + activityJson() + "}";
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
