import com.sun.net.httpserver.HttpExchange;
import com.sun.net.httpserver.HttpServer;

import java.io.IOException;
import java.io.OutputStream;
import java.net.InetSocketAddress;
import java.net.URI;
import java.net.URLDecoder;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.CyclicBarrier;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.locks.ReentrantReadWriteLock;
import java.util.concurrent.atomic.AtomicLong;

/**
 * Caso 17 — Migracion de esquema sin downtime — stack Java 21.
 *
 * Blocking: un `ALTER TABLE` toma el lock exclusivo durante toda la migracion.
 * Los lectores esperan, y los que tienen timeout fallan.
 * Expand-contract: cuatro fases, y el lock se toma y se suelta en cada lote.
 *
 * Primitiva Java distintiva:
 *   `ReentrantReadWriteLock`, con dos detalles que ningun otro stack del lab
 *   tiene juntos:
 *
 *   1. **`tryLock(timeout, unit)` en el lado de lectura.** Un lector real no
 *      espera para siempre: tiene un deadline y devuelve 503 si no lo alcanza.
 *      Es la diferencia entre "la app esta lenta" y "la app no responde".
 *
 *   2. **El constructor `new ReentrantReadWriteLock(true)` — el modo justo.**
 *      Por defecto el lock NO es justo, y con trafico de lectura constante el
 *      escritor puede no entrar nunca. En una migracion eso significa que el
 *      `ALTER TABLE` se queda esperando indefinidamente mientras la app
 *      funciona perfecto — el peor modo de fallar, porque nada se ve roto.
 *
 *   Python no tiene read-write lock en la stdlib y hay que escribirlo; Java lo
 *   trae con la politica de equidad como parametro del constructor. Ese
 *   parametro es exactamente el problema que Python resuelve a mano con una
 *   bandera de escritor esperando.
 *
 * El tiempo de migracion es un `sleep`: un ALTER TABLE se demora esperando I/O
 * del motor, no quemando CPU del proceso de la app.
 */
public class Main {

    private static final String CASE_NAME = "17 - Migracion de esquema sin downtime";
    private static final String STACK = System.getenv().getOrDefault("APP_STACK", "Java 21");
    private static final int PORT = Integer.parseInt(System.getenv().getOrDefault("PORT", "8080"));

    private static final long READ_TIMEOUT_MS = 120;

    /** `true` = modo justo: sin esto, el escritor puede no entrar nunca. */
    private static final ReentrantReadWriteLock rwLock = new ReentrantReadWriteLock(true);

    private static final Map<String, Object> table = new ConcurrentHashMap<>();
    private static volatile boolean readFromNewColumn = false;
    private static volatile String phase = "idle";

    private static final class Slot {
        final AtomicLong runs = new AtomicLong();
        final AtomicLong lockHeldMicros = new AtomicLong();
        final AtomicLong readersServed = new AtomicLong();
        final AtomicLong readersFailed = new AtomicLong();
        final AtomicLong maxReadWaitMicros = new AtomicLong();
        final AtomicLong backfillBatches = new AtomicLong();
    }

    private static final Map<String, Slot> metrics = new ConcurrentHashMap<>();
    static {
        metrics.put("blocking", new Slot());
        metrics.put("expand_contract", new Slot());
        resetTable(20000);
    }

    public static void main(String[] args) throws Exception {
        HttpServer server = HttpServer.create(new InetSocketAddress(PORT), 0);
        server.createContext("/", Main::route);
        server.setExecutor(Executors.newCachedThreadPool());
        server.start();
        System.out.println("[case17-java] listening on " + PORT);
        Runtime.getRuntime().addShutdownHook(new Thread(() -> server.stop(0)));
    }

    private static void resetTable(int rows) {
        table.put("rows", rows);
        table.put("has_new_column", false);
        table.put("backfilled", 0);
        table.put("old_column_dropped", false);
        readFromNewColumn = false;
        phase = "idle";
    }

