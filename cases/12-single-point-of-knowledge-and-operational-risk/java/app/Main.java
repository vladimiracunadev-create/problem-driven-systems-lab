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
import java.util.Optional;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.Executors;
import java.util.concurrent.atomic.AtomicInteger;
import java.util.concurrent.atomic.LongAdder;

/**
 * Caso 12 — Punto unico de conocimiento y riesgo operacional — stack Java.
 *
 * Legacy: incidente con owner ausente y runbook tribal crashea por NPE en
 * acceso ciego a estructuras anidadas.
 * Distributed: Optional<T> defensivo + chaining seguro = runbook codificado;
 * /share-knowledge sube coverage y baja mttr_min progresivamente.
 *
 * Primitiva Java distintiva:
 *   - Optional<T> + map/flatMap/orElse como runbook codificado en el lenguaje
 *     (equivalente al optional chaining ?. de Node).
 *   - record types para Incident, Owner inmutables.
 */
public class Main {

    private static final String CASE_NAME = "12 - Punto unico de conocimiento";
    private static final String STACK = "Java 21";
    private static final int PORT = Integer.parseInt(System.getenv().getOrDefault("PORT", "8080"));
    private static final int MAX_INCIDENTS = 30;

    private record Owner(String name, boolean available, Map<String, String> runbook) {}
    private record Incident(String at, String variant, String scenario, String result, long mttrMin) {}

    private static final Map<String, Owner> owners = new ConcurrentHashMap<>();
    private static final Deque<Incident> incidents = new ArrayDeque<>();
    /** coverage: % de runbooks documentados (0..100). */
    private static final AtomicInteger coverage = new AtomicInteger(30);
    private static final AtomicInteger busFactor = new AtomicInteger(1);

    private static final LongAdder legacyIncidents = new LongAdder();
    private static final LongAdder legacyCrashed = new LongAdder();
    private static final LongAdder distributedIncidents = new LongAdder();
    private static final LongAdder distributedHandled = new LongAdder();

    static {
        owners.put("alice", new Owner("alice", true, Map.of(
                "db_failover", "step1; step2; step3",
                "cache_purge", "redis-cli flushall on prod-cache-01")));
    }

