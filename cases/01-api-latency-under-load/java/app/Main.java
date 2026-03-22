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
import java.util.Collections;
import java.util.HashMap;
import java.util.List;
import java.util.Map;

public class Main {
    private static final Metrics metrics = new Metrics(3000);

    public static void main(String[] args) throws Exception {
        HttpServer server = HttpServer.create(new InetSocketAddress(8080), 0);
        server.createContext("/", Main::handle);
        server.setExecutor(null);
        server.start();
        System.out.println("Servidor Java escuchando en 8080");
    }

    private static void handle(HttpExchange exchange) throws IOException {
        long start = System.nanoTime();
        URI uri = exchange.getRequestURI();
        String path = uri.getPath();
        Map<String, String> query = queryParams(uri.getRawQuery());
        int status = 200;
        String json;

        try {
            switch (path) {
                case "/":
                    json = """
                    {
                      "lab": "Problem-Driven Systems Lab",
                      "case": "01 - API lenta bajo carga",
                      "stack": "Java",
                      "goal": "Simular endpoints rápidos y lentos para estudiar latencia, percentiles y comportamiento bajo carga.",
                      "recommended_flow": [
                        "Levantar un solo stack primero para entender el caso.",
                        "Usar compose.compare.yml solo cuando quieras comparar comportamientos.",
                        "Medir con /metrics antes y después de generar carga."
                      ],
                      "routes": {
                        "/": "Resumen del caso y rutas disponibles.",
                        "/health": "Chequeo simple.",
                        "/fast": "Respuesta rápida y liviana.",
                        "/slow?delay_ms=200&payload_kb=4": "Simula latencia I/O y payload mayor.",
                        "/cpu?iterations=3500000": "Simula trabajo CPU-bound.",
                        "/mixed?delay_ms=120&iterations=1500000&payload_kb=8": "Combina espera, CPU y payload.",
                        "/metrics": "Métricas acumuladas en memoria.",
                        "/reset-metrics": "Reinicia contadores del caso."
                      }
                    }
                    """;
                    break;
                case "/health":
                    json = "{" +
                            "\"status\":\"ok\"," +
                            "\"stack\":\"Java\"," +
                            "\"case\":\"01 - API lenta bajo carga\"}";
                    break;
                case "/fast":
                    json = "{" +
                            "\"endpoint\":\"fast\"," +
                            "\"message\":\"Respuesta ligera diseñada para contrastar con rutas lentas.\"}";
                    break;
                case "/slow": {
                    int delayMs = boundedInt(query.getOrDefault("delay_ms", "250"), 0, 60000);
                    int payloadKb = boundedInt(query.getOrDefault("payload_kb", "8"), 0, 256);
                    sleep(delayMs);
                    json = "{" +
                            "\"endpoint\":\"slow\"," +
                            "\"delay_ms\":" + delayMs + "," +
                            "\"payload_kb\":" + payloadKb + "," +
                            "\"message\":\"Esta ruta simula espera de red, I/O o dependencia externa.\"," +
                            "\"payload\":\"" + payloadOfKb(payloadKb) + "\"}";
                    break;
                }
                case "/cpu": {
                    int iterations = boundedInt(query.getOrDefault("iterations", "3500000"), 1, 20000000);
                    long checksum = cpuWork(iterations);
                    json = "{" +
                            "\"endpoint\":\"cpu\"," +
                            "\"iterations\":" + iterations + "," +
                            "\"checksum\":" + checksum + "," +
                            "\"message\":\"Esta ruta simula presión de CPU en una ruta crítica.\"}";
                    break;
                }
                case "/mixed": {
                    int delayMs = boundedInt(query.getOrDefault("delay_ms", "120"), 0, 60000);
                    int iterations = boundedInt(query.getOrDefault("iterations", "1500000"), 1, 20000000);
                    int payloadKb = boundedInt(query.getOrDefault("payload_kb", "12"), 0, 256);
                    sleep(delayMs);
                    long checksum = cpuWork(iterations);
                    json = "{" +
                            "\"endpoint\":\"mixed\"," +
                            "\"delay_ms\":" + delayMs + "," +
                            "\"iterations\":" + iterations + "," +
                            "\"checksum\":" + checksum + "," +
                            "\"payload_kb\":" + payloadKb + "," +
                            "\"message\":\"Mezcla espera, trabajo CPU y payload para emular una ruta más realista.\"," +
                            "\"payload\":\"" + payloadOfKb(payloadKb) + "\"}";
                    break;
                }
                case "/metrics":
                    json = metrics.toJson();
                    break;
                case "/reset-metrics":
                    metrics.reset();
                    json = "{" +
                            "\"status\":\"reset\"," +
                            "\"message\":\"Métricas reiniciadas para el stack Java.\"}";
                    break;
                default:
                    status = 404;
                    json = "{" +
                            "\"error\":\"Ruta no encontrada\"," +
                            "\"path\":\"" + escape(path) + "\"}";
                    break;
            }
        } catch (Exception e) {
            status = 500;
            json = "{" +
                    "\"error\":\"Error interno\"," +
                    "\"detail\":\"" + escape(e.getMessage()) + "\"}";
        }

        double elapsedMs = round2((System.nanoTime() - start) / 1_000_000.0);
        if (!path.equals("/metrics") && !path.equals("/reset-metrics")) {
            metrics.record(path, status, elapsedMs);
        }

        String suffix = ",\"elapsed_ms\":" + elapsedMs + ",\"pid\":" + ProcessHandle.current().pid() + ",\"timestamp_utc\":\"" + Instant.now() + "\"}";
        String body = json.endsWith("}") ? json.substring(0, json.length() - 1) + suffix : json;
        byte[] bytes = body.getBytes(StandardCharsets.UTF_8);
        exchange.getResponseHeaders().add("Content-Type", "application/json; charset=utf-8");
        exchange.sendResponseHeaders(status, bytes.length);
        try (OutputStream os = exchange.getResponseBody()) {
            os.write(bytes);
        }
    }

