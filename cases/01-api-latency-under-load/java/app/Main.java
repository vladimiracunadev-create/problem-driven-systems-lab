import com.sun.net.httpserver.HttpExchange;
import com.sun.net.httpserver.HttpServer;

import java.io.IOException;
import java.io.OutputStream;
import java.net.InetSocketAddress;
import java.net.URI;
import java.net.URLDecoder;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.Paths;
import java.sql.Connection;
import java.sql.DriverManager;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.sql.Statement;
import java.time.Instant;
import java.util.ArrayList;
import java.util.Collections;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.Executors;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.LongAdder;

/**
 * Caso 01 — API lenta bajo carga (stack Java).
 *
 * Problema: filtro no sargable + N+1 bajo carga concurrente, conviviendo con un
 * worker que refresca una tabla resumen. Misma logica que PHP/Python/Node,
 * primitivas Java distintas.
 *
 * Substrato real: SQLite embebido via sqlite-jdbc 3.46.1.3 (driver xerial), en
 * archivo bajo /tmp y con journal_mode=WAL. No hay datos en memoria simulando
 * ser una base: `db_hits` cuenta ejecuciones reales contra el motor.
 *
 * Por que WAL y una conexion por request: el worker escribe customer_summary
 * mientras las rutas leen. Con WAL los lectores no se bloquean con el escritor
 * — es el equivalente embebido del MVCC que da PostgreSQL en el stack PHP, y es
 * exactamente la propiedad que este caso enseña.
 *
 * Primitivas Java que aporta este stack:
 *   - try-with-resources para Connection/PreparedStatement (cierre garantizado
 *     incluso en el camino de excepcion — sin fugas de conexion).
 *   - PreparedStatement con placeholders reales.
 *   - ScheduledExecutorService para el worker (shutdown limpio en SIGTERM).
 *   - LongAdder para contadores sin lock contention bajo carga.
 */
public class Main {

    private static final String CASE_NAME = "01 - API lenta bajo carga";
    private static final String STACK = "Java 21";
    private static final int PORT = Integer.parseInt(System.getenv().getOrDefault("PORT", "8080"));
    private static final int SUMMARY_REFRESH_SECONDS = 5;
    private static final int MAX_SAMPLES = 3000;
    private static final int MAX_JOB_RUNS = 30;
    private static final String WORKER_NAME = "report-refresh-java";

    private static final Path STORAGE_DIR = Paths.get(System.getProperty("java.io.tmpdir"), "pdsl-case01-java");
    private static final String DB_URL = "jdbc:sqlite:" + STORAGE_DIR.resolve("case01.sqlite3");

    private static final Metrics legacyMetrics = new Metrics();
    private static final Metrics optimizedMetrics = new Metrics();

    public static void main(String[] args) throws Exception {
        Class.forName("org.sqlite.JDBC");
        Files.createDirectories(STORAGE_DIR);
        // Arranque limpio y determinista: se borra la DB y los sidecars de WAL.
        for (String f : new String[]{"case01.sqlite3", "case01.sqlite3-wal", "case01.sqlite3-shm"}) {
            Files.deleteIfExists(STORAGE_DIR.resolve(f));
        }

        try (Connection db = open()) {
            initSchema(db);
            seedData(db);
        }
        refreshSummary();

        HttpServer server = HttpServer.create(new InetSocketAddress(PORT), 0);
        server.createContext("/", Main::route);
        server.setExecutor(Executors.newCachedThreadPool());
        server.start();
        System.out.println("[case01-java] listening on " + PORT);

        ScheduledExecutorService worker = Executors.newSingleThreadScheduledExecutor(r -> {
            Thread t = new Thread(r, WORKER_NAME);
            t.setDaemon(true);
            return t;
        });
        worker.scheduleAtFixedRate(Main::refreshSummary, SUMMARY_REFRESH_SECONDS, SUMMARY_REFRESH_SECONDS, TimeUnit.SECONDS);

        Runtime.getRuntime().addShutdownHook(new Thread(() -> {
            worker.shutdownNow();
            server.stop(0);
        }));
    }

