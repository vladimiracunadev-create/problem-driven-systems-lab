import com.sun.net.httpserver.HttpExchange;
import com.sun.net.httpserver.HttpServer;

import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.InetSocketAddress;
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.nio.charset.StandardCharsets;
import java.time.Duration;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.Executors;

/**
 * Java Lab Dispatcher — un solo contenedor, un solo puerto para los casos Java.
 *
 * Espejo del patron de python-dispatcher y node-dispatcher:
 *   - Spawnea cada caso como subproceso interno con `java Main` en /cases/{id}/.
 *   - Listening publico en :8400.
 *   - Enruta por prefijo de path: /01/* → :9401, /02/* → :9402, etc.
 *   - Los puertos internos nunca se exponen al host.
 *
 * Cases operativos hoy: 01-06. Cases 07-12 se sumaran cuando esten implementados.
 */
public class Dispatcher {

    private record CaseInfo(String id, int port, String name, String dir) {}

    private static final List<CaseInfo> CASES = List.of(
        new CaseInfo("01", 9401, "API lenta bajo carga",               "/cases/01"),
        new CaseInfo("02", 9402, "N+1 y cuellos de botella DB",        "/cases/02"),
        new CaseInfo("03", 9403, "Observabilidad deficiente",          "/cases/03"),
        new CaseInfo("04", 9404, "Timeout chain y retry storms",       "/cases/04"),
        new CaseInfo("05", 9405, "Presion de memoria y fugas",         "/cases/05"),
        new CaseInfo("06", 9406, "Pipeline roto y delivery fragil",    "/cases/06"),
        new CaseInfo("07", 9407, "Modernizacion incremental monolito", "/cases/07"),
        new CaseInfo("08", 9408, "Extraccion critica de modulo",       "/cases/08"),
        new CaseInfo("09", 9409, "Integracion externa inestable",      "/cases/09"),
        new CaseInfo("10", 9410, "Arquitectura cara para algo simple", "/cases/10"),
        new CaseInfo("11", 9411, "Reportes que bloquean operacion",    "/cases/11"),
        new CaseInfo("12", 9412, "Punto unico de conocimiento",        "/cases/12")
    );

    private static final int DISPATCH_PORT = Integer.parseInt(System.getenv().getOrDefault("PORT", "8400"));
    private static final String APP_STACK = System.getenv().getOrDefault("APP_STACK", "Java 21");

    private static final HttpClient client = HttpClient.newBuilder()
            .connectTimeout(Duration.ofSeconds(2))
            .build();

    public static void main(String[] args) throws Exception {
        System.out.println("[java-dispatcher] starting " + CASES.size() + " case servers...");
        for (CaseInfo c : CASES) spawnCase(c);

        // espera a que cada caso responda /health
        for (CaseInfo c : CASES) waitHealthy(c, 30_000);

        HttpServer server = HttpServer.create(new InetSocketAddress(DISPATCH_PORT), 0);
        server.createContext("/", Dispatcher::route);
        server.setExecutor(Executors.newCachedThreadPool());
        server.start();
        System.out.println("[java-dispatcher] listening on " + DISPATCH_PORT);

        Runtime.getRuntime().addShutdownHook(new Thread(() -> server.stop(0)));
    }

    private static void spawnCase(CaseInfo c) throws IOException {
        ProcessBuilder pb = new ProcessBuilder("java", "-cp", c.dir, "Main");
        Map<String, String> env = pb.environment();
        env.put("PORT", String.valueOf(c.port));
        env.put("APP_STACK", APP_STACK);
        pb.directory(new java.io.File(c.dir));
        pb.redirectErrorStream(true);
        pb.redirectOutput(ProcessBuilder.Redirect.DISCARD);
        Process p = pb.start();
        System.out.println("  case " + c.id + " → interno :" + c.port + " (pid " + p.pid() + ")");
    }

    private static void waitHealthy(CaseInfo c, long timeoutMs) {
        long deadline = System.currentTimeMillis() + timeoutMs;
        while (System.currentTimeMillis() < deadline) {
            try {
                HttpResponse<String> r = client.send(
                        HttpRequest.newBuilder(URI.create("http://127.0.0.1:" + c.port + "/health"))
                                .timeout(Duration.ofMillis(800)).build(),
                        HttpResponse.BodyHandlers.ofString());
                if (r.statusCode() == 200) {
                    System.out.println("  case " + c.id + " healthy");
                    return;
                }
            } catch (Exception ignored) {}
            try { Thread.sleep(500); } catch (InterruptedException ie) { Thread.currentThread().interrupt(); return; }
        }
        System.err.println("[java-dispatcher] case " + c.id + " did not become healthy in " + timeoutMs + "ms");
    }

