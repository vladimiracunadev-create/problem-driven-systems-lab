import com.sun.net.httpserver.HttpExchange;
import com.sun.net.httpserver.HttpServer;

import java.io.IOException;
import java.io.OutputStream;
import java.net.InetSocketAddress;
import java.net.URI;
import java.net.URLDecoder;
import java.nio.charset.StandardCharsets;
import java.time.Instant;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.CopyOnWriteArrayList;
import java.util.concurrent.CyclicBarrier;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.atomic.AtomicLong;
import java.util.concurrent.atomic.LongAdder;

/**
 * Caso 16 — Idempotencia y efectos duplicados — stack Java 21.
 *
 * Unsafe: N reintentos del mismo pago aplican N cargos.
 * Idempotent: `Idempotency-Key` persistida + outbox pattern.
 *
 * Primitiva Java distintiva:
 *   `ConcurrentHashMap.putIfAbsent(key, value)`.
 *
 *   Es la operacion atomica de "reserva la clave si nadie la tiene, y decime si
 *   ya estaba". Devuelve `null` si ganaste y el valor existente si perdiste — o
 *   sea, en una sola llamada resuelve la carrera Y te dice de que lado quedaste.
 *
 *   El contraste con la version rota cabe en dos lineas:
 *
 *       if (!table.containsKey(key)) table.put(key, entry);   // dos operaciones
 *       table.putIfAbsent(key, entry);                        // una
 *
 *   Entre el `containsKey` y el `put` hay una ventana. Con cinco reintentos
 *   concurrentes de un cliente que sufrio un timeout, esa ventana produce cinco
 *   cobros — y el codigo se ve razonable en la review.
 *
 *   `putIfAbsent`, `TryAdd` de .NET, `LoadOrStore` de Go y `entry()` de Rust son
 *   **la misma operacion con cuatro nombres**. Lo interesante del caso no es
 *   cual es mejor: es que los cuatro runtimes llegaron a la conclusion de que
 *   hacia falta una primitiva para esto.
 *
 * La segunda mitad es el **outbox pattern**: el cargo va a la base y el email a
 * una cola, sin transaccion que los abarque. El outbox escribe el efecto en la
 * misma escritura que el cargo y deja que un worker lo entregue.
 */
public class Main {

    private static final String CASE_NAME = "16 - Idempotencia y efectos duplicados";
    private static final String STACK = System.getenv().getOrDefault("APP_STACK", "Java 21");
    private static final int PORT = Integer.parseInt(System.getenv().getOrDefault("PORT", "8080"));

    private static final long DEDUPE_WINDOW_MS = 24L * 60 * 60 * 1000;

    private static final class Entry {
        volatile String response;
        final long storedAt = System.currentTimeMillis();
    }

    private record OutboxRow(String key, String kind, long amountCents, String at, String status, String via) {}

    private static final Map<String, Long> ledger = new ConcurrentHashMap<>();
    private static final ConcurrentHashMap<String, Entry> idempotency = new ConcurrentHashMap<>();
    private static final List<OutboxRow> outbox = new CopyOnWriteArrayList<>();
    private static final List<OutboxRow> delivered = new CopyOnWriteArrayList<>();

    private static final class Slot {
        final LongAdder runs = new LongAdder();
        final LongAdder attempts = new LongAdder();
        final LongAdder chargesApplied = new LongAdder();
        final LongAdder duplicatesPrevented = new LongAdder();
        final LongAdder duplicatesApplied = new LongAdder();
        final LongAdder idempotencyHits = new LongAdder();
        final LongAdder sideEffects = new LongAdder();
        final LongAdder overcharged = new LongAdder();
    }

    private static final Map<String, Slot> metrics = new ConcurrentHashMap<>();
    static {
        metrics.put("unsafe", new Slot());
        metrics.put("idempotent", new Slot());
    }

    public static void main(String[] args) throws Exception {
        HttpServer server = HttpServer.create(new InetSocketAddress(PORT), 0);
        server.createContext("/", Main::route);
        server.setExecutor(Executors.newCachedThreadPool());
        server.start();
        System.out.println("[case16-java] listening on " + PORT);
        Runtime.getRuntime().addShutdownHook(new Thread(() -> server.stop(0)));
    }

    private static long applyCharge(String account, long amount) {
        return ledger.merge(account, amount, Long::sum);
    }

    /** El efecto DIRECTO, fuera de la transaccion del cargo. */
    private static void emitDirect(String key, long amount) {
        delivered.add(new OutboxRow(key, "payment_receipt_email", amount, Instant.now().toString(), "delivered", "direct"));
        trim(delivered);
    }