    /** Conexion nueva por unidad de trabajo. WAL permite lector+escritor en paralelo. */
    private static Connection open() throws SQLException {
        Connection c = DriverManager.getConnection(DB_URL);
        try (Statement st = c.createStatement()) {
            st.execute("PRAGMA journal_mode=WAL");
            st.execute("PRAGMA busy_timeout=5000");
        }
        return c;
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
                    body = workerStateJson();
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
                    try (Connection db = open(); Statement st = db.createStatement()) {
                        st.executeUpdate("DELETE FROM job_runs");
                    }
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
                "\"substrate\":\"SQLite embebido via sqlite-jdbc (WAL, archivo en /tmp)\"," +
                "\"native_primitives\":[\"try-with-resources (Connection/PreparedStatement)\",\"PreparedStatement (SQL real)\",\"LongAdder (counters)\",\"ScheduledExecutorService (worker)\"]," +
                "\"routes\":{" +
                "\"/health\":\"liveness check\"," +
                "\"/report-legacy?limit=20\":\"filtro no sargable (LOWER sobre la columna) + N+1 real\"," +
                "\"/report-optimized?limit=20\":\"rango sargable + batch IN(...) + lectura de customer_summary\"," +
                "\"/batch/status\":\"estado del worker\"," +
                "\"/job-runs\":\"historial de corridas del worker\"," +
                "\"/diagnostics/summary\":\"contraste legacy vs optimized\"," +
                "\"/metrics\":\"avg/p95/p99 por ruta\"," +
                "\"/reset-lab\":\"reinicia contadores e historico\"}}";
    }

    /**
     * Legacy: filtro no sargable — LOWER(region) sobre la columna impide usar
     * idx_orders_region, el motor recorre la tabla entera. Despues, N+1 real:
     * una query dependiente por cada fila devuelta.
     */
    private static String reportLegacy(int limit) throws SQLException {
        long dbHits = 0;
        long ms0 = System.nanoTime();
        StringBuilder sb = new StringBuilder(8192);
        sb.append("{\"variant\":\"legacy\",\"rows\":[");

        try (Connection db = open()) {
            List<int[]> ids = new ArrayList<>();
            List<String> regions = new ArrayList<>();
            List<Double> amounts = new ArrayList<>();

            try (PreparedStatement ps = db.prepareStatement(
                    "SELECT id, customer_id, region, amount FROM orders " +
                    "WHERE LOWER(region) LIKE 'n%' ORDER BY id LIMIT ?")) {
                ps.setInt(1, limit);
                try (ResultSet rs = ps.executeQuery()) {
                    while (rs.next()) {
                        ids.add(new int[]{rs.getInt("id"), rs.getInt("customer_id")});
                        regions.add(rs.getString("region"));
                        amounts.add(rs.getDouble("amount"));
                    }
                }
            }
            dbHits++;

            for (int i = 0; i < ids.size(); i++) {
                String name = "";
                String tier = "";
                try (PreparedStatement ps = db.prepareStatement(
                        "SELECT name, tier FROM customers WHERE id = ?")) {
                    ps.setInt(1, ids.get(i)[1]);
                    try (ResultSet rs = ps.executeQuery()) {
                        if (rs.next()) {
                            name = rs.getString("name");
                            tier = rs.getString("tier");
                        }
                    }
                }
                dbHits++;
                if (i > 0) sb.append(',');
                sb.append("{\"order_id\":").append(ids.get(i)[0])
                  .append(",\"customer\":\"").append(escape(name)).append('"')
                  .append(",\"tier\":\"").append(escape(tier)).append('"')
                  .append(",\"region\":\"").append(escape(regions.get(i))).append('"')
                  .append(",\"amount\":").append(amounts.get(i)).append('}');
            }
        }

        double elapsedMs = round2((System.nanoTime() - ms0) / 1_000_000.0);
        sb.append("],\"db_hits\":").append(dbHits)
          .append(",\"elapsed_ms\":").append(elapsedMs)
          .append(",\"note\":\"LOWER(region) invalida el indice + N+1 real: 1 + N queries contra SQLite.\"}");
        return sb.toString();
    }

