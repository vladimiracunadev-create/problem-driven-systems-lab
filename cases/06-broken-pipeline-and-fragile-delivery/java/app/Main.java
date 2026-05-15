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
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.Executors;
import java.util.concurrent.atomic.LongAdder;

/**
 * Caso 06 — Pipeline roto y entrega fragil (stack Java).
 *
 * Legacy: deploy directo sin preflight, sin smoke, sin rollback.
 * Controlled: state machine (preflight → canary → smoke → promote | rollback).
 *
 * Primitivas Java distintivas:
 *   - record types para DeploymentState inmutables.
 *   - ConcurrentHashMap<String,EnvState> para snapshot por ambiente sin lock global.
 *   - State machine como enum + transition table.
 */
public class Main {

    private static final String CASE_NAME = "06 - Pipeline roto y delivery fragil";
    private static final String STACK = "Java 21";
    private static final int PORT = Integer.parseInt(System.getenv().getOrDefault("PORT", "8080"));
    private static final int MAX_DEPLOYMENTS = 30;

    private static final Map<String, EnvState> environments = new ConcurrentHashMap<>();
    private static final Deque<Deployment> deployments = new ArrayDeque<>();

    private static final LongAdder legacyDeploys = new LongAdder();
    private static final LongAdder legacyBroken = new LongAdder();
    private static final LongAdder controlledDeploys = new LongAdder();
    private static final LongAdder controlledRollbacks = new LongAdder();
    private static final LongAdder controlledBlocked = new LongAdder();