    private static void sleepMs(double ms) {
        if (ms <= 0) return;
        try {
            Thread.sleep((long) ms, (int) ((ms - (long) ms) * 1_000_000));
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
        }
    }

    private record ReaderResult(long served, long failed, List<Double> waits) {}

    /** Trafico normal que corre mientras la migracion pasa. */
    private static ReaderResult reader(CyclicBarrier gate, long stopAtNanos) {
        try { gate.await(); } catch (Exception ignored) { Thread.currentThread().interrupt(); }
        long served = 0, failed = 0;
        List<Double> waits = new ArrayList<>();
        while (System.nanoTime() < stopAtNanos) {
            long t0 = System.nanoTime();
            boolean got = false;
            try {
                // Deadline explicito: un lector real no espera para siempre.
                got = rwLock.readLock().tryLock(READ_TIMEOUT_MS, TimeUnit.MILLISECONDS);
            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
            }
            waits.add((System.nanoTime() - t0) / 1_000_000.0);
            if (got) {
                try {
                    Object ignored = table.get("rows");
                } finally {
                    rwLock.readLock().unlock();
                }
                served++;
            } else {
                failed++;
            }
            sleepMs(2);
        }
        return new ReaderResult(served, failed, waits);
    }

    // ------------------------------------------------------------------
    // Variante blocking
    // ------------------------------------------------------------------

    private static long[] migrateBlocking(int rows, int msPer1k) {
        resetTable(rows);
        phase = "expand";
        double durationMs = rows / 1000.0 * msPer1k;

        long t0 = System.nanoTime();
        // El lock exclusivo se toma UNA vez y se suelta al final.
        rwLock.writeLock().lock();
        try {
            sleepMs(durationMs);
            table.put("has_new_column", true);
            table.put("backfilled", rows);
            table.put("old_column_dropped", true);
            readFromNewColumn = true;
        } finally {
            rwLock.writeLock().unlock();
        }
        long heldMicros = (System.nanoTime() - t0) / 1000;
        phase = "done";
        return new long[]{heldMicros, 1};
    }

    // ------------------------------------------------------------------
    // Variante expand-contract
    // ------------------------------------------------------------------

    private static long[] migrateExpandContract(int rows, int msPer1k, int batchSize, int pauseMs) {
        resetTable(rows);
        double totalMs = rows / 1000.0 * msPer1k;
        long heldMicros = 0;
        long batches = 0;

        // 1. EXPAND — columna nullable: metadata, instantaneo.
        phase = "expand";
        long t0 = System.nanoTime();
        rwLock.writeLock().lock();
        try {
            table.put("has_new_column", true);
        } finally {
            rwLock.writeLock().unlock();
        }
        heldMicros += (System.nanoTime() - t0) / 1000;

        // 2. BACKFILL — por lotes, soltando el lock entre cada uno.
        phase = "backfill";
        int done = 0;
        double perBatchMs = totalMs * (batchSize / (double) Math.max(1, rows));
        while (done < rows) {
            int chunk = Math.min(batchSize, rows - done);
            t0 = System.nanoTime();
            rwLock.writeLock().lock();
            try {
                sleepMs(perBatchMs);
                table.put("backfilled", (int) table.get("backfilled") + chunk);
            } finally {
                rwLock.writeLock().unlock();
            }
            heldMicros += (System.nanoTime() - t0) / 1000;
            done += chunk;
            batches++;
            // La pausa entre lotes es lo que le devuelve el motor a la app.
            sleepMs(pauseMs);
        }

        // 3. SWITCH — feature flag. No toca datos: reversible en un segundo.
        phase = "switch";
        readFromNewColumn = true;

        // 4. CONTRACT — recien ahora se borra la vieja.
        phase = "contract";
        t0 = System.nanoTime();
        rwLock.writeLock().lock();
        try {
            table.put("old_column_dropped", true);
        } finally {
            rwLock.writeLock().unlock();
        }
        heldMicros += (System.nanoTime() - t0) / 1000;
        phase = "done";
        return new long[]{heldMicros, batches};
    }

