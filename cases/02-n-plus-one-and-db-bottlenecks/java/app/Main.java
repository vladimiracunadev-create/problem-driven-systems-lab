import com.sun.net.httpserver.HttpExchange;
import com.sun.net.httpserver.HttpServer;

import java.io.IOException;
import java.io.OutputStream;
import java.net.InetSocketAddress;
import java.net.URI;
import java.net.URLDecoder;
import java.nio.charset.StandardCharsets;
import java.sql.Connection;
import java.sql.DriverManager;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.sql.Statement;
import java.util.ArrayList;
import java.util.Collections;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.Executors;
import java.util.concurrent.atomic.LongAdder;

/**
 * Caso 02 — N+1 queries y cuellos de botella DB (stack Java).
 *
 * Substrato real: SQLite embebido vía sqlite-jdbc 3.46.1.3 (driver xerial).
 * Conexión JDBC en memoria — sin contenedor extra, sin puerto extra.
 *
 * Antes este caso simulaba N+1 con un HashMap, lo que es deshonesto: N+1
 * *es* un problema de round-trips contra una base relacional. Ahora cada
 * `db_hits` cuenta una llamada real a executeQuery() — el mismo coste que
 * pagaría una app real contra PostgreSQL/MySQL.
 *
 * Schema (unificado con Node/.NET para que las comparaciones sean justas):
 *   categories(id, name)                              24 filas
 *   customers(id, name, region, category_id)          900 filas
 *   orders(id, customer_id, total, created_at)        1500 filas
 *   order_items(id, order_id, sku, qty, price)        ~5000 filas
 */
public class Main {

    private static final String CASE_NAME = "02 - N+1 queries y cuellos de botella DB";
    private static final String STACK = "Java 21";
    private static final int PORT = Integer.parseInt(System.getenv().getOrDefault("PORT", "8080"));
    private static final int MAX_SAMPLES = 3000;

    /** Una sola conexión global a `:memory:` — si se cierra, la DB se pierde. */
    private static Connection db;

    private static final Metrics legacyMetrics = new Metrics();
    private static final Metrics optimizedMetrics = new Metrics();

    public static void main(String[] args) throws Exception {
        Class.forName("org.sqlite.JDBC");
        db = DriverManager.getConnection("jdbc:sqlite::memory:");
        initSchema();
        seedData();

        HttpServer server = HttpServer.create(new InetSocketAddress(PORT), 0);
        server.createContext("/", Main::route);
        server.setExecutor(Executors.newCachedThreadPool());
        server.start();
        System.out.println("[case02-java] listening on " + PORT);
        Runtime.getRuntime().addShutdownHook(new Thread(() -> {
            server.stop(0);
            try { if (db != null) db.close(); } catch (SQLException ignored) {}
        }));
    }

    private static void initSchema() throws SQLException {
        try (Statement st = db.createStatement()) {
            st.executeUpdate("CREATE TABLE categories (id INTEGER PRIMARY KEY, name TEXT NOT NULL)");
            st.executeUpdate("CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT NOT NULL, region TEXT NOT NULL, category_id INTEGER NOT NULL)");
            st.executeUpdate("CREATE TABLE orders (id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL, total REAL NOT NULL, created_at INTEGER NOT NULL)");
            st.executeUpdate("CREATE TABLE order_items (id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL, sku TEXT NOT NULL, qty INTEGER NOT NULL, price REAL NOT NULL)");
            st.executeUpdate("CREATE INDEX idx_items_order_id ON order_items (order_id)");
        }
    }

