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
import java.util.Random;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.Executors;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.TimeoutException;
import java.util.concurrent.atomic.AtomicLong;
import java.util.concurrent.atomic.AtomicReference;
import java.util.concurrent.atomic.LongAdder;

/**
 * Caso 04 — Cadena de timeouts y tormentas de reintentos (stack Java).
 *
 * Legacy: 5 reintentos sin backoff contra proveedor lento → tormenta.
 * Resilient: CompletableFuture.orTimeout + circuit breaker como AtomicReference<State>
 * + fallback cacheado.
 *
 * Primitivas Java distintivas:
 *   - CompletableFuture.orTimeout(Duration) — deadline cooperativo a nivel future.
 *   - AtomicReference<BreakerState> con CAS para transiciones closed→open→half_open.
 *   - sealed-ish enum State.
 */
public class Main {

    private static final String CASE_NAME = "04 - Timeout chain y retry storms";
    private static final String STACK = "Java 21";
    private static final int PORT = Integer.parseInt(System.getenv().getOrDefault("PORT", "8080"));
    private static final long BREAKER_COOLDOWN_MS = 5000;
    private static final int BREAKER_FAIL_THRESHOLD = 3;

    private static final LongAdder legacyRetries = new LongAdder();
    private static final LongAdder legacyFailures = new LongAdder();
    private static final LongAdder resilientCalls = new LongAdder();
    private static final LongAdder resilientFallbacks = new LongAdder();
    private static final LongAdder resilientShortCircuits = new LongAdder();

    private static final AtomicReference<BreakerState> breaker = new AtomicReference<>(
            new BreakerState("closed", 0, 0L));
    private static final AtomicLong lastFallbackPrice = new AtomicLong(0L);

    private static final Random rng = new Random(20420L);