    // ------------------------------------------------------------------
    // Orquestacion
    // ------------------------------------------------------------------

    private static String runMigration(String variant, int rows, int readers, int msPer1k, int batchSize, int pauseMs) {
        double budgetMs = rows / 1000.0 * msPer1k + (rows / (double) Math.max(1, batchSize)) * pauseMs + 400;
        long stopAt = System.nanoTime() + (long) (budgetMs * 1_000_000);
        CyclicBarrier gate = new CyclicBarrier(readers + 1);
        ExecutorService pool = Executors.newFixedThreadPool(readers);
        List<CompletableFuture<ReaderResult>> futures = new ArrayList<>(readers);
        for (int i = 0; i < readers; i++) {
            futures.add(CompletableFuture.supplyAsync(() -> reader(gate, stopAt), pool));
        }

        long started = System.nanoTime();
        try { gate.await(); } catch (Exception ignored) { Thread.currentThread().interrupt(); }
        long[] result = variant.equals("blocking")
                ? migrateBlocking(rows, msPer1k)
                : migrateExpandContract(rows, msPer1k, batchSize, pauseMs);
        double migrationMs = (System.nanoTime() - started) / 1_000_000.0;

        long served = 0, failed = 0;
        List<Double> waits = new ArrayList<>();
        for (CompletableFuture<ReaderResult> f : futures) {
            ReaderResult r = f.join();
            served += r.served();
            failed += r.failed();
            waits.addAll(r.waits());
        }
        double wallMs = (System.nanoTime() - started) / 1_000_000.0;
        pool.shutdown();

        double heldMs = result[0] / 1000.0;
        long batches = result[1];
        double[] sorted = waits.stream().mapToDouble(Double::doubleValue).sorted().toArray();
        double maxWait = sorted.length > 0 ? sorted[sorted.length - 1] : 0.0;

        Slot s = metrics.get(variant);
        s.runs.incrementAndGet();
        s.lockHeldMicros.addAndGet(result[0]);
        s.readersServed.addAndGet(served);
        s.readersFailed.addAndGet(failed);
        s.maxReadWaitMicros.accumulateAndGet((long) (maxWait * 1000), Math::max);
        s.backfillBatches.addAndGet(batches);

        String note = variant.equals("blocking")
                ? "Un solo lock exclusivo tomado durante toda la migracion: los lectores esperan lo que dure, y los que tienen timeout fallan. Es el ALTER TABLE que devuelve 503 durante veinte minutos."
                : "Expand, backfill por lotes con pausa, switch por feature flag y contract. El lock se toma y se suelta en cada lote, asi que ningun lector espera mas que un lote.";

        return "{\"variant\":\"" + variant + "\",\"rows_total\":" + rows
                + ",\"readers\":" + readers
                + ",\"phase\":\"" + phase + "\""
                + ",\"lock_held_ms\":" + round2(heldMs)
                + ",\"longest_single_lock_ms\":" + round2(variant.equals("blocking") ? heldMs : heldMs / Math.max(1, batches))
                + ",\"readers_served\":" + served
                + ",\"readers_failed\":" + failed
                + ",\"availability_pct\":" + round2(served * 100.0 / Math.max(1, served + failed))
                + ",\"p99_read_wait_ms\":" + round2(percentile(sorted, 99))
                + ",\"max_read_wait_ms\":" + round2(maxWait)
                + ",\"read_timeout_ms\":" + READ_TIMEOUT_MS
                + ",\"backfill_batches\":" + batches
                + ",\"backfill_progress_pct\":" + round2((int) table.get("backfilled") * 100.0 / Math.max(1, rows))
                + ",\"migration_ms\":" + round2(migrationMs)
                + ",\"wall_ms\":" + round2(wallMs)
                + ",\"note\":\"" + note + "\"}";
    }

