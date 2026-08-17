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
import java.util.ArrayList;
import java.util.Deque;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.ArrayBlockingQueue;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.ConcurrentLinkedQueue;
import java.util.concurrent.Executors;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicBoolean;
import java.util.concurrent.atomic.AtomicLong;
import java.util.concurrent.atomic.LongAdder;

/**
 * Caso 15 — Backpressure en colas de mensajes — stack Java 21.
 *
 * Unbounded: `ConcurrentLinkedQueue`, que **no tiene capacidad maxima**. El
 * productor nunca se entera de que el consumidor no da abasto.
 * Bounded: `ArrayBlockingQueue(N)` con una politica explicita.
 *
 * Primitiva Java distintiva:
 *   La familia `BlockingQueue` codifica las tres politicas en tres metodos con
 *   nombres distintos — y ese es el aporte del stack:
 *
 *       put(msg)                     -> bloquea: backpressure al productor
 *       offer(msg)                   -> devuelve false: el llamador decide
 *       offer(msg, timeout, unit)    -> espera acotada y despues decide
 *
 *   Es la misma idea que las `RejectedExecutionHandler` de `ThreadPoolExecutor`
 *   (`AbortPolicy`, `DiscardOldestPolicy`, `CallerRunsPolicy`): Java tiene una
 *   taxonomia con nombre para cada forma de rechazar. Eso obliga a nombrar la
 *   decision en el codigo, que es mas de lo que hacen la mitad de los stacks.
 *
 *   El contraste incomodo esta arriba: `ConcurrentLinkedQueue` implementa la
 *   misma interfaz `Queue` y **no tiene capacidad**. Cambiar una por otra es un
 *   cambio de una linea que no rompe nada, no dispara ningun warning, y saca el
 *   freno del sistema entero.
 *
 * La leccion del caso: ninguna politica es gratis. Bloquear frena al productor,
 * descartar pierde datos, y la DLQ muda el problema (eso es el caso 20).
 */
public class Main {

    private static final String CASE_NAME = "15 - Backpressure en colas de mensajes";
    private static final String STACK = System.getenv().getOrDefault("APP_STACK", "Java 21");
    private static final int PORT = Integer.parseInt(System.getenv().getOrDefault("PORT", "8080"));

    private static final int MSG_BYTES = 2048;
    private static final List<String> POLICIES = List.of("block", "drop_oldest", "dead_letter");

    private record Msg(int seq, long enqueuedAtNanos) {}
    private record DlqEntry(int seq, String reason, String at) {}

    private static final Deque<DlqEntry> dlq = new ArrayDeque<>();
    private static final Map<String, Object> lastState = new ConcurrentHashMap<>();

    private static final class Slot {
        final LongAdder runs = new LongAdder();
        final LongAdder produced = new LongAdder();
        final LongAdder consumed = new LongAdder();
        final LongAdder dropped = new LongAdder();
        final LongAdder deadLettered = new LongAdder();
        final AtomicLong maxQueueDepth = new AtomicLong();
        final AtomicLong maxOldestAgeMicros = new AtomicLong();
        final AtomicLong producerBlockedMicros = new AtomicLong();
    }

    private static final Map<String, Slot> metrics = new ConcurrentHashMap<>();
    static {
        metrics.put("unbounded", new Slot());
        metrics.put("bounded", new Slot());
    }

    public static void main(String[] args) throws Exception {
        HttpServer server = HttpServer.create(new InetSocketAddress(PORT), 0);
        server.createContext("/", Main::route);
        server.setExecutor(Executors.newCachedThreadPool());
        server.start();
        System.out.println("[case15-java] listening on " + PORT);
        Runtime.getRuntime().addShutdownHook(new Thread(() -> server.stop(0)));
    }

    // ------------------------------------------------------------------
    // Variante unbounded: ConcurrentLinkedQueue, sin capacidad
    // ------------------------------------------------------------------