    private static void route(HttpExchange ex) throws IOException {
        URI uri = ex.getRequestURI();
        String path = uri.getPath();
        String method = ex.getRequestMethod();

        if (path.equals("/") || path.equals("/index") || path.equals("/index.html")) {
            sendJson(ex, 200, indexJson());
            return;
        }
        if (path.equals("/health")) {
            sendJson(ex, 200, "{\"status\":\"ok\",\"stack\":\"" + APP_STACK + "\",\"role\":\"dispatcher\"}");
            return;
        }

        if (path.length() < 3 || path.charAt(0) != '/') {
            sendJson(ex, 404, "{\"error\":\"not_found\",\"hint\":\"usa /01/..., /02/..., ..., /06/...\"}");
            return;
        }
        String caseId = path.substring(1, 3);
        CaseInfo target = null;
        for (CaseInfo c : CASES) if (c.id.equals(caseId)) { target = c; break; }
        if (target == null) {
            sendJson(ex, 404, "{\"error\":\"case_not_found\",\"case\":\"" + escape(caseId) + "\"}");
            return;
        }

        String remainder = path.length() > 3 ? path.substring(3) : "/";
        String query = uri.getRawQuery();
        String url = "http://127.0.0.1:" + target.port + remainder + (query != null ? "?" + query : "");

        try {
            HttpRequest.Builder rb = HttpRequest.newBuilder(URI.create(url))
                    .timeout(Duration.ofSeconds(30));
            byte[] body = ex.getRequestBody().readAllBytes();
            if (body.length > 0 && !method.equalsIgnoreCase("GET")) {
                rb.method(method, HttpRequest.BodyPublishers.ofByteArray(body));
            } else {
                rb.method(method, HttpRequest.BodyPublishers.noBody());
            }
            ex.getRequestHeaders().forEach((k, vs) -> {
                if (k != null && !k.equalsIgnoreCase("Host") && !k.equalsIgnoreCase("Content-Length")) {
                    for (String v : vs) {
                        try { rb.header(k, v); } catch (Exception ignored) {}
                    }
                }
            });
            HttpResponse<byte[]> resp = client.send(rb.build(), HttpResponse.BodyHandlers.ofByteArray());
            byte[] respBody = resp.body();
            resp.headers().map().forEach((k, vs) -> {
                if (!k.equalsIgnoreCase("transfer-encoding") && !k.equalsIgnoreCase("content-length")) {
                    for (String v : vs) ex.getResponseHeaders().add(k, v);
                }
            });
            ex.sendResponseHeaders(resp.statusCode(), respBody.length);
            try (OutputStream os = ex.getResponseBody()) { os.write(respBody); }
        } catch (Exception e) {
            sendJson(ex, 502, "{\"error\":\"upstream_unavailable\",\"case\":\"" + caseId +
                    "\",\"detail\":\"" + escape(e.getMessage()) + "\"}");
        }
    }

    private static String indexJson() {
        Map<String, Object> entries = new LinkedHashMap<>();
        for (CaseInfo c : CASES) {
            entries.put(c.id, Map.of(
                    "name", c.name,
                    "health", "/" + c.id + "/health",
                    "index", "/" + c.id + "/",
                    "internal_port", c.port));
        }
        StringBuilder sb = new StringBuilder(1024);
        sb.append("{\"lab\":\"Problem-Driven Systems Lab\"," +
                "\"stack\":\"").append(APP_STACK).append("\"," +
                "\"role\":\"dispatcher\"," +
                "\"usage\":\"GET /{caso}/{ruta}  →  e.g. /01/health, /04/quote-resilient\"," +
                "\"cases\":{");
        boolean first = true;
        for (CaseInfo c : CASES) {
            if (!first) sb.append(',');
            sb.append("\"").append(c.id).append("\":{\"name\":\"").append(escape(c.name))
              .append("\",\"health\":\"/").append(c.id).append("/health\",\"index\":\"/")
              .append(c.id).append("/\",\"internal_port\":").append(c.port).append('}');
            first = false;
        }
        sb.append("}}");
        return sb.toString();
    }

    private static void sendJson(HttpExchange ex, int status, String body) throws IOException {
        byte[] out = body.getBytes(StandardCharsets.UTF_8);
        ex.getResponseHeaders().add("Content-Type", "application/json; charset=utf-8");
        ex.sendResponseHeaders(status, out.length);
        try (OutputStream os = ex.getResponseBody()) { os.write(out); }
    }

    private static String escape(String v) {
        return v == null ? "" : v.replace("\\", "\\\\").replace("\"", "\\\"");
    }
}