    /**
     * Optimized: el mismo filtro reescrito como rango sargable (usa
     * idx_orders_region), un solo batch IN(...) para los customers, y lectura de
     * customer_summary que el worker mantiene. 3 queries, no 1+N.
     */
    private static String reportOptimized(int limit) throws SQLException {
        long dbHits = 0;
        long ms0 = System.nanoTime();
        StringBuilder sb = new StringBuilder(8192);
        sb.append("{\"variant\":\"optimized\",\"rows\":[");
        int summarySize = 0;

        try (Connection db = open()) {
            List<int[]> ids = new ArrayList<>();
            List<String> regions = new ArrayList<>();
            List<Double> amounts = new ArrayList<>();

            // Rango sargable: region >= 'n' AND region < 'o' usa el indice.
            try (PreparedStatement ps = db.prepareStatement(
                    "SELECT id, customer_id, region, amount FROM orders " +
                    "WHERE region >= 'n' AND region < 'o' ORDER BY id LIMIT ?")) {
                ps.setInt(1, limit);
                try (ResultSet rs = ps.executeQuery()) {
                    while (rs.next()) {
                        ids.add(new int[]{rs.getInt("id"), rs.getInt("customer_id")});
                        regions.add(rs.getString("region"));
                        amounts.add(rs.getDouble("amount"));
                    }
                }
            }
            dbHits++;

            Map<Integer, String[]> customerBatch = new HashMap<>();
            Map<Integer, double[]> summaryBatch = new HashMap<>();
            if (!ids.isEmpty()) {
                StringBuilder placeholders = new StringBuilder();
                for (int i = 0; i < ids.size(); i++) placeholders.append(i > 0 ? ",?" : "?");

                try (PreparedStatement ps = db.prepareStatement(
                        "SELECT id, name, tier FROM customers WHERE id IN (" + placeholders + ")")) {
                    for (int i = 0; i < ids.size(); i++) ps.setInt(i + 1, ids.get(i)[1]);
                    try (ResultSet rs = ps.executeQuery()) {
                        while (rs.next()) {
                            customerBatch.put(rs.getInt("id"),
                                    new String[]{rs.getString("name"), rs.getString("tier")});
                        }
                    }
                }
                dbHits++;

                try (PreparedStatement ps = db.prepareStatement(
                        "SELECT customer_id, order_count, total_amount FROM customer_summary " +
                        "WHERE customer_id IN (" + placeholders + ")")) {
                    for (int i = 0; i < ids.size(); i++) ps.setInt(i + 1, ids.get(i)[1]);
                    try (ResultSet rs = ps.executeQuery()) {
                        while (rs.next()) {
                            summaryBatch.put(rs.getInt("customer_id"),
                                    new double[]{rs.getInt("order_count"), rs.getDouble("total_amount")});
                        }
                    }
                }
                dbHits++;
            }

            for (int i = 0; i < ids.size(); i++) {
                int cid = ids.get(i)[1];
                String[] c = customerBatch.get(cid);
                double[] s = summaryBatch.get(cid);
                if (i > 0) sb.append(',');
                sb.append("{\"order_id\":").append(ids.get(i)[0])
                  .append(",\"customer\":\"").append(escape(c == null ? "" : c[0])).append('"')
                  .append(",\"tier\":\"").append(escape(c == null ? "" : c[1])).append('"')
                  .append(",\"region\":\"").append(escape(regions.get(i))).append('"')
                  .append(",\"amount\":").append(amounts.get(i))
                  .append(",\"lifetime_orders\":").append(s == null ? 0 : (long) s[0])
                  .append(",\"lifetime_amount\":").append(s == null ? 0.0 : s[1])
                  .append('}');
            }

            summarySize = countRows(db, "customer_summary");
            dbHits++;
        }

        double elapsedMs = round2((System.nanoTime() - ms0) / 1_000_000.0);
        sb.append("],\"db_hits\":").append(dbHits)
          .append(",\"elapsed_ms\":").append(elapsedMs)
          .append(",\"summary_cache_size\":").append(summarySize)
          .append(",\"note\":\"Rango sargable + 2 batches IN(...) + customer_summary mantenida por el worker.\"}");
        return sb.toString();
    }