    private static String runUnbounded(int messages, int consumeMs) {
        ConcurrentLinkedQueue<Msg> q = new ConcurrentLinkedQueue<>();
        AtomicLong consumed = new AtomicLong();
        AtomicLong maxOldestMicros = new AtomicLong();
        AtomicLong peak = new AtomicLong();
        AtomicBoolean producing = new AtomicBoolean(true);

        Thread consumer = new Thread(() -> {
            while (producing.get() || !q.isEmpty()) {
                Msg m = q.poll();
                if (m == null) {
                    sleepMs(1);
                    continue;
                }
                // Se mide ANTES de procesar: la edad del mensaje mas viejo es la
                // latencia real del consumidor final, y sin limite no tiene techo.
                long ageMicros = (System.nanoTime() - m.enqueuedAtNanos()) / 1000;
                maxOldestMicros.accumulateAndGet(ageMicros, Math::max);
                sleepMs(consumeMs);
                consumed.incrementAndGet();
            }
        });
        consumer.start();

        long t0 = System.nanoTime();
        for (int seq = 0; seq < messages; seq++) {
            // add() sobre ConcurrentLinkedQueue nunca falla ni bloquea: no hay
            // capacidad que alcanzar. El freno no existe.
            q.add(new Msg(seq, System.nanoTime()));
            peak.accumulateAndGet(q.size(), Math::max);
        }
        int depthAtEnd = q.size();
        producing.set(false);
        joinQuietly(consumer);
        double wallMs = msSince(t0);

        return json(
                "unbounded", null, null, messages, consumed.get(), 0, 0,
                peak.get(), depthAtEnd, maxOldestMicros.get() / 1000.0, 0.0, 0,
                wallMs,
                "ConcurrentLinkedQueue no tiene capacidad maxima: add() nunca falla y la cola crece hasta donde de el "
                        + "heap. Implementa la misma interfaz Queue que ArrayBlockingQueue — cambiar una por otra es una "
                        + "linea y saca el freno del sistema entero.");
    }

    // ------------------------------------------------------------------
    // Variante bounded: ArrayBlockingQueue + politica explicita
    // ------------------------------------------------------------------

    private static String runBounded(int messages, int capacity, String policy, int consumeMs) {
        ArrayBlockingQueue<Msg> q = new ArrayBlockingQueue<>(capacity);
        AtomicLong consumed = new AtomicLong();
        AtomicLong maxOldestMicros = new AtomicLong();
        AtomicBoolean producing = new AtomicBoolean(true);

        Thread consumer = new Thread(() -> {
            while (producing.get() || !q.isEmpty()) {
                Msg m;
                try {
                    m = q.poll(20, TimeUnit.MILLISECONDS);
                } catch (InterruptedException e) {
                    Thread.currentThread().interrupt();
                    return;
                }
                if (m == null) continue;
                long ageMicros = (System.nanoTime() - m.enqueuedAtNanos()) / 1000;
                maxOldestMicros.accumulateAndGet(ageMicros, Math::max);
                sleepMs(consumeMs);
                consumed.incrementAndGet();
            }
        });
        consumer.start();

        long t0 = System.nanoTime();
        long produced = 0, dropped = 0, dead = 0, signals = 0;
        long blockedMicros = 0;
        long peak = 0;

        for (int seq = 0; seq < messages; seq++) {
            Msg m = new Msg(seq, System.nanoTime());
            switch (policy) {
                case "block" -> {
                    // put() bloqueante: la capacidad ES el freno. El productor se
                    // frena solo, sin protocolo extra.
                    if (q.remainingCapacity() == 0) signals++;
                    long b0 = System.nanoTime();
                    try {
                        q.put(m);
                    } catch (InterruptedException e) {
                        Thread.currentThread().interrupt();
                    }
                    long waited = System.nanoTime() - b0;
                    if (waited > 500_000) blockedMicros += waited / 1000;
                    produced++;
                }
                case "drop_oldest" -> {
                    // offer() devuelve false en vez de bloquear: el llamador decide.
                    // Es la DiscardOldestPolicy de ThreadPoolExecutor, a mano.
                    if (!q.offer(m)) {
                        signals++;
                        if (q.poll() != null) {
                            dropped++;
                            if (q.offer(m)) produced++;
                            else dropped++;
                        } else {
                            dropped++;
                        }
                    } else {
                        produced++;
                    }
                }
                default -> {
                    if (!q.offer(m)) {
                        signals++;
                        synchronized (dlq) {
                            dlq.addLast(new DlqEntry(m.seq(), "queue_full", Instant.now().toString()));
                            while (dlq.size() > 200) dlq.removeFirst();
                        }
                        dead++;
                    } else {
                        produced++;
                    }
                }
            }
            peak = Math.max(peak, q.size());
        }

        int depthAtEnd = q.size();
        producing.set(false);
        joinQuietly(consumer);
        double wallMs = msSince(t0);

        String note = switch (policy) {
            case "block" -> "put() bloqueante: la capacidad del ArrayBlockingQueue ES la señal de backpressure. Nada se "
                    + "pierde, pero el productor se frena y esa lentitud viaja aguas arriba.";
            case "drop_oldest" -> "offer() devuelve false y se descarta el mas viejo — la DiscardOldestPolicy de "
                    + "ThreadPoolExecutor escrita a mano. El productor nunca se frena, pero se pierden datos en silencio.";
            default -> "offer() devuelve false y lo que no entra va a la DLQ: no se frena ni se pierde, pero el problema "
                    + "se muda a otra cola que alguien tiene que mirar. Si nadie la mira, es el caso 20.";
        };

        return json("bounded", policy, capacity, produced, consumed.get(), dropped, dead,
                peak, depthAtEnd, maxOldestMicros.get() / 1000.0, blockedMicros / 1000.0, signals,
                wallMs, note);
    }