    private static double percentile(double[] sorted, int pct) {
        if (sorted.length == 0) return 0.0;
        int idx = (int) Math.ceil(pct / 100.0 * sorted.length) - 1;
        return sorted[Math.max(0, Math.min(sorted.length - 1, idx))];
    }

    private static double round2(double v) {
        return Math.round(v * 100.0) / 100.0;
    }

    // ------------------------------------------------------------------
    // Rutas
    // ------------------------------------------------------------------

    private static String migrationStateJson() {
        int rows = (int) table.get("rows");
        int backfilled = (int) table.get("backfilled");
        return "{\"phase\":\"" + phase + "\""
                + ",\"phases\":[\"idle\",\"expand\",\"backfill\",\"switch\",\"contract\",\"done\"]"
                + ",\"rows_total\":" + rows
                + ",\"has_new_column\":" + table.get("has_new_column")
                + ",\"backfilled\":" + backfilled
                + ",\"backfill_progress_pct\":" + round2(backfilled * 100.0 / Math.max(1, rows))
                + ",\"old_column_dropped\":" + table.get("old_column_dropped")
                + ",\"read_from_new_column\":" + readFromNewColumn
                + ",\"read_timeout_ms\":" + READ_TIMEOUT_MS
                + ",\"fair_lock\":true"
                + ",\"note\":\"El feature flag read_from_new_column es lo unico reversible en un segundo. Por eso el switch va antes del contract, y no al reves.\"}";
    }

    private static String backfillStepJson(int batchSize, int msPer1k) {
        int rows = (int) table.get("rows");
        int done = (int) table.get("backfilled");
        if (!(boolean) table.get("has_new_column")) {
            return "{\"status\":\"skipped\",\"reason\":\"la columna nueva todavia no existe: falta la fase expand\"}";
        }
        if (done >= rows) {
            return "{\"status\":\"complete\",\"backfilled\":" + done + ",\"rows_total\":" + rows + "}";
        }
        int chunk = Math.min(batchSize, rows - done);
        long t0 = System.nanoTime();
        rwLock.writeLock().lock();
        try {
            sleepMs(rows / 1000.0 * msPer1k * (chunk / (double) Math.max(1, rows)));
            table.put("backfilled", done + chunk);
        } finally {
            rwLock.writeLock().unlock();
        }
        int now = (int) table.get("backfilled");
        return "{\"status\":\"batch_done\",\"batch_size\":" + chunk
                + ",\"lock_held_ms\":" + round2((System.nanoTime() - t0) / 1_000_000.0)
                + ",\"backfilled\":" + now + ",\"rows_total\":" + rows
                + ",\"backfill_progress_pct\":" + round2(now * 100.0 / Math.max(1, rows)) + "}";
    }

    private static String variantJson(String name) {
        Slot s = metrics.get(name);
        return "\"" + name + "\":{\"runs\":" + s.runs.get()
                + ",\"lock_held_ms\":" + round2(s.lockHeldMicros.get() / 1000.0)
                + ",\"readers_served\":" + s.readersServed.get()
                + ",\"readers_failed\":" + s.readersFailed.get()
                + ",\"max_read_wait_ms\":" + round2(s.maxReadWaitMicros.get() / 1000.0)
                + ",\"backfill_batches\":" + s.backfillBatches.get() + "}";
    }

    private static String diagnosticsJson() {
        return "{\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\",\"variants\":{"
                + variantJson("blocking") + "," + variantJson("expand_contract") + "}"
                + ",\"migration\":" + migrationStateJson()
                + ",\"interpretation\":{"
                + "\"blocking\":\"readers_failed > 0 y max_read_wait_ms = la duracion entera de la migracion: la app estuvo caida todo ese tiempo aunque el proceso siguiera vivo.\","
                + "\"expand_contract\":\"readers_failed = 0 y max_read_wait_ms = lo que dura UN lote. El trabajo total es el mismo; lo que cambia es como se reparte.\","
                + "\"java_note\":\"ReentrantReadWriteLock con tryLock(timeout) del lado del lector y modo justo en el constructor. Sin equidad, el trafico de lectura constante puede impedir que el escritor entre nunca — el ALTER TABLE se cuelga y nada se ve roto.\"}}";
    }