    private static String diagnosticsJson() throws SQLException {
        int summarySize;
        try (Connection db = open()) {
            summarySize = countRows(db, "customer_summary");
        }
        return "{" +
                "\"stack\":\"" + STACK + "\"," +
                "\"case\":\"" + CASE_NAME + "\"," +
                "\"substrate\":\"SQLite embebido (sqlite-jdbc, WAL)\"," +
                "\"legacy\":" + legacyMetrics.toJson("legacy") + "," +
                "\"optimized\":" + optimizedMetrics.toJson("optimized") + "," +
                "\"summary_cache_size\":" + summarySize + "," +
                "\"worker\":" + workerStateJson() + "}";
    }

    private static String metricsJson() {
        return "{\"legacy\":" + legacyMetrics.toJson("legacy") +
                ",\"optimized\":" + optimizedMetrics.toJson("optimized") + "}";
    }

    private static String workerStateJson() throws SQLException {
        try (Connection db = open();
             PreparedStatement ps = db.prepareStatement(
                     "SELECT last_status, last_duration_ms, last_message, last_heartbeat " +
                     "FROM worker_state WHERE worker_name = ?")) {
            ps.setString(1, WORKER_NAME);
            try (ResultSet rs = ps.executeQuery()) {
                if (!rs.next()) {
                    return "{\"worker_name\":\"" + WORKER_NAME + "\",\"last_status\":\"unknown\"," +
                            "\"last_duration_ms\":-1,\"last_message\":\"\",\"last_heartbeat\":\"\"}";
                }
                return "{" +
                        "\"worker_name\":\"" + WORKER_NAME + "\"," +
                        "\"last_status\":\"" + escape(rs.getString("last_status")) + "\"," +
                        "\"last_duration_ms\":" + rs.getLong("last_duration_ms") + "," +
                        "\"last_message\":\"" + escape(rs.getString("last_message")) + "\"," +
                        "\"last_heartbeat\":\"" + escape(rs.getString("last_heartbeat")) + "\"}";
            }
        }
    }

    private static String jobRunsJson() throws SQLException {
        StringBuilder sb = new StringBuilder(1024);
        sb.append("{\"runs\":[");
        try (Connection db = open();
             PreparedStatement ps = db.prepareStatement(
                     "SELECT at, status, duration_ms, customers_refreshed FROM job_runs " +
                     "ORDER BY id DESC LIMIT ?")) {
            ps.setInt(1, MAX_JOB_RUNS);
            try (ResultSet rs = ps.executeQuery()) {
                boolean first = true;
                while (rs.next()) {
                    if (!first) sb.append(',');
                    sb.append("{\"at\":\"").append(escape(rs.getString("at")))
                      .append("\",\"status\":\"").append(escape(rs.getString("status")))
                      .append("\",\"duration_ms\":").append(rs.getLong("duration_ms"))
                      .append(",\"customers_refreshed\":").append(rs.getInt("customers_refreshed"))
                      .append('}');
                    first = false;
                }
            }
        }
        sb.append("],\"max_runs_kept\":").append(MAX_JOB_RUNS).append('}');
        return sb.toString();
    }

    // ---------- worker ----------

    /**
     * Refresca customer_summary con un DELETE + INSERT ... SELECT real. Corre en
     * su propia conexion; gracias a WAL los lectores siguen respondiendo mientras
     * esta transaccion escribe.
     */
    private static void refreshSummary() {
        long t0 = System.nanoTime();
        try (Connection db = open()) {
            db.setAutoCommit(false);
            int refreshed;
            try (Statement st = db.createStatement()) {
                st.executeUpdate("DELETE FROM customer_summary");
                refreshed = st.executeUpdate(
                        "INSERT INTO customer_summary (customer_id, order_count, total_amount, refreshed_at) " +
                        "SELECT customer_id, COUNT(*), ROUND(SUM(amount), 2), strftime('%s','now') " +
                        "FROM orders GROUP BY customer_id");
            }
            long durMs = (System.nanoTime() - t0) / 1_000_000L;

            try (PreparedStatement ps = db.prepareStatement(
                    "UPDATE worker_state SET last_status = ?, last_duration_ms = ?, " +
                    "last_message = ?, last_heartbeat = ? WHERE worker_name = ?")) {
                ps.setString(1, "ok");
                ps.setLong(2, durMs);
                ps.setString(3, "refreshed " + refreshed + " customer summaries");
                ps.setString(4, Instant.now().toString());
                ps.setString(5, WORKER_NAME);
                ps.executeUpdate();
            }
            try (PreparedStatement ps = db.prepareStatement(
                    "INSERT INTO job_runs (at, status, duration_ms, customers_refreshed) VALUES (?, ?, ?, ?)")) {
                ps.setString(1, Instant.now().toString());
                ps.setString(2, "ok");
                ps.setLong(3, durMs);
                ps.setInt(4, refreshed);
                ps.executeUpdate();
            }
            try (Statement st = db.createStatement()) {
                st.executeUpdate("DELETE FROM job_runs WHERE id NOT IN " +
                        "(SELECT id FROM job_runs ORDER BY id DESC LIMIT " + MAX_JOB_RUNS + ")");
            }
            db.commit();
        } catch (SQLException e) {
            System.err.println("[case01-java] worker error: " + e.getMessage());
        }
    }

