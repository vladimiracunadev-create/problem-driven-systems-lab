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
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.LongAdder;

/**
 * Caso 02 — N+1 queries y cuellos de botella DB (stack Java).
 *
 * Misma logica que PHP/Python/Node: orders + items relacionados, legacy carga
 * dentro de un bucle, optimized usa batch con Map para acceso O(1).
 *
 * Primitiva Java distintiva:
 *   - HashMap<Integer, List<Item>> precomputado como "IN-batch" sin DB real
 *     (espejo de IN(...) en JDBC con PreparedStatement).
 *   - LongAdder para contadores lock-free.
 *
 * Datos en memoria — no requiere PostgreSQL.
 */
public class Main {

    private static final String CASE_NAME = "02 - N+1 queries y cuellos de botella DB";
    private static final String STACK = "Java 21";
    private static final int PORT = Integer.parseInt(System.getenv().getOrDefault("PORT", "8080"));
    private static final int MAX_SAMPLES = 3000;

    private static final List<Order> orders = new ArrayList<>();
    private static final Map<Integer, List<Item>> itemsByOrderId = new HashMap<>();
    private static final List<Item> allItems = new ArrayList<>();

    private static final Metrics legacyMetrics = new Metrics();
    private static final Metrics optimizedMetrics = new Metrics();

    public static void main(String[] args) throws Exception {
        seedData();
        HttpServer server = HttpServer.create(new InetSocketAddress(PORT), 0);
        server.createContext("/", Main::route);
        server.setExecutor(Executors.newCachedThreadPool());
        server.start();
        System.out.println("[case02-java] listening on " + PORT);
        Runtime.getRuntime().addShutdownHook(new Thread(() -> server.stop(0)));
    }

    private static void route(HttpExchange ex) throws IOException {
        long t0 = System.nanoTime();
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
                    body = "{\"case\":\"" + CASE_NAME + "\",\"stack\":\"" + STACK +
                            "\",\"routes\":[\"/health\",\"/orders-legacy?limit=20\",\"/orders-optimized?limit=20\",\"/diagnostics/summary\",\"/metrics\",\"/reset-lab\"]}";
                    break;
                case "/health":
                    body = "{\"status\":\"ok\",\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\"}";
                    break;
                case "/orders-legacy":
                    body = ordersLegacy(bounded(q.getOrDefault("limit", "20"), 1, 200));
                    tracked = legacyMetrics;
                    break;
                case "/orders-optimized":
                    body = ordersOptimized(bounded(q.getOrDefault("limit", "20"), 1, 200));
                    tracked = optimizedMetrics;
                    break;
                case "/diagnostics/summary":
                    body = "{\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME +
                            "\",\"orders_total\":" + orders.size() +
                            ",\"items_total\":" + allItems.size() +
                            ",\"avg_items_per_order\":" + round2(allItems.size() / (double) orders.size()) +
                            ",\"legacy\":" + legacyMetrics.toJson("legacy") +
                            ",\"optimized\":" + optimizedMetrics.toJson("optimized") + "}";
                    break;
                case "/metrics":
                    body = "{\"legacy\":" + legacyMetrics.toJson("legacy") +
                            ",\"optimized\":" + optimizedMetrics.toJson("optimized") + "}";
                    break;
                case "/reset-lab":
                    legacyMetrics.reset();
                    optimizedMetrics.reset();
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

        double elapsedMs = round2((System.nanoTime() - t0) / 1_000_000.0);
        if (tracked != null) tracked.record(elapsedMs);

        byte[] out = body.getBytes(StandardCharsets.UTF_8);
        ex.getResponseHeaders().add("Content-Type", "application/json; charset=utf-8");
        ex.sendResponseHeaders(status, out.length);
        try (OutputStream os = ex.getResponseBody()) { os.write(out); }
    }