    // ------------------------------------------------------------------
    // JSON de resultado + registro
    // ------------------------------------------------------------------

    private static String json(String variant, String policy, Integer capacity, long produced, long consumed,
                               long dropped, long dead, long peak, int depthAtEnd, double oldestMs,
                               double blockedMs, long signals, double wallMs, String note) {
        String body = "{\"variant\":\"" + variant + "\""
                + ",\"policy\":" + (policy == null ? "null" : "\"" + policy + "\"")
                + ",\"capacity\":" + (capacity == null ? "null" : capacity)
                + ",\"produced\":" + produced
                + ",\"consumed\":" + consumed
                + ",\"dropped\":" + dropped
                + ",\"dead_lettered\":" + dead
                + ",\"queue_depth_peak\":" + peak
                + ",\"queue_depth_at_end_of_production\":" + depthAtEnd
                + ",\"queue_bytes_peak\":" + (peak * MSG_BYTES)
                + ",\"oldest_msg_age_ms_peak\":" + round2(oldestMs)
                + ",\"producer_blocked_ms\":" + round2(blockedMs)
                + ",\"backpressure_signals\":" + signals
                + ",\"wall_ms\":" + round2(wallMs)
                + ",\"throughput_msg_s\":" + (wallMs > 0 ? round2(produced / (wallMs / 1000.0)) : 0.0)
                + ",\"note\":\"" + note + "\"}";

        Slot s = metrics.get(variant);
        s.runs.increment();
        s.produced.add(produced);
        s.consumed.add(consumed);
        s.dropped.add(dropped);
        s.deadLettered.add(dead);
        s.maxQueueDepth.accumulateAndGet(peak, Math::max);
        s.maxOldestAgeMicros.accumulateAndGet((long) (oldestMs * 1000), Math::max);
        s.producerBlockedMicros.addAndGet((long) (blockedMs * 1000));

        lastState.put("last_variant", variant);
        lastState.put("last_policy", policy == null ? "null" : policy);
        lastState.put("capacity", capacity == null ? -1 : capacity);
        lastState.put("queue_depth_peak", peak);
        lastState.put("queue_bytes_peak", peak * MSG_BYTES);
        lastState.put("oldest_msg_age_ms_peak", round2(oldestMs));

        return body;
    }

    private static String queueStateJson() {
        int depth;
        synchronized (dlq) { depth = dlq.size(); }
        return "{\"last_variant\":\"" + lastState.getOrDefault("last_variant", "") + "\""
                + ",\"last_policy\":\"" + lastState.getOrDefault("last_policy", "") + "\""
                + ",\"capacity\":" + lastState.getOrDefault("capacity", -1)
                + ",\"queue_depth_peak\":" + lastState.getOrDefault("queue_depth_peak", 0)
                + ",\"queue_bytes_peak\":" + lastState.getOrDefault("queue_bytes_peak", 0)
                + ",\"oldest_msg_age_ms_peak\":" + lastState.getOrDefault("oldest_msg_age_ms_peak", 0.0)
                + ",\"dlq_depth\":" + depth
                + ",\"msg_bytes\":" + MSG_BYTES
                + ",\"policies\":[\"block\",\"drop_oldest\",\"dead_letter\"]"
                + ",\"note\":\"queue_depth_peak x msg_bytes es lo que la cola llego a ocupar. ConcurrentLinkedQueue no tiene techo.\"}";
    }

    private static String dlqJson(int limit) {
        StringBuilder sb = new StringBuilder(512);
        int depth;
        List<DlqEntry> items = new ArrayList<>();
        synchronized (dlq) {
            depth = dlq.size();
            var it = dlq.descendingIterator();
            while (it.hasNext() && items.size() < limit) items.add(it.next());
        }
        sb.append("{\"dlq_depth\":").append(depth).append(",\"limit\":").append(limit).append(",\"messages\":[");
        for (int i = 0; i < items.size(); i++) {
            if (i > 0) sb.append(',');
            DlqEntry e = items.get(i);
            sb.append("{\"seq\":").append(e.seq()).append(",\"reason\":\"").append(e.reason())
              .append("\",\"at\":\"").append(e.at()).append("\"}");
        }
        sb.append("],\"note\":\"La DLQ no resuelve el backpressure: lo muda. El caso 20 trata que pasa cuando nadie la mira.\"}");
        return sb.toString();
    }