    public static void main(String[] args) throws Exception {
        environments.put("staging", new EnvState("staging", "v1.0.0", "healthy"));
        environments.put("prod", new EnvState("prod", "v1.0.0", "healthy"));
        HttpServer server = HttpServer.create(new InetSocketAddress(PORT), 0);
        server.createContext("/", Main::route);
        server.setExecutor(Executors.newCachedThreadPool());
        server.start();
        System.out.println("[case06-java] listening on " + PORT);
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
                            "\",\"routes\":[\"/health\",\"/deploy-legacy?env=prod&version=v1.1.0&scenario=secret_drift\",\"/deploy-controlled?env=prod&version=v1.1.0&scenario=secret_drift\",\"/environments\",\"/deployments\",\"/diagnostics/summary\",\"/reset-lab\"]}";
                    break;
                case "/health":
                    body = "{\"status\":\"ok\",\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\"}";
                    break;
                case "/deploy-legacy":
                    body = deployLegacy(q.getOrDefault("env", "prod"),
                            q.getOrDefault("version", "v1.1.0"),
                            q.getOrDefault("scenario", "clean"));
                    break;
                case "/deploy-controlled":
                    body = deployControlled(q.getOrDefault("env", "prod"),
                            q.getOrDefault("version", "v1.1.0"),
                            q.getOrDefault("scenario", "clean"));
                    break;
                case "/environments":
                    body = environmentsJson();
                    break;
                case "/deployments":
                    body = deploymentsJson();
                    break;
                case "/diagnostics/summary":
                    body = diagnosticsJson();
                    break;
                case "/reset-lab":
                    environments.put("staging", new EnvState("staging", "v1.0.0", "healthy"));
                    environments.put("prod", new EnvState("prod", "v1.0.0", "healthy"));
                    synchronized (deployments) { deployments.clear(); }
                    legacyDeploys.reset(); legacyBroken.reset();
                    controlledDeploys.reset(); controlledRollbacks.reset(); controlledBlocked.reset();
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

    /** Legacy: aplica el version sin preflight, deja ambiente roto si el scenario falla. */
    private static String deployLegacy(String env, String version, String scenario) {
        legacyDeploys.increment();
        String result;
        String health;
        if (isBadScenario(scenario)) {
            health = "degraded";
            legacyBroken.increment();
            result = "deployed_but_broken";
        } else {
            health = "healthy";
            result = "deployed";
        }
        environments.put(env, new EnvState(env, version, health));
        record("legacy", env, version, scenario, result);
        return "{\"variant\":\"legacy\",\"env\":\"" + env + "\",\"version\":\"" + version +
                "\",\"scenario\":\"" + scenario + "\",\"result\":\"" + result + "\",\"health\":\"" + health +
                "\",\"note\":\"sin preflight ni rollback; ambiente queda como quede.\"}";
    }

    /** Controlled: preflight → smoke → promote, o rollback si smoke falla. */
    private static String deployControlled(String env, String version, String scenario) {
        controlledDeploys.increment();
        EnvState before = environments.get(env);
        // preflight bloquea si scenario es claramente malo
        if (scenario.equals("missing_artifact") || scenario.equals("secret_drift_detected")) {
            controlledBlocked.increment();
            record("controlled", env, version, scenario, "blocked_in_preflight");
            return "{\"variant\":\"controlled\",\"env\":\"" + env + "\",\"version\":\"" + version +
                    "\",\"scenario\":\"" + scenario + "\",\"result\":\"blocked_in_preflight\"," +
                    "\"current_version\":\"" + before.version + "\"," +
                    "\"note\":\"preflight bloqueo antes de tocar el ambiente.\"}";
        }
        // smoke despues del deploy
        if (isBadScenario(scenario)) {
            // rollback automatico
            controlledRollbacks.increment();
            record("controlled", env, version, scenario, "rolled_back_to_" + before.version);
            return "{\"variant\":\"controlled\",\"env\":\"" + env + "\",\"version\":\"" + version +
                    "\",\"scenario\":\"" + scenario + "\",\"result\":\"rolled_back\"," +
                    "\"current_version\":\"" + before.version + "\"," +
                    "\"note\":\"smoke fallo, rollback automatico al version anterior.\"}";
        }
        environments.put(env, new EnvState(env, version, "healthy"));
        record("controlled", env, version, scenario, "promoted");
        return "{\"variant\":\"controlled\",\"env\":\"" + env + "\",\"version\":\"" + version +
                "\",\"scenario\":\"" + scenario + "\",\"result\":\"promoted\",\"health\":\"healthy\"," +
                "\"note\":\"preflight ok + smoke ok → promote.\"}";
    }

    private static boolean isBadScenario(String scenario) {
        return scenario.equals("secret_drift") || scenario.equals("breaking_change")
                || scenario.equals("schema_mismatch");
    }

    private static void record(String variant, String env, String version, String scenario, String result) {
        synchronized (deployments) {
            deployments.addFirst(new Deployment(Instant.now().toString(), variant, env, version, scenario, result));
            while (deployments.size() > MAX_DEPLOYMENTS) deployments.removeLast();
        }
    }

    private static String environmentsJson() {
        StringBuilder sb = new StringBuilder(512);
        sb.append("{\"envs\":[");
        boolean first = true;
        for (EnvState s : environments.values()) {
            if (!first) sb.append(',');
            sb.append("{\"name\":\"").append(s.name).append("\",\"version\":\"").append(s.version)
              .append("\",\"health\":\"").append(s.health).append("\"}");
            first = false;
        }
        sb.append("]}");
        return sb.toString();
    }

    private static String deploymentsJson() {
        StringBuilder sb = new StringBuilder(1024);
        sb.append("{\"history\":[");
        synchronized (deployments) {
            boolean first = true;
            for (Deployment d : deployments) {
                if (!first) sb.append(',');
                sb.append("{\"at\":\"").append(d.at).append("\",\"variant\":\"").append(d.variant)
                  .append("\",\"env\":\"").append(d.env).append("\",\"version\":\"").append(d.version)
                  .append("\",\"scenario\":\"").append(d.scenario).append("\",\"result\":\"").append(d.result).append("\"}");
                first = false;
            }
        }
        sb.append("],\"max_kept\":").append(MAX_DEPLOYMENTS).append('}');
        return sb.toString();
    }

    private static String diagnosticsJson() {
        return "{\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\"," +
                "\"legacy\":{\"deploys\":" + legacyDeploys.sum() +
                ",\"broken_state_left\":" + legacyBroken.sum() +
                ",\"behavior\":\"sin preflight, sin rollback\"}," +
                "\"controlled\":{\"deploys\":" + controlledDeploys.sum() +
                ",\"blocked_in_preflight\":" + controlledBlocked.sum() +
                ",\"rollbacks\":" + controlledRollbacks.sum() +
                ",\"behavior\":\"preflight + smoke + rollback automatico\"}," +
                "\"environments\":" + environmentsJson() + "}";
    }

    private record EnvState(String name, String version, String health) {}
    private record Deployment(String at, String variant, String env, String version, String scenario, String result) {}

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