    /** Escribe el efecto en el outbox, junto al cargo. No lo entrega. */
    private static void enqueueOutbox(String key, long amount) {
        outbox.add(new OutboxRow(key, "payment_receipt_email", amount, Instant.now().toString(), "pending", "outbox"));
        trim(outbox);
    }

    /** El worker que mueve el outbox al destino real. Idempotente por diseño. */
    private static int drainOutbox() {
        int moved = 0;
        for (int i = 0; i < outbox.size(); i++) {
            OutboxRow row = outbox.get(i);
            if ("pending".equals(row.status())) {
                OutboxRow done = new OutboxRow(row.key(), row.kind(), row.amountCents(), row.at(), "delivered", "outbox");
                outbox.set(i, done);
                delivered.add(done);
                moved++;
            }
        }
        trim(delivered);
        return moved;
    }

    private static void trim(List<OutboxRow> list) {
        while (list.size() > 200) list.remove(0);
    }

    private record Outcome(boolean applied, boolean hit, double lookupMs) {}

    // ------------------------------------------------------------------
    // Variante unsafe
    // ------------------------------------------------------------------

    private static Outcome attemptUnsafe(String key, String account, long amount, CyclicBarrier gate) {
        awaitGate(gate);
        applyCharge(account, amount);
        emitDirect(key, amount);
        return new Outcome(true, false, 0.0);
    }

    // ------------------------------------------------------------------
    // Variante idempotent: putIfAbsent
    // ------------------------------------------------------------------

    private static Outcome attemptIdempotent(String key, String account, long amount, CyclicBarrier gate) {
        awaitGate(gate);
        long t0 = System.nanoTime();

        Entry existing = idempotency.get(key);
        if (existing != null && System.currentTimeMillis() - existing.storedAt > DEDUPE_WINDOW_MS) {
            // Fuera de la ventana: la clave caduco y esto es una operacion nueva.
            idempotency.remove(key, existing);
        }

        Entry mine = new Entry();
        // Una sola operacion: reserva si nadie la tiene y devuelve quien gano.
        // Con containsKey + put habria una ventana, y cinco reintentos
        // concurrentes producirian cinco cobros.
        Entry winner = idempotency.putIfAbsent(key, mine);

        if (winner == null) {
            // El cargo y el efecto pendiente se escriben JUNTOS.
            long balance = applyCharge(account, amount);
            enqueueOutbox(key, amount);
            mine.response = "{\"status\":\"charged\",\"key\":\"" + escape(key) + "\",\"account\":\"" + escape(account)
                    + "\",\"amount_cents\":" + amount + ",\"balance_cents\":" + balance + "}";
            return new Outcome(true, false, ms(t0));
        }

        // Reintento: se devuelve exactamente la misma respuesta que habria
        // recibido el intento original.
        long deadline = System.currentTimeMillis() + 5000;
        while (winner.response == null && System.currentTimeMillis() < deadline) {
            Thread.onSpinWait();
        }
        return new Outcome(false, true, ms(t0));
    }

    private static void awaitGate(CyclicBarrier gate) {
        try {
            gate.await();
        } catch (Exception ignored) {
            Thread.currentThread().interrupt();
        }
    }

    private static double ms(long t0) {
        return Math.round((System.nanoTime() - t0) / 1_000.0) / 1000.0;
    }

    // ------------------------------------------------------------------
    // Orquestacion
    // ------------------------------------------------------------------