    public static void main(String[] args) throws Exception {
        HttpServer server = HttpServer.create(new InetSocketAddress(PORT), 0);
        server.createContext("/", Main::route);
        server.setExecutor(Executors.newCachedThreadPool());
        server.start();
        System.out.println("[case12-java] listening on " + PORT);
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
                            "\",\"routes\":[\"/health\",\"/incident-legacy?scenario=owner_absent&runbook=db_failover\",\"/incident-distributed?scenario=owner_absent&runbook=db_failover\",\"/share-knowledge?owner=bob&runbook=db_failover\",\"/incidents\",\"/diagnostics/summary\",\"/reset-lab\"]}";
                    break;
                case "/health":
                    body = "{\"status\":\"ok\",\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\"}";
                    break;
                case "/incident-legacy":
                    body = incidentLegacy(q.getOrDefault("scenario", "owner_absent"),
                            q.getOrDefault("runbook", "db_failover"));
                    legacyIncidents.increment();
                    break;
                case "/incident-distributed":
                    body = incidentDistributed(q.getOrDefault("scenario", "owner_absent"),
                            q.getOrDefault("runbook", "db_failover"));
                    distributedIncidents.increment();
                    break;
                case "/share-knowledge":
                    body = shareKnowledge(q.getOrDefault("owner", "bob"),
                            q.getOrDefault("runbook", "db_failover"));
                    break;
                case "/incidents":
                    body = incidentsJson();
                    break;
                case "/diagnostics/summary":
                    body = diagnosticsJson();
                    break;
                case "/reset-lab":
                    legacyIncidents.reset(); legacyCrashed.reset();
                    distributedIncidents.reset(); distributedHandled.reset();
                    coverage.set(30); busFactor.set(1);
                    owners.clear();
                    owners.put("alice", new Owner("alice", true, Map.of(
                            "db_failover", "step1; step2; step3",
                            "cache_purge", "redis-cli flushall on prod-cache-01")));
                    synchronized (incidents) { incidents.clear(); }
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

    /** Legacy: acceso ciego a estructura anidada → NPE/exception cuando owner ausente. */
    private static String incidentLegacy(String scenario, String runbookKey) {
        try {
            // simula lookup ciego (sin Optional, sin nullcheck)
            Owner owner = pickOwnerLegacy(scenario);
            String script = owner.runbook().get(runbookKey);   // NPE si owner null
            if (script == null) throw new RuntimeException("runbook missing");
            String executed = script.toUpperCase();             // NPE si script null
            long mttr = 25 + (long) (Math.random() * 30);
            record("legacy", scenario, "executed", mttr);
            return "{\"variant\":\"legacy\",\"status\":\"executed\",\"scenario\":\"" + scenario +
                    "\",\"runbook\":\"" + runbookKey + "\",\"mttr_min\":" + mttr +
                    ",\"executed_preview\":\"" + escape(executed).substring(0, Math.min(40, executed.length())) +
                    "...\"}";
        } catch (Exception e) {
            legacyCrashed.increment();
            record("legacy", scenario, "crashed: " + e.getClass().getSimpleName(), 120);
            return "{\"variant\":\"legacy\",\"status\":\"crashed\",\"scenario\":\"" + scenario +
                    "\",\"reason\":\"" + e.getClass().getSimpleName() + ": " + escape(e.getMessage()) + "\"" +
                    ",\"mttr_min\":120,\"note\":\"acceso ciego a estructuras anidadas falla cuando owner/runbook ausente.\"}";
        }
    }

    /** Distributed: Optional + chaining seguro = runbook codificado, no crashea. */
    private static String incidentDistributed(String scenario, String runbookKey) {
        Optional<Owner> ownerOpt = pickOwnerDistributed(scenario);
        Optional<String> scriptOpt = ownerOpt.map(o -> o.runbook().get(runbookKey));
        String script = scriptOpt.orElse(null);
        long mttr;
        String result;
        if (script == null) {
            // degradacion controlada: usa runbook compartido por equipo
            mttr = 35 + (long) (Math.random() * 15);
            result = "owner_absent_handled_via_team_runbook";
        } else {
            mttr = 15 + (long) (Math.random() * 10);
            result = "executed_by_primary";
        }
        distributedHandled.increment();
        record("distributed", scenario, result, mttr);
        return "{\"variant\":\"distributed\",\"status\":\"handled\",\"scenario\":\"" + scenario +
                "\",\"runbook\":\"" + runbookKey + "\"," +
                "\"result\":\"" + result + "\"," +
                "\"primary_available\":" + ownerOpt.map(o -> o.available()).orElse(false) +
                ",\"mttr_min\":" + mttr +
                ",\"coverage\":" + coverage.get() +
                ",\"bus_factor\":" + busFactor.get() +
                ",\"note\":\"Optional<T> + chaining seguro = runbook codificado.\"}";
    }

    /** /share-knowledge: agrega owner alterno con el mismo runbook. */
    private static String shareKnowledge(String name, String runbookKey) {
        // copia el runbook de alice (primary) al nuevo owner
        Owner alice = owners.get("alice");
        if (alice == null) {
            return "{\"status\":\"error\",\"reason\":\"primary owner missing\"}";
        }
        owners.put(name, new Owner(name, true, new HashMap<>(alice.runbook())));
        int newCoverage = Math.min(100, coverage.get() + 15);
        coverage.set(newCoverage);
        busFactor.incrementAndGet();
        return "{\"status\":\"shared\",\"new_owner\":\"" + name +
                "\",\"runbook\":\"" + runbookKey + "\"," +
                "\"coverage\":" + newCoverage +
                ",\"bus_factor\":" + busFactor.get() +
                ",\"note\":\"el conocimiento dejo de vivir solo en alice; bus factor sube.\"}";
    }

    private static Owner pickOwnerLegacy(String scenario) {
        if (scenario.equals("owner_absent") || scenario.equals("night_shift")) return null;
        return owners.get("alice");
    }

    private static Optional<Owner> pickOwnerDistributed(String scenario) {
        if (scenario.equals("owner_absent") || scenario.equals("night_shift")) {
            // busca alterno disponible
            for (Owner o : owners.values()) {
                if (!o.name().equals("alice") && o.available()) return Optional.of(o);
            }
            return Optional.empty();
        }
        return Optional.ofNullable(owners.get("alice"));
    }

    private static void record(String variant, String scenario, String result, long mttr) {
        synchronized (incidents) {
            incidents.addFirst(new Incident(Instant.now().toString(), variant, scenario, result, mttr));
            while (incidents.size() > MAX_INCIDENTS) incidents.removeLast();
        }
    }

    private static String incidentsJson() {
        StringBuilder sb = new StringBuilder(1024);
        sb.append("{\"recent\":[");
        synchronized (incidents) {
            boolean first = true;
            for (Incident i : incidents) {
                if (!first) sb.append(',');
                sb.append("{\"at\":\"").append(i.at()).append("\",\"variant\":\"").append(i.variant())
                  .append("\",\"scenario\":\"").append(i.scenario()).append("\",\"result\":\"").append(i.result())
                  .append("\",\"mttr_min\":").append(i.mttrMin()).append('}');
                first = false;
            }
        }
        sb.append("]}");
        return sb.toString();
    }

    private static String diagnosticsJson() {
        return "{\"stack\":\"" + STACK + "\",\"case\":\"" + CASE_NAME + "\"," +
                "\"legacy\":{\"incidents\":" + legacyIncidents.sum() +
                ",\"crashed\":" + legacyCrashed.sum() +
                ",\"behavior\":\"acceso ciego, NPE cuando estructura ausente\"}," +
                "\"distributed\":{\"incidents\":" + distributedIncidents.sum() +
                ",\"handled\":" + distributedHandled.sum() +
                ",\"behavior\":\"Optional+chaining seguro = runbook codificado\"}," +
                "\"coverage\":" + coverage.get() +
                ",\"bus_factor\":" + busFactor.get() + "}";
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