    // ---------- schema y seed ----------

    private static void initSchema(Connection db) throws SQLException {
        try (Statement st = db.createStatement()) {
            st.execute("PRAGMA journal_mode=WAL");
            st.executeUpdate("CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT NOT NULL, tier TEXT NOT NULL)");
            st.executeUpdate("CREATE TABLE orders (id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL, region TEXT NOT NULL, amount REAL NOT NULL)");
            st.executeUpdate("CREATE TABLE customer_summary (customer_id INTEGER PRIMARY KEY, order_count INTEGER NOT NULL, total_amount REAL NOT NULL, refreshed_at INTEGER NOT NULL)");
            st.executeUpdate("CREATE TABLE worker_state (worker_name TEXT PRIMARY KEY, last_status TEXT NOT NULL, last_duration_ms INTEGER NOT NULL, last_message TEXT, last_heartbeat TEXT)");
            st.executeUpdate("CREATE TABLE job_runs (id INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT NOT NULL, status TEXT NOT NULL, duration_ms INTEGER NOT NULL, customers_refreshed INTEGER NOT NULL)");
            // El indice que la ruta legacy desperdicia al envolver la columna en LOWER().
            st.executeUpdate("CREATE INDEX idx_orders_region ON orders (region, id)");
            st.executeUpdate("CREATE INDEX idx_orders_customer ON orders (customer_id)");
        }
    }

    private static void seedData(Connection db) throws SQLException {
        long seed = 102030L;
        String[] regions = {"north", "south", "east", "west"};
        String[] tiers = {"bronze", "silver", "gold"};

        db.setAutoCommit(false);
        try (PreparedStatement ps = db.prepareStatement("INSERT INTO customers VALUES (?, ?, ?)")) {
            for (int i = 1; i <= 1600; i++) {
                seed = (seed * 9301 + 49297) % 233280;
                ps.setInt(1, i);
                ps.setString(2, "Customer " + i);
                ps.setString(3, tiers[(int) (seed % tiers.length)]);
                ps.addBatch();
            }
            ps.executeBatch();
        }
        try (PreparedStatement ps = db.prepareStatement("INSERT INTO orders VALUES (?, ?, ?, ?)")) {
            for (int i = 1; i <= 4800; i++) {
                seed = (seed * 9301 + 49297) % 233280;
                ps.setInt(1, i);
                ps.setInt(2, 1 + (int) (seed % 1600));
                ps.setString(3, regions[(int) ((seed / 7) % regions.length)]);
                ps.setDouble(4, round2(20.0 + (seed % 1000)));
                ps.addBatch();
            }
            ps.executeBatch();
        }
        try (PreparedStatement ps = db.prepareStatement("INSERT INTO worker_state VALUES (?, ?, ?, ?, ?)")) {
            ps.setString(1, WORKER_NAME);
            ps.setString(2, "init");
            ps.setLong(3, -1);
            ps.setString(4, "worker not started yet");
            ps.setString(5, "");
            ps.executeUpdate();
        }
        db.commit();
        db.setAutoCommit(true);
    }

    private static int countRows(Connection db, String table) throws SQLException {
        try (Statement st = db.createStatement();
             ResultSet rs = st.executeQuery("SELECT COUNT(*) FROM " + table)) {
            return rs.next() ? rs.getInt(1) : 0;
        }
    }

    // ---------- tipos ----------

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