    private static String runAttempts(String variant, String key, String account, long amount, int attempts) {
        CyclicBarrier gate = new CyclicBarrier(attempts);
        ExecutorService pool = Executors.newFixedThreadPool(attempts);
        List<CompletableFuture<Outcome>> futures = new ArrayList<>(attempts);
        long t0 = System.nanoTime();
        for (int i = 0; i < attempts; i++) {
            futures.add(CompletableFuture.supplyAsync(
                    () -> variant.equals("unsafe")
                            ? attemptUnsafe(key, account, amount, gate)
                            : attemptIdempotent(key, account, amount, gate),
                    pool));
        }
        List<Outcome> results = new ArrayList<>(attempts);
        for (CompletableFuture<Outcome> f : futures) results.add(f.join());
        double wallMs = (System.nanoTime() - t0) / 1_000_000.0;
        pool.shutdown();

        long applied = results.stream().filter(Outcome::applied).count();
        long hits = results.stream().filter(Outcome::hit).count();
        double[] lookups = results.stream().mapToDouble(Outcome::lookupMs).filter(v -> v > 0).toArray();
        int deliveredNow = variant.equals("idempotent") ? drainOutbox() : 0;

        long balance = ledger.getOrDefault(account, 0L);
        long pending = outbox.stream().filter(r -> "pending".equals(r.status())).count();
        long overcharged = Math.max(0, applied - 1) * amount;
        long effects = variant.equals("unsafe") ? attempts : deliveredNow;

        Slot s = metrics.get(variant);
        s.runs.increment();
        s.attempts.add(attempts);
        s.chargesApplied.add(applied);
        s.duplicatesPrevented.add(hits);
        s.duplicatesApplied.add(Math.max(0, applied - 1));
        s.idempotencyHits.add(hits);
        s.sideEffects.add(effects);
        s.overcharged.add(overcharged);

        double avgLookup = lookups.length == 0 ? 0.0
                : Math.round(java.util.Arrays.stream(lookups).sum() / lookups.length * 1000.0) / 1000.0;

        String note = variant.equals("unsafe")
                ? "Sin clave de idempotencia: cada reintento aplica su propio cargo y publica su propio efecto. El cliente reintento por un timeout, no porque quisiera pagar de nuevo."
                : "putIfAbsent resuelve la carrera en una sola operacion + outbox en la misma escritura que el cargo: un cobro, un efecto, y los reintentos reciben la respuesta guardada.";

        return "{\"variant\":\"" + variant + "\",\"key\":\"" + escape(key) + "\",\"account\":\"" + escape(account) + "\""
                + ",\"attempts\":" + attempts
                + ",\"amount_cents\":" + amount
                + ",\"charges_applied\":" + applied
                + ",\"duplicates_prevented\":" + hits
                + ",\"duplicates_applied\":" + Math.max(0, applied - 1)
                + ",\"idempotency_hits\":" + hits
                + ",\"balance_cents\":" + balance
                + ",\"overcharged_cents\":" + overcharged
                + ",\"side_effects_emitted\":" + effects
                + ",\"side_effect_transport\":\"" + (variant.equals("unsafe")
                        ? "directo, fuera de la transaccion" : "outbox, en la misma escritura que el cargo") + "\""
                + ",\"outbox_pending\":" + pending
                + ",\"outbox_delivered\":" + delivered.size()
                + ",\"lookup_overhead_ms\":" + avgLookup
                + ",\"dedupe_window_ms\":" + DEDUPE_WINDOW_MS
                + ",\"wall_ms\":" + Math.round(wallMs * 100.0) / 100.0
                + ",\"note\":\"" + note + "\"}";
    }

    // ------------------------------------------------------------------
    // Rutas
    // ------------------------------------------------------------------

    private static String idempotencyStateJson() {
        StringBuilder sb = new StringBuilder(512);
        sb.append("{\"keys\":{");
        boolean first = true;
        long now = System.currentTimeMillis();
        for (Map.Entry<String, Entry> me : idempotency.entrySet()) {
            if (!first) sb.append(',');
            long age = now - me.getValue().storedAt;
            sb.append('"').append(escape(me.getKey())).append("\":{\"age_ms\":").append(age)
              .append(",\"expired\":").append(age > DEDUPE_WINDOW_MS)
              .append(",\"has_response\":").append(me.getValue().response != null).append('}');
            first = false;
        }
        sb.append("},\"key_count\":").append(idempotency.size()).append(",\"ledger_cents\":{");
        first = true;
        for (Map.Entry<String, Long> me : ledger.entrySet()) {
            if (!first) sb.append(',');
            sb.append('"').append(escape(me.getKey())).append("\":").append(me.getValue());
            first = false;
        }
        sb.append("},\"dedupe_window_ms\":").append(DEDUPE_WINDOW_MS)
          .append(",\"note\":\"La tabla de idempotencia necesita ventana y limpieza: una clave que vive para siempre es una tabla que crece para siempre.\"}");
        return sb.toString();
    }

    private static String rowsJson(List<OutboxRow> list, int limit) {
        StringBuilder sb = new StringBuilder(256);
        sb.append('[');
        int n = 0;
        for (int i = list.size() - 1; i >= 0 && n < limit; i--, n++) {
            if (n > 0) sb.append(',');
            OutboxRow r = list.get(i);
            sb.append("{\"key\":\"").append(escape(r.key())).append("\",\"kind\":\"").append(r.kind())
              .append("\",\"amount_cents\":").append(r.amountCents()).append(",\"at\":\"").append(r.at())
              .append("\",\"status\":\"").append(r.status()).append("\",\"via\":\"").append(r.via()).append("\"}");
        }
        sb.append(']');
        return sb.toString();
    }