    public static void main(String[] args) throws Exception {
        HttpServer server = HttpServer.create(new InetSocketAddress(PORT), 0);
        server.createContext("/", Main::route);
        server.setExecutor(Executors.newCachedThreadPool());
        server.start();
        System.out.println("[case04-java] listening on " + PORT);
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
                            "\",\"routes\":[\"/health\",\"/quote-legacy?fail=on\",\"/quote-resilient?fail=on\",\"/dependency/state\",\"/diagnostics/summary\",\"/reset-lab\"]}";
                    break;
                case "/health":
                    body = "{\"status\":\"ok\",\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\"}";
                    break;
                case "/quote-legacy":
                    body = quoteLegacy("on".equals(q.getOrDefault("fail", "off")));
                    break;
                case "/quote-resilient":
                    body = quoteResilient("on".equals(q.getOrDefault("fail", "off")));
                    resilientCalls.increment();
                    break;
                case "/dependency/state":
                    body = breakerJson();
                    break;
                case "/diagnostics/summary":
                    body = diagnosticsJson();
                    break;
                case "/reset-lab":
                    legacyRetries.reset(); legacyFailures.reset();
                    resilientCalls.reset(); resilientFallbacks.reset(); resilientShortCircuits.reset();
                    breaker.set(new BreakerState("closed", 0, 0L));
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

    /** Legacy: 5 retries secuenciales sin backoff, sin timeout, sin breaker. */
    private static String quoteLegacy(boolean fail) {
        long t0 = System.nanoTime();
        for (int attempt = 1; attempt <= 5; attempt++) {
            legacyRetries.increment();
            try {
                long quote = callProvider(fail, 800);
                return "{\"variant\":\"legacy\",\"status\":\"ok\",\"attempts\":" + attempt +
                        ",\"quote\":" + quote + ",\"elapsed_ms\":" + round2((System.nanoTime() - t0) / 1e6) + "}";
            } catch (Exception e) {
                // sin backoff, sin breaker
            }
        }
        legacyFailures.increment();
        return "{\"variant\":\"legacy\",\"status\":\"failed\",\"attempts\":5," +
                "\"elapsed_ms\":" + round2((System.nanoTime() - t0) / 1e6) +
                ",\"note\":\"5 reintentos sin backoff agotaron al proveedor; sin circuit breaker.\"}";
    }

    /** Resilient: timeout cooperativo + circuit breaker + fallback cacheado. */
    private static String quoteResilient(boolean fail) {
        long t0 = System.nanoTime();
        BreakerState st = breaker.get();
        if ("open".equals(st.state) && (System.currentTimeMillis() - st.openedAt) < BREAKER_COOLDOWN_MS) {
            resilientShortCircuits.increment();
            long fb = lastFallbackPrice.get();
            return "{\"variant\":\"resilient\",\"status\":\"short_circuited\"," +
                    "\"breaker\":\"open\",\"fallback_quote\":" + fb +
                    ",\"elapsed_ms\":" + round2((System.nanoTime() - t0) / 1e6) +
                    ",\"note\":\"breaker abierto, devuelve fallback sin tocar al proveedor.\"}";
        }
        // half_open o closed: intentar 1 vez con CompletableFuture + orTimeout 300ms
        CompletableFuture<Long> fut = CompletableFuture
                .supplyAsync(() -> callProviderUnchecked(fail, 800))
                .orTimeout(300, TimeUnit.MILLISECONDS);
        try {
            long quote = fut.get();
            onSuccess();
            lastFallbackPrice.set(quote);
            return "{\"variant\":\"resilient\",\"status\":\"ok\",\"quote\":" + quote +
                    ",\"breaker\":\"" + breaker.get().state + "\"," +
                    "\"elapsed_ms\":" + round2((System.nanoTime() - t0) / 1e6) + "}";
        } catch (Exception e) {
            onFailure();
            resilientFallbacks.increment();
            long fb = lastFallbackPrice.get();
            return "{\"variant\":\"resilient\",\"status\":\"fallback\"," +
                    "\"breaker\":\"" + breaker.get().state + "\"," +
                    "\"fallback_quote\":" + fb +
                    ",\"elapsed_ms\":" + round2((System.nanoTime() - t0) / 1e6) +
                    ",\"cause\":\"" + (e.getCause() instanceof TimeoutException ? "timeout" : "provider_error") + "\"}";
        }
    }

    private static void onSuccess() {
        breaker.set(new BreakerState("closed", 0, 0L));
    }

    private static void onFailure() {
        BreakerState cur = breaker.get();
        int fails = cur.failCount + 1;
        if (fails >= BREAKER_FAIL_THRESHOLD) {
            breaker.set(new BreakerState("open", fails, System.currentTimeMillis()));
        } else {
            breaker.set(new BreakerState(cur.state, fails, cur.openedAt));
        }
    }

    private static long callProvider(boolean fail, int latencyMs) throws InterruptedException {
        Thread.sleep(latencyMs);
        if (fail) throw new RuntimeException("provider_unavailable");
        return 100L + rng.nextInt(900);
    }

    private static long callProviderUnchecked(boolean fail, int latencyMs) {
        try { return callProvider(fail, latencyMs); }
        catch (InterruptedException ie) { Thread.currentThread().interrupt(); throw new RuntimeException(ie); }
    }

    private static String breakerJson() {
        BreakerState s = breaker.get();
        long cooldownLeft = Math.max(0, BREAKER_COOLDOWN_MS - (System.currentTimeMillis() - s.openedAt));
        return "{\"state\":\"" + s.state + "\",\"fail_count\":" + s.failCount +
                ",\"opened_at\":" + s.openedAt + ",\"cooldown_left_ms\":" + cooldownLeft +
                ",\"threshold\":" + BREAKER_FAIL_THRESHOLD + ",\"cooldown_ms\":" + BREAKER_COOLDOWN_MS + "}";
    }

    private static String diagnosticsJson() {
        return "{\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\"," +
                "\"legacy\":{\"retries_total\":" + legacyRetries.sum() +
                ",\"failures\":" + legacyFailures.sum() +
                ",\"note\":\"reintentos lineales sin breaker producen retry storm\"}," +
                "\"resilient\":{\"calls\":" + resilientCalls.sum() +
                ",\"fallbacks\":" + resilientFallbacks.sum() +
                ",\"short_circuits\":" + resilientShortCircuits.sum() +
                ",\"breaker\":" + breakerJson() + "}}";
    }

    private record BreakerState(String state, int failCount, long openedAt) {}

    private static double round2(double v) { return Math.round(v * 100.0) / 100.0; }

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