    private static void sleep(int delayMs) {
        try {
            Thread.sleep(delayMs);
        } catch (InterruptedException ignored) {
            Thread.currentThread().interrupt();
        }
    }

    private static long cpuWork(int iterations) {
        long value = 0;
        for (int i = 0; i < iterations; i++) {
            value += (i % 13);
        }
        return value;
    }

    private static String payloadOfKb(int payloadKb) {
        return "x".repeat(Math.max(0, payloadKb) * 1024);
    }

    private static int boundedInt(String raw, int min, int max) {
        try {
            int parsed = Integer.parseInt(raw);
            return Math.max(min, Math.min(parsed, max));
        } catch (NumberFormatException e) {
            return min;
        }
    }

    private static double round2(double value) {
        return Math.round(value * 100.0) / 100.0;
    }

    private static String escape(String value) {
        return value == null ? "" : value.replace("\\", "\\\\").replace("\"", "\\\"");
    }

    private static Map<String, String> queryParams(String rawQuery) {
        Map<String, String> params = new HashMap<>();
        if (rawQuery == null || rawQuery.isBlank()) {
            return params;
        }
        for (String pair : rawQuery.split("&")) {
            String[] parts = pair.split("=", 2);
            String key = URLDecoder.decode(parts[0], StandardCharsets.UTF_8);
            String value = parts.length > 1 ? URLDecoder.decode(parts[1], StandardCharsets.UTF_8) : "";
            params.put(key, value);
        }
        return params;
    }

    private static class Metrics {
        private int requests;
        private final List<Double> samplesMs;
        private final int maxSamples;
        private String lastPath;
        private int lastStatus;
        private String lastUpdated;

        Metrics(int maxSamples) {
            this.maxSamples = maxSamples;
            this.samplesMs = new ArrayList<>();
            this.lastStatus = 200;
        }

        synchronized void record(String path, int status, double elapsedMs) {
            requests += 1;
            samplesMs.add(elapsedMs);
            if (samplesMs.size() > maxSamples) {
                samplesMs.remove(0);
            }
            lastPath = path;
            lastStatus = status;
            lastUpdated = Instant.now().toString();
        }

        synchronized void reset() {
            requests = 0;
            samplesMs.clear();
            lastPath = null;
            lastStatus = 200;
            lastUpdated = Instant.now().toString();
        }

        synchronized String toJson() {
            double avg = 0.0;
            if (!samplesMs.isEmpty()) {
                double sum = 0.0;
                for (double sample : samplesMs) {
                    sum += sample;
                }
                avg = round2(sum / samplesMs.size());
            }
            return "{" +
                    "\"stack\":\"Java\"," +
                    "\"case\":\"01 - API lenta bajo carga\"," +
                    "\"requests_tracked\":" + requests + "," +
                    "\"sample_count\":" + samplesMs.size() + "," +
                    "\"avg_ms\":" + avg + "," +
                    "\"p95_ms\":" + percentile(samplesMs, 95) + "," +
                    "\"p99_ms\":" + percentile(samplesMs, 99) + "," +
                    "\"last_path\":\"" + escape(lastPath) + "\"," +
                    "\"last_status\":" + lastStatus + "," +
                    "\"last_updated\":\"" + escape(lastUpdated) + "\"," +
                    "\"note\":\"Métrica simple, en proceso único, pensada para laboratorio. No reemplaza observabilidad real.\"}";
        }

        private double percentile(List<Double> values, int percent) {
            if (values.isEmpty()) {
                return 0.0;
            }
            List<Double> ordered = new ArrayList<>(values);
            Collections.sort(ordered);
            int index = Math.max(0, Math.min(ordered.size() - 1, (int) Math.ceil((percent / 100.0) * ordered.size()) - 1));
            return round2(ordered.get(index));
        }
    }
}