    private static String outboxJson(int limit) {
        long pending = outbox.stream().filter(r -> "pending".equals(r.status())).count();
        return "{\"outbox_pending\":" + pending
                + ",\"outbox_total\":" + outbox.size()
                + ",\"delivered_total\":" + delivered.size()
                + ",\"limit\":" + limit
                + ",\"outbox\":" + rowsJson(outbox, limit)
                + ",\"delivered\":" + rowsJson(delivered, limit)
                + ",\"note\":\"El outbox se escribe en la misma transaccion que el cargo. El worker que lo drena puede reintentar sin miedo: entregar dos veces el mismo row es visible y corregible, perder el efecto no.\"}";
    }

    private static String variantJson(String name) {
        Slot s = metrics.get(name);
        return "\"" + name + "\":{\"runs\":" + s.runs.sum()
                + ",\"attempts\":" + s.attempts.sum()
                + ",\"charges_applied\":" + s.chargesApplied.sum()
                + ",\"duplicates_prevented\":" + s.duplicatesPrevented.sum()
                + ",\"duplicates_applied\":" + s.duplicatesApplied.sum()
                + ",\"idempotency_hits\":" + s.idempotencyHits.sum()
                + ",\"side_effects_emitted\":" + s.sideEffects.sum()
                + ",\"overcharged_cents\":" + s.overcharged.sum() + "}";
    }

    private static String diagnosticsJson() {
        long pending = outbox.stream().filter(r -> "pending".equals(r.status())).count();
        return "{\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\",\"variants\":{"
                + variantJson("unsafe") + "," + variantJson("idempotent") + "}"
                + ",\"outbox_pending\":" + pending
                + ",\"outbox_delivered\":" + delivered.size()
                + ",\"interpretation\":{"
                + "\"unsafe\":\"charges_applied = attempts: cada reintento cobro de nuevo. overcharged_cents es plata real que el negocio tiene que devolver.\","
                + "\"idempotent\":\"charges_applied = 1 y duplicates_prevented = attempts - 1, sin importar cuantas veces reintente el cliente.\","
                + "\"java_note\":\"putIfAbsent resuelve la carrera Y dice de que lado quedaste, en una sola llamada. Es la misma operacion que TryAdd en .NET, LoadOrStore en Go y entry() en Rust: cuatro runtimes llegaron a que hacia falta una primitiva para esto.\"}}";
    }

    private static void route(HttpExchange ex) throws IOException {
        URI uri = ex.getRequestURI();
        String path = uri.getPath();
        Map<String, String> q = queryParams(uri.getRawQuery());
        String key = q.getOrDefault("key", "order-4711");
        if (key.length() > 60) key = key.substring(0, 60);
        String account = q.getOrDefault("account", "acct-1");
        if (account.length() > 40) account = account.substring(0, 40);
        int attempts = clamp(parseInt(q.get("attempts"), 5), 1, 64);
        long amount = clamp(parseInt(q.get("amount"), 2500), 1, 10_000_000);
        int limit = clamp(parseInt(q.get("limit"), 20), 1, 200);

        int status = 200;
        String body;
        try {
            switch (path) {
                case "/", "/index" -> body = "{\"case\":\"" + CASE_NAME + "\",\"stack\":\"" + STACK
                        + "\",\"java_specific\":\"ConcurrentHashMap.putIfAbsent: una sola operacion que reserva la clave y dice quien gano. Con containsKey + put habria una ventana, y cinco reintentos producirian cinco cobros.\""
                        + ",\"routes\":[\"/health\",\"/charge-unsafe?key=order-4711&attempts=5&amount=2500\",\"/charge-idempotent?key=order-4711&attempts=5&amount=2500\",\"/idempotency/state\",\"/outbox?limit=20\",\"/diagnostics/summary\",\"/reset-lab\"]}";
                case "/health" -> body = "{\"status\":\"ok\",\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\"}";
                case "/charge-unsafe" -> body = runAttempts("unsafe", key, account, amount, attempts);
                case "/charge-idempotent" -> body = runAttempts("idempotent", key, account, amount, attempts);
                case "/idempotency/state" -> body = idempotencyStateJson();
                case "/outbox" -> body = outboxJson(limit);
                case "/diagnostics/summary" -> body = diagnosticsJson();
                case "/reset-lab" -> {
                    ledger.clear();
                    idempotency.clear();
                    outbox.clear();
                    delivered.clear();
                    metrics.put("unsafe", new Slot());
                    metrics.put("idempotent", new Slot());
                    body = "{\"status\":\"reset\",\"message\":\"Ledger, claves de idempotencia y outbox reiniciados.\"}";
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
        try {
            return Integer.parseInt(raw);
        } catch (NumberFormatException e) {
            return fallback;
        }
    }

    private static int clamp(int v, int lo, int hi) {
        return Math.max(lo, Math.min(hi, v));
    }

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