    private static void route(HttpExchange ex) throws IOException {
        URI uri = ex.getRequestURI();
        String path = uri.getPath();
        Map<String, String> q = queryParams(uri.getRawQuery());
        int rows = clamp(parseInt(q.get("rows"), 20000), 1000, 500000);
        int readers = clamp(parseInt(q.get("readers"), 8), 1, 64);
        int msPer1k = clamp(parseInt(q.get("ms_per_1k"), 20), 1, 200);
        int batch = clamp(parseInt(q.get("batch"), 2000), 100, 100000);
        int pauseMs = clamp(parseInt(q.get("pause_ms"), 5), 0, 200);

        int status = 200;
        String body;
        try {
            switch (path) {
                case "/", "/index" -> body = "{\"case\":\"" + CASE_NAME + "\",\"stack\":\"" + STACK
                        + "\",\"java_specific\":\"ReentrantReadWriteLock en modo justo + tryLock(timeout) del lado del lector: el deadline convierte una espera infinita en un 503 contable.\""
                        + ",\"routes\":[\"/health\",\"/migrate-blocking?rows=20000&readers=8\",\"/migrate-expand-contract?rows=20000&readers=8&batch=2000&pause_ms=5\",\"/migration/state\",\"/backfill?batch=2000\",\"/diagnostics/summary\",\"/reset-lab\"]}";
                case "/health" -> body = "{\"status\":\"ok\",\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\"}";
                case "/migrate-blocking" -> body = runMigration("blocking", rows, readers, msPer1k, batch, pauseMs);
                case "/migrate-expand-contract" -> body = runMigration("expand_contract", rows, readers, msPer1k, batch, pauseMs);
                case "/migration/state" -> body = migrationStateJson();
                case "/backfill" -> body = backfillStepJson(batch, msPer1k);
                case "/diagnostics/summary" -> body = diagnosticsJson();
                case "/reset-lab" -> {
                    resetTable(rows);
                    metrics.put("blocking", new Slot());
                    metrics.put("expand_contract", new Slot());
                    body = "{\"status\":\"reset\",\"message\":\"Tabla, fase y metricas reiniciadas.\"}";
                }
                default -> {
                    status = 404;
                    body = "{\"error\":\"Ruta no encontrada\",\"path\":\"" + escape(path) + "\"}";
                }
            }
        } catch (Exception e) {
            status = 500;
            body = "{\"error\":\"internal\",\"detail\":\"" + escape(String.valueOf(e.getMessage())) + "\"}";
        }

        byte[] out = body.getBytes(StandardCharsets.UTF_8);
        ex.getResponseHeaders().add("Content-Type", "application/json; charset=utf-8");
        ex.sendResponseHeaders(status, out.length);
        try (OutputStream os = ex.getResponseBody()) {
            os.write(out);
        }
    }

    private static int parseInt(String raw, int fallback) {
        if (raw == null || raw.isBlank()) return fallback;
        try { return Integer.parseInt(raw); } catch (NumberFormatException e) { return fallback; }
    }

    private static int clamp(int v, int lo, int hi) { return Math.max(lo, Math.min(hi, v)); }

    private static String escape(String v) {
        return v == null ? "" : v.replace("\\", "\\\\").replace("\"", "\\\"");
    }

    private static Map<String, String> queryParams(String rawQuery) {
        Map<String, String> params = new HashMap<>();
        if (rawQuery == null || rawQuery.isBlank()) return params;
        for (String pair : rawQuery.split("&")) {
            String[] parts = pair.split("=", 2);
            params.put(URLDecoder.decode(parts[0], StandardCharsets.UTF_8),
                    parts.length > 1 ? URLDecoder.decode(parts[1], StandardCharsets.UTF_8) : "");
        }
        return params;
    }
}