    private static void seedData() throws SQLException {
        long seed = 270718L;
        String[] regions = {"LATAM", "NA", "EMEA", "APAC"};
        long now = System.currentTimeMillis() / 1000L;

        db.setAutoCommit(false);
        try (PreparedStatement insCat = db.prepareStatement("INSERT INTO categories VALUES (?, ?)");
             PreparedStatement insCust = db.prepareStatement("INSERT INTO customers VALUES (?, ?, ?, ?)");
             PreparedStatement insOrd = db.prepareStatement("INSERT INTO orders VALUES (?, ?, ?, ?)");
             PreparedStatement insItem = db.prepareStatement("INSERT INTO order_items VALUES (?, ?, ?, ?, ?)")) {

            for (int i = 1; i <= 24; i++) {
                insCat.setInt(1, i);
                insCat.setString(2, "Category " + i);
                insCat.executeUpdate();
            }
            for (int i = 1; i <= 900; i++) {
                seed = (seed * 9301 + 49297) % 233280;
                insCust.setInt(1, i);
                insCust.setString(2, "Customer " + i);
                insCust.setString(3, regions[(int) (seed % regions.length)]);
                insCust.setInt(4, 1 + ((i - 1) % 24));
                insCust.executeUpdate();
            }

            int itemId = 1;
            for (int orderId = 1; orderId <= 1500; orderId++) {
                seed = (seed * 9301 + 49297) % 233280;
                int cid = 1 + (int) (seed % 900);
                seed = (seed * 9301 + 49297) % 233280;
                long createdAt = now - (seed % (120L * 86400L));
                int itemsPerOrder = 2 + (int) (seed % 4); // 2..5
                double total = 0.0;
                List<Object[]> items = new ArrayList<>(itemsPerOrder);
                for (int k = 0; k < itemsPerOrder; k++) {
                    seed = (seed * 9301 + 49297) % 233280;
                    String sku = "SKU-" + (1000 + (int) (seed % 9000));
                    int qty = 1 + (int) (seed % 8);
                    seed = (seed * 9301 + 49297) % 233280;
                    double price = Math.round((10.0 + (seed % 233280) / 233280.0 * 220.0) * 100.0) / 100.0;
                    total += qty * price;
                    items.add(new Object[]{itemId++, orderId, sku, qty, price});
                }
                insOrd.setInt(1, orderId);
                insOrd.setInt(2, cid);
                insOrd.setDouble(3, Math.round(total * 100.0) / 100.0);
                insOrd.setLong(4, createdAt);
                insOrd.executeUpdate();
                for (Object[] it : items) {
                    insItem.setInt(1, (int) it[0]);
                    insItem.setInt(2, (int) it[1]);
                    insItem.setString(3, (String) it[2]);
                    insItem.setInt(4, (int) it[3]);
                    insItem.setDouble(5, (double) it[4]);
                    insItem.executeUpdate();
                }
            }
            db.commit();
        } finally {
            db.setAutoCommit(true);
        }
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
                    body = diagnosticsSummary();
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

    /** Legacy: 1 SELECT orders + N PreparedStatement.executeQuery() items (uno por order). */
    private static String ordersLegacy(int limit) throws SQLException {
        long t0 = System.nanoTime();
        long dbHits = 0;
        List<int[]> orders = new ArrayList<>(); // {id, customer_id}
        // 1: SELECT orders
        try (PreparedStatement ps = db.prepareStatement(
                "SELECT id, customer_id FROM orders ORDER BY id ASC LIMIT ?")) {
            ps.setInt(1, limit);
            try (ResultSet rs = ps.executeQuery()) {
                dbHits++;
                while (rs.next()) {
                    orders.add(new int[]{rs.getInt(1), rs.getInt(2)});
                }
            }
        }

        StringBuilder sb = new StringBuilder(8192);
        sb.append("{\"variant\":\"legacy\",\"rows\":[");
        // N: por cada order, un SELECT items separado — el anti-patrón clásico
        // que un ORM genera al iterar sin `JOIN FETCH` o batch hint.
        try (PreparedStatement psItems = db.prepareStatement(
                "SELECT sku, qty FROM order_items WHERE order_id = ? ORDER BY id ASC")) {
            for (int i = 0; i < orders.size(); i++) {
                int[] o = orders.get(i);
                psItems.setInt(1, o[0]);
                List<Object[]> items = new ArrayList<>();
                try (ResultSet rs = psItems.executeQuery()) {
                    dbHits++;
                    while (rs.next()) {
                        items.add(new Object[]{rs.getString(1), rs.getInt(2)});
                    }
                }
                if (i > 0) sb.append(',');
                sb.append("{\"order_id\":").append(o[0])
                  .append(",\"customer_id\":").append(o[1])
                  .append(",\"item_count\":").append(items.size())
                  .append(",\"items\":[");
                for (int j = 0; j < items.size(); j++) {
                    if (j > 0) sb.append(',');
                    Object[] it = items.get(j);
                    sb.append("{\"sku\":\"").append(escape((String) it[0])).append("\",\"qty\":").append(it[1]).append('}');
                }
                sb.append("]}");
            }
        }
        double elapsedMs = round2((System.nanoTime() - t0) / 1_000_000.0);
        sb.append("],\"db_hits\":").append(dbHits)
          .append(",\"elapsed_ms\":").append(elapsedMs)
          .append(",\"note\":\"1 SELECT orders + N SELECT items (uno por order).\"}");
        return sb.toString();
    }

    /** Optimized: 1 SELECT orders + 1 SELECT items WHERE order_id IN (?, ?, ...) batch. */
    private static String ordersOptimized(int limit) throws SQLException {
        long t0 = System.nanoTime();
        long dbHits = 0;
        List<int[]> orders = new ArrayList<>(); // {id, customer_id}
        try (PreparedStatement ps = db.prepareStatement(
                "SELECT id, customer_id FROM orders ORDER BY id ASC LIMIT ?")) {
            ps.setInt(1, limit);
            try (ResultSet rs = ps.executeQuery()) {
                dbHits++;
                while (rs.next()) {
                    orders.add(new int[]{rs.getInt(1), rs.getInt(2)});
                }
            }
        }

        // batch IN(?, ?, ...) — un solo round-trip para todos los items.
        Map<Integer, List<Object[]>> itemsByOrder = new HashMap<>();
        if (!orders.isEmpty()) {
            StringBuilder ph = new StringBuilder(orders.size() * 2);
            for (int i = 0; i < orders.size(); i++) ph.append(i == 0 ? "?" : ",?");
            String sql = "SELECT order_id, sku, qty FROM order_items WHERE order_id IN (" + ph + ") ORDER BY id ASC";
            try (PreparedStatement ps = db.prepareStatement(sql)) {
                for (int i = 0; i < orders.size(); i++) ps.setInt(i + 1, orders.get(i)[0]);
                try (ResultSet rs = ps.executeQuery()) {
                    dbHits++;
                    while (rs.next()) {
                        int oid = rs.getInt(1);
                        itemsByOrder.computeIfAbsent(oid, k -> new ArrayList<>())
                                .add(new Object[]{rs.getString(2), rs.getInt(3)});
                    }
                }
            }
        }

        StringBuilder sb = new StringBuilder(8192);
        sb.append("{\"variant\":\"optimized\",\"rows\":[");
        for (int i = 0; i < orders.size(); i++) {
            int[] o = orders.get(i);
            List<Object[]> items = itemsByOrder.getOrDefault(o[0], Collections.emptyList());
            if (i > 0) sb.append(',');
            sb.append("{\"order_id\":").append(o[0])
              .append(",\"customer_id\":").append(o[1])
              .append(",\"item_count\":").append(items.size())
              .append(",\"items\":[");
            for (int j = 0; j < items.size(); j++) {
                if (j > 0) sb.append(',');
                Object[] it = items.get(j);
                sb.append("{\"sku\":\"").append(escape((String) it[0])).append("\",\"qty\":").append(it[1]).append('}');
            }
            sb.append("]}");
        }
        double elapsedMs = round2((System.nanoTime() - t0) / 1_000_000.0);
        sb.append("],\"db_hits\":").append(dbHits)
          .append(",\"elapsed_ms\":").append(elapsedMs)
          .append(",\"note\":\"1 SELECT orders + 1 SELECT items con IN(...) batch.\"}");
        return sb.toString();
    }

    private static String diagnosticsSummary() throws SQLException {
        int customers = scalarInt("SELECT COUNT(*) FROM customers");
        int categories = scalarInt("SELECT COUNT(*) FROM categories");
        int ordersCount = scalarInt("SELECT COUNT(*) FROM orders");
        int itemsCount = scalarInt("SELECT COUNT(*) FROM order_items");
        double avg = ordersCount == 0 ? 0.0 : round2(itemsCount / (double) ordersCount);
        return "{\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\"," +
                "\"customers_total\":" + customers + ",\"categories_total\":" + categories +
                ",\"orders_total\":" + ordersCount + ",\"items_total\":" + itemsCount +
                ",\"avg_items_per_order\":" + avg +
                ",\"legacy\":" + legacyMetrics.toJson("legacy") +
                ",\"optimized\":" + optimizedMetrics.toJson("optimized") + "}";
    }

    private static int scalarInt(String sql) throws SQLException {
        try (Statement st = db.createStatement(); ResultSet rs = st.executeQuery(sql)) {
            return rs.next() ? rs.getInt(1) : 0;
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