    /** Legacy: 1 query orders + N queries items (uno por order). */
    private static String ordersLegacy(int limit) {
        long t0 = System.nanoTime();
        long dbHits = 1; // orders query
        int take = Math.min(limit, orders.size());
        StringBuilder sb = new StringBuilder(8192);
        sb.append("{\"variant\":\"legacy\",\"rows\":[");
        for (int i = 0; i < take; i++) {
            Order o = orders.get(i);
            List<Item> items = lookupItemsOneByOne(o.id); // N+1
            dbHits++;
            sleepMicros(900); // costo de roundtrip
            if (i > 0) sb.append(',');
            sb.append("{\"order_id\":").append(o.id)
              .append(",\"customer_id\":").append(o.customerId)
              .append(",\"item_count\":").append(items.size())
              .append(",\"items\":[");
            for (int j = 0; j < items.size(); j++) {
                if (j > 0) sb.append(',');
                Item it = items.get(j);
                sb.append("{\"sku\":\"").append(it.sku).append("\",\"qty\":").append(it.qty).append('}');
            }
            sb.append("]}");
        }
        double elapsedMs = round2((System.nanoTime() - t0) / 1_000_000.0);
        sb.append("],\"db_hits\":").append(dbHits)
          .append(",\"elapsed_ms\":").append(elapsedMs)
          .append(",\"note\":\"1 query orders + N queries items dentro de bucle.\"}");
        return sb.toString();
    }

    /** Optimized: 1 query orders + 1 IN-batch items, ensamblado en Java con Map O(1). */
    private static String ordersOptimized(int limit) {
        long t0 = System.nanoTime();
        long dbHits = 1; // orders
        int take = Math.min(limit, orders.size());

        // batch IN(...) simulado: 1 lookup que devuelve todos los items
        List<Integer> ids = new ArrayList<>();
        for (int i = 0; i < take; i++) ids.add(orders.get(i).id);
        Map<Integer, List<Item>> batch = new HashMap<>();
        for (Integer id : ids) batch.put(id, itemsByOrderId.getOrDefault(id, Collections.emptyList()));
        dbHits++;
        sleepMicros(700); // un solo roundtrip

        StringBuilder sb = new StringBuilder(8192);
        sb.append("{\"variant\":\"optimized\",\"rows\":[");
        for (int i = 0; i < take; i++) {
            Order o = orders.get(i);
            List<Item> items = batch.get(o.id);
            if (i > 0) sb.append(',');
            sb.append("{\"order_id\":").append(o.id)
              .append(",\"customer_id\":").append(o.customerId)
              .append(",\"item_count\":").append(items.size())
              .append(",\"items\":[");
            for (int j = 0; j < items.size(); j++) {
                if (j > 0) sb.append(',');
                Item it = items.get(j);
                sb.append("{\"sku\":\"").append(it.sku).append("\",\"qty\":").append(it.qty).append('}');
            }
            sb.append("]}");
        }
        double elapsedMs = round2((System.nanoTime() - t0) / 1_000_000.0);
        sb.append("],\"db_hits\":").append(dbHits)
          .append(",\"elapsed_ms\":").append(elapsedMs)
          .append(",\"note\":\"1 query orders + 1 batch items (IN-style) + ensamblado en memoria.\"}");
        return sb.toString();
    }

    private static void seedData() {
        long seed = 270718L;
        for (int i = 1; i <= 600; i++) {
            seed = (seed * 9301 + 49297) % 233280;
            int cid = 1 + (int) (seed % 500);
            orders.add(new Order(i, cid));
            int itemsPerOrder = 2 + (int) (seed % 5);
            List<Item> list = new ArrayList<>();
            for (int j = 1; j <= itemsPerOrder; j++) {
                seed = (seed * 9301 + 49297) % 233280;
                Item it = new Item("SKU-" + (1000 + (int) (seed % 9000)), 1 + (int) (seed % 8));
                list.add(it);
                allItems.add(it);
            }
            itemsByOrderId.put(i, list);
        }
    }

    private static List<Item> lookupItemsOneByOne(int orderId) {
        // simula busqueda lineal sobre allItems (peor caso del N+1)
        // en la practica seria un SELECT * FROM items WHERE order_id=?
        return itemsByOrderId.getOrDefault(orderId, Collections.emptyList());
    }

    private record Order(int id, int customerId) {}
    private record Item(String sku, int qty) {}

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
            return "{\"label\":\"" + label + "\",\"requests\":" + requests.sum() +
                    ",\"sample_count\":" + snap.size() + ",\"avg_ms\":" + avg(snap) +
                    ",\"p95_ms\":" + percentile(snap, 95) + ",\"p99_ms\":" + percentile(snap, 99) + "}";
        }
    }

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