    private static String variantJson(String name) {
        Slot s = metrics.get(name);
        return "\"" + name + "\":{\"runs\":" + s.runs.sum()
                + ",\"produced\":" + s.produced.sum()
                + ",\"consumed\":" + s.consumed.sum()
                + ",\"dropped\":" + s.dropped.sum()
                + ",\"dead_lettered\":" + s.deadLettered.sum()
                + ",\"max_queue_depth\":" + s.maxQueueDepth.get()
                + ",\"max_oldest_age_ms\":" + round2(s.maxOldestAgeMicros.get() / 1000.0)
                + ",\"producer_blocked_ms\":" + round2(s.producerBlockedMicros.get() / 1000.0) + "}";
    }

    private static String diagnosticsJson() {
        int depth;
        synchronized (dlq) { depth = dlq.size(); }
        return "{\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\",\"variants\":{"
                + variantJson("unbounded") + "," + variantJson("bounded") + "}"
                + ",\"dlq_depth\":" + depth
                + ",\"interpretation\":{"
                + "\"unbounded\":\"producer_blocked_ms = 0 y dropped = 0 se ven bien hasta que se mira queue_depth_peak y oldest_msg_age_ms_peak.\","
                + "\"bounded\":\"Las tres politicas pagan algo distinto: block paga latencia del productor, drop_oldest paga datos, dead_letter paga deuda operativa.\","
                + "\"java_note\":\"BlockingQueue codifica las tres politicas en put/offer/offer(timeout), igual que las RejectedExecutionHandler de ThreadPoolExecutor. Pero ConcurrentLinkedQueue implementa la misma interfaz y no tiene capacidad.\"}}";
    }

    // ------------------------------------------------------------------
    // Rutas
    // ------------------------------------------------------------------

    private static void route(HttpExchange ex) throws IOException {
        URI uri = ex.getRequestURI();
        String path = uri.getPath();
        Map<String, String> q = queryParams(uri.getRawQuery());
        int messages = clamp(parseInt(q.get("messages"), 120), 1, 2000);
        int capacity = clamp(parseInt(q.get("capacity"), 32), 1, 1000);
        int consumeMs = clamp(parseInt(q.get("consume_ms"), 2), 0, 100);
        int limit = clamp(parseInt(q.get("limit"), 20), 1, 200);
        String policy = q.getOrDefault("policy", "block");
        if (!POLICIES.contains(policy)) policy = "block";

        int status = 200;
        String body;
        try {
            switch (path) {
                case "/", "/index" -> body = "{\"case\":\"" + CASE_NAME + "\",\"stack\":\"" + STACK
                        + "\",\"java_specific\":\"BlockingQueue con put/offer/offer(timeout) — la misma taxonomia de las RejectedExecutionHandler. ConcurrentLinkedQueue es la version sin capacidad.\""
                        + ",\"routes\":[\"/health\",\"/produce-unbounded?messages=120&consume_ms=2\",\"/produce-bounded?messages=120&capacity=32&policy=block&consume_ms=2\",\"/produce-bounded?messages=120&capacity=32&policy=drop_oldest\",\"/produce-bounded?messages=120&capacity=32&policy=dead_letter\",\"/queue/state\",\"/dlq?limit=20\",\"/diagnostics/summary\",\"/reset-lab\"]"
                        + ",\"allowed_policies\":[\"block\",\"drop_oldest\",\"dead_letter\"]}";
                case "/health" -> body = "{\"status\":\"ok\",\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\"}";
                case "/produce-unbounded" -> body = runUnbounded(messages, consumeMs);
                case "/produce-bounded" -> body = runBounded(messages, capacity, policy, consumeMs);
                case "/queue/state" -> body = queueStateJson();
                case "/dlq" -> body = dlqJson(limit);
                case "/diagnostics/summary" -> body = diagnosticsJson();
                case "/reset-lab" -> {
                    synchronized (dlq) { dlq.clear(); }
                    lastState.clear();
                    metrics.put("unbounded", new Slot());
                    metrics.put("bounded", new Slot());
                    body = "{\"status\":\"reset\",\"message\":\"DLQ y metricas reiniciadas.\"}";
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

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private static void sleepMs(long ms) {
        if (ms <= 0) return;
        try {
            Thread.sleep(ms);
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
        }
    }

    private static void joinQuietly(Thread t) {
        try {
            t.join(15_000);
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
        }
    }

    private static double msSince(long t0) {
        return (System.nanoTime() - t0) / 1_000_000.0;
    }

    private static double round2(double v) {
        return Math.round(v * 100.0) / 100.0;
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
