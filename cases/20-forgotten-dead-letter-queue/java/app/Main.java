// Caso 20 — La dead letter queue olvidada — stack Java 21.
//
// Cierra el arco que abrio el caso 15: alli la DLQ nace, como la politica de
// rechazo que salva al productor de bloquearse. Aca se ve que pasa cuando nadie
// vuelve a mirarla.
//
// Silencioso: el consumidor falla, manda el mensaje a la DLQ y sigue. Sin
// clasificar, sin reintentar, sin medir, sin alerta. La cola crece durante meses
// y el pipeline se ve sano: throughput normal, cero errores — porque los errores
// se fueron a otro lado.
//
// Observado: el error se clasifica antes de decidir. Lo transitorio se reintenta
// y casi todo se recupera; lo venenoso va a la DLQ con su clase y una muestra del
// payload; la profundidad y la antiguedad se publican; hay umbral.
//
// La distincion que ordena el caso:
//
//   transitorio  — el mismo mensaje funciona en el proximo intento
//   venenoso     — el mismo mensaje NUNCA va a funcionar
//
//   Reintentar lo venenoso es quemar CPU. Mandar lo transitorio a la DLQ es
//   tirar trabajo que se podia salvar. El consumidor que no distingue hace las
//   dos cosas mal a la vez.
//
// Primitiva Java distintiva:
//
//   **La jerarquia de excepciones es el mecanismo de clasificacion nativo de la
//   plataforma**, y es el mas expresivo del laboratorio para este caso:
//
//       sealed class ErrorProceso extends RuntimeException
//           permits ErrorTransitorio, ErrorVenenoso { }
//
//       catch (ErrorTransitorio e) { reintentar(); }
//       catch (ErrorVenenoso e)    { aDLQ(msg, e.clase()); }
//
//   `sealed` (Java 17) es lo que acerca a Java a la exhaustividad de Rust: la
//   jerarquia queda cerrada, y una clase nueva **tiene que declararse en el
//   `permits`**. Con `switch` sobre patrones de tipo, el compilador exige que se
//   cubran todas las ramas.
//
//   Y el multi-catch —`catch (A | B e)`— dice «estos dos se tratan igual» sin
//   duplicar el bloque.
//
//   Lo que Java pierde contra .NET: **para clasificar hay que capturar**, y
//   capturar desenrolla la pila. Cuando se relanza para que el caller decida, el
//   stack trace original ya se acorto. .NET tiene filtros de excepcion —
//   `catch (Ex e) when (...)`— que deciden **antes** de desenrollar, y eso es
//   exactamente el dato que un registro de DLQ necesita para ser util.
//
//   El otro riesgo, que en Java es cultural: `catch (Exception e)` en el
//   consumidor manda a la DLQ tambien los bugs del propio codigo —un NPE de un
//   refactor a medias— y esos mensajes no son venenosos. Son correctos, y el
//   codigo esta roto.

import com.sun.net.httpserver.HttpExchange;
import com.sun.net.httpserver.HttpServer;

import java.io.OutputStream;
import java.net.InetSocketAddress;
import java.nio.charset.StandardCharsets;
import java.time.Instant;
import java.time.temporal.ChronoUnit;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.TreeMap;
import java.util.concurrent.Executors;

public class Main {

    static final String APP_STACK = System.getenv().getOrDefault("APP_STACK", "Java 21");
    static final String CASE_NAME = "20 - La dead letter queue olvidada";
    static final String[] POISON_CLASSES = {"schema_mismatch", "unknown_field", "null_required", "invalid_encoding"};

    static final long START = System.nanoTime();

    static double nowMs() {
        return (System.nanoTime() - START) / 1_000_000.0;
    }

    /** Jerarquia sellada: una clase de error nueva TIENE que declararse aqui. */
    abstract static sealed class ErrorProceso extends RuntimeException
            permits ErrorTransitorio, ErrorVenenoso {
        ErrorProceso(String m) {
            super(m, null, false, false);   // sin stack trace: es un lazo caliente
        }
    }

    /** El mismo mensaje funciona en el proximo intento. */
    static final class ErrorTransitorio extends ErrorProceso {
        ErrorTransitorio(String m) {
            super(m);
        }
    }

    /** El mismo mensaje NUNCA va a funcionar. */
    static final class ErrorVenenoso extends ErrorProceso {
        final String clase;

        ErrorVenenoso(String clase) {
            super("mensaje venenoso: " + clase);
            this.clase = clase;
        }
    }

    record Sample(int idx, String payload) { }

    static final class Dead {
        final String id;
        final String errorClass;
        int attempts;
        final double firstSeenMs;
        final Sample sample;

        Dead(String id, String errorClass, int attempts, double firstSeenMs, Sample sample) {
            this.id = id;
            this.errorClass = errorClass;
            this.attempts = attempts;
            this.firstSeenMs = firstSeenMs;
            this.sample = sample;
        }
    }

    static final class Slot {
        int runs, consumed, succeeded, retried, deadLettered, alertsFired;
    }

    static final Object LOCK = new Object();
    static List<Dead> dlq = new ArrayList<>();
    static int alertsFired = 0;
    static Map<String, Slot> metrics = newMetrics();

    static Map<String, Slot> newMetrics() {
        Map<String, Slot> m = new LinkedHashMap<>();
        m.put("silent", new Slot());
        m.put("observed", new Slot());
        return m;
    }

    static double round(double v, int d) {
        double f = Math.pow(10, d);
        return Math.round(v * f) / f;
    }

    /**
     * Procesa un mensaje. Lanza transitorio o venenoso segun el mensaje.
     * El transitorio falla solo en el primer intento: es la definicion de
     * transitorio, y es lo que hace que reintentarlo tenga sentido.
     */
    static void procesar(int idx, int transientPct, int poisonPct, int attempt) {
        if (Math.floorMod((long) idx * 53, 101) < poisonPct) {
            throw new ErrorVenenoso(POISON_CLASSES[idx % POISON_CLASSES.length]);
        }
        if (Math.floorMod((long) idx * 37, 101) < transientPct && attempt == 0) {
            throw new ErrorTransitorio("timeout del downstream");
        }
    }

    // -----------------------------------------------------------------------
    // Variante silenciosa: cualquier fallo va a la DLQ, y nadie vuelve
    // -----------------------------------------------------------------------

    static Map<String, Object> consumeSilent(int messages, int transientPct, int poisonPct) {
        synchronized (LOCK) {
            dlq = new ArrayList<>();
            alertsFired = 0;
        }
        int consumed = 0, succeeded = 0, deadCount = 0;
        double t0 = nowMs();

        for (int i = 0; i < messages; i++) {
            consumed++;
            try {
                procesar(i, transientPct, poisonPct, 0);
                succeeded++;
            } catch (RuntimeException e) {
                // El bug entero. `catch (Exception)` no mira QUE error es, no
                // reintenta, y no guarda por que fallo. Ademas se traga los bugs
                // del propio consumidor junto con los datos malos.
                synchronized (LOCK) {
                    dlq.add(new Dead("msg-" + i, "unclassified", 1, nowMs(), null));
                }
                deadCount++;
            }
        }

        Map<String, Object> out = new LinkedHashMap<>();
        out.put("consumed", consumed);
        out.put("succeeded", succeeded);
        out.put("retried", 0);
        out.put("dead_lettered", deadCount);
        out.put("alerts_fired", 0);
        out.put("sampled", 0);
        out.put("wall_ms", round(nowMs() - t0, 2));
        return out;
    }

    // -----------------------------------------------------------------------
    // Variante observada: clasificar, reintentar, medir, alertar
    // -----------------------------------------------------------------------

    static Map<String, Object> consumeObserved(int messages, int transientPct, int poisonPct,
                                               int maxRetries, int alertThreshold, int sampleSize) {
        synchronized (LOCK) {
            dlq = new ArrayList<>();
            alertsFired = 0;
        }
        int consumed = 0, succeeded = 0, retried = 0, deadCount = 0, sampled = 0;
        double t0 = nowMs();

        for (int i = 0; i < messages; i++) {
            consumed++;
            for (int attempt = 0; attempt <= maxRetries; attempt++) {
                try {
                    procesar(i, transientPct, poisonPct, attempt);
                    succeeded++;
                    break;
                } catch (ErrorTransitorio e) {
                    // Transitorio: el proximo intento tiene otra suerte.
                    // Mandarlo a la DLQ seria tirar trabajo que se podia salvar.
                    retried++;
                    if (attempt == maxRetries) {
                        synchronized (LOCK) {
                            dlq.add(new Dead("msg-" + i, "transient_exhausted", attempt + 1, nowMs(), null));
                        }
                        deadCount++;
                    }
                } catch (ErrorVenenoso e) {
                    // Venenoso: reintentarlo es quemar CPU. Va a la DLQ ya
                    // mismo, con su clase y —para los primeros— una muestra.
                    Sample muestra = null;
                    if (sampled < sampleSize) {
                        muestra = new Sample(i, "{\"id\": " + i + ", \"campo\": \"...\"}");
                        sampled++;
                    }
                    synchronized (LOCK) {
                        dlq.add(new Dead("msg-" + i, e.clase, attempt + 1, nowMs(), muestra));
                    }
                    deadCount++;
                    break;
                }
                // No hay `catch (Exception)`: un error que no supimos clasificar
                // NO va a la DLQ. Con la jerarquia `sealed`, agregar una clase
                // nueva obliga a declararla en el `permits`.
            }
        }

        int alerts = 0;
        synchronized (LOCK) {
            if (dlq.size() > alertThreshold) {
                alertsFired++;
                alerts = 1;
            }
        }

        Map<String, Object> out = new LinkedHashMap<>();
        out.put("consumed", consumed);
        out.put("succeeded", succeeded);
        out.put("retried", retried);
        out.put("dead_lettered", deadCount);
        out.put("alerts_fired", alerts);
        out.put("sampled", sampled);
        out.put("wall_ms", round(nowMs() - t0, 2));
        return out;
    }

    // -----------------------------------------------------------------------
    // La DLQ como cola observable, no como agujero
    // -----------------------------------------------------------------------

    static Map<String, Object> dlqStats(int alertThreshold) {
        Map<String, Object> out = new LinkedHashMap<>();
        synchronized (LOCK) {
            Map<String, Integer> porClase = new TreeMap<>();
            for (Dead m : dlq) porClase.merge(m.errorClass, 1, Integer::sum);

            double now = nowMs();
            double oldest = 0;
            for (Dead m : dlq) oldest = Math.max(oldest, now - m.firstSeenMs);

            List<Map<String, Object>> muestras = new ArrayList<>();
            for (Dead m : dlq) {
                if (m.sample != null && muestras.size() < 5) {
                    Map<String, Object> s = new LinkedHashMap<>();
                    s.put("idx", m.sample.idx());
                    s.put("payload", m.sample.payload());
                    muestras.add(s);
                }
            }

            out.put("dlq_depth", dlq.size());
            out.put("dlq_oldest_msg_age_ms", round(oldest, 2));
            out.put("by_error_class", new LinkedHashMap<String, Object>(porClase));
            out.put("alert_threshold", alertThreshold);
            out.put("over_threshold", dlq.size() > alertThreshold);
            out.put("alerts_fired", alertsFired);
            out.put("samples", muestras);
        }
        out.put("note", "Una DLQ sin profundidad publicada, sin antiguedad del mensaje mas viejo y sin desglose "
                + "por clase de error no es una cola: es un agujero. by_error_class convierte 'hay 4.000 mensajes' "
                + "en 'hay un bug de schema y tres timeouts'.");
        return out;
    }

    /**
     * Replay desde la DLQ. Lo que se recupera vuelve; lo venenoso sigue ahi.
     * Es la mitad que casi nunca se construye: una DLQ que solo recibe es un
     * cementerio; una de la que se puede volver es un buffer.
     */
    static Map<String, Object> dlqDrain(int limit, int transientPct, int poisonPct, int maxRetries) {
        double t0 = nowMs();
        List<Dead> lote;
        List<Dead> resto;
        synchronized (LOCK) {
            int n = Math.min(limit, dlq.size());
            lote = new ArrayList<>(dlq.subList(0, n));
            resto = new ArrayList<>(dlq.subList(n, dlq.size()));
        }

        int ok = 0, fallo = 0;
        List<Dead> quedan = new ArrayList<>();
        for (Dead m : lote) {
            int idx = Integer.parseInt(m.id.substring(4));
            boolean recuperado = false;
            for (int attempt = 1; attempt <= maxRetries; attempt++) {
                try {
                    procesar(idx, transientPct, poisonPct, attempt);
                    recuperado = true;
                    break;
                } catch (ErrorTransitorio e) {
                    // sigue intentando
                } catch (ErrorVenenoso e) {
                    break;
                }
            }
            if (recuperado) {
                ok++;
            } else {
                fallo++;
                m.attempts += maxRetries;
                quedan.add(m);
            }
        }

        int depth;
        synchronized (LOCK) {
            quedan.addAll(resto);
            dlq = quedan;
            depth = dlq.size();
        }

        Map<String, Object> out = new LinkedHashMap<>();
        out.put("drain_limit", limit);
        out.put("drained_ok", ok);
        out.put("drain_failed", fallo);
        out.put("recovered_pct", round(ok * 100.0 / Math.max(1, ok + fallo), 2));
        out.put("drain_duration_ms", round(nowMs() - t0, 2));
        out.put("dlq_depth_after", depth);
        out.put("note", "Lo que se recupera en el replay es exactamente lo que nunca deberia haber estado aca: "
                + "errores transitorios que un reintento habria resuelto. Lo que sigue fallando es veneno de "
                + "verdad, y necesita un cambio de codigo o de datos — no otro reintento.");
        return out;
    }

    static Map<String, Object> runScenario(String variant, int messages, int transientPct, int poisonPct,
                                           int maxRetries, int alertThreshold, int sampleSize) {
        Map<String, Object> r = "silent".equals(variant)
                ? consumeSilent(messages, transientPct, poisonPct)
                : consumeObserved(messages, transientPct, poisonPct, maxRetries, alertThreshold, sampleSize);
        Map<String, Object> stats = dlqStats(alertThreshold);

        synchronized (LOCK) {
            Slot s = metrics.get(variant);
            s.runs++;
            s.consumed += (int) r.get("consumed");
            s.succeeded += (int) r.get("succeeded");
            s.retried += (int) r.get("retried");
            s.deadLettered += (int) r.get("dead_lettered");
            s.alertsFired += (int) r.get("alerts_fired");
        }

        Map<String, Object> out = new LinkedHashMap<>();
        out.put("variant", variant);
        out.put("messages", messages);
        out.put("transient_pct", transientPct);
        out.put("poison_pct", poisonPct);
        out.put("max_retries", "observed".equals(variant) ? maxRetries : 0);
        out.putAll(r);
        for (String k : new String[]{"dlq_depth", "dlq_oldest_msg_age_ms", "by_error_class",
                                     "alert_threshold", "over_threshold"}) {
            out.put(k, stats.get(k));
        }
        out.put("dead_letter_rate_pct",
                round((int) r.get("dead_lettered") * 100.0 / Math.max(1, (int) r.get("consumed")), 2));
        out.put("note", "silent".equals(variant)
                ? "El consumidor no clasifico nada: transitorio y venenoso fueron al mismo lugar, sin reintentar y "
                + "sin registrar por que. El pipeline se ve sano —throughput normal, cero errores— porque los "
                + "errores se fueron a otro lado. Y nadie va a volver."
                : "Lo transitorio se reintento y casi todo se recupero; solo el veneno llego a la DLQ, con su clase "
                + "de error y una muestra del payload. La profundidad esta publicada y el umbral disparo alerta.");
        out.put("java_note", "La jerarquia sellada de excepciones es el mecanismo de clasificacion nativo y el mas "
                + "expresivo del set: `sealed ... permits` obliga a declarar una clase nueva. Lo que pierde contra "
                + ".NET es que para clasificar hay que capturar, y capturar desenrolla la pila: al relanzar, el "
                + "stack trace original ya se acorto.");
        return out;
    }

    static Map<String, Object> diagnostics(int alertThreshold) {
        Map<String, Object> variants = new LinkedHashMap<>();
        synchronized (LOCK) {
            for (String name : new String[]{"silent", "observed"}) {
                Slot s = metrics.get(name);
                Map<String, Object> m = new LinkedHashMap<>();
                m.put("runs", s.runs);
                m.put("consumed", s.consumed);
                m.put("succeeded", s.succeeded);
                m.put("retried", s.retried);
                m.put("dead_lettered", s.deadLettered);
                m.put("alerts_fired", s.alertsFired);
                variants.put(name, m);
            }
        }
        Map<String, Object> fidelity = new LinkedHashMap<>();
        fidelity.put("real", "La clasificacion de errores, el reintento con presupuesto acotado, el desglose por "
                + "clase, el muestreo de payloads y el replay desde la DLQ son codigo de verdad.");
        fidelity.put("modelado", "La DLQ es una lista en memoria, no SQS ni RabbitMQ. La clase de error de cada "
                + "mensaje es deterministica para que el escenario sea reproducible.");
        fidelity.put("honesto", "Lo que define el caso no es el broker: es que un mensaje que falla tiene que ir a "
                + "algun lado, y que ese lado necesita profundidad, antiguedad, clasificacion y una salida.");

        Map<String, Object> interp = new LinkedHashMap<>();
        interp.put("silent", "dead_letter_rate_pct alto, by_error_class con una sola entrada ('unclassified') y "
                + "alerts_fired en cero. El pipeline se ve sano.");
        interp.put("observed", "dead_letter_rate_pct bajo —solo el veneno—, by_error_class desglosado y la alerta "
                + "disparada.");
        interp.put("java_note", "`catch (Exception e)` en el consumidor manda a la DLQ tambien los bugs del propio "
                + "codigo. Esos mensajes no son venenosos: son correctos, y el codigo esta roto.");

        Map<String, Object> out = new LinkedHashMap<>();
        out.put("stack", APP_STACK);
        out.put("case", CASE_NAME);
        out.put("variants", variants);
        out.put("dlq", dlqStats(alertThreshold));
        out.put("arco_con_el_caso_15", "En el caso 15 la DLQ NACE: es la politica de rechazo que salva al productor "
                + "de bloquearse cuando la cola se llena. Aca se ve que pasa cuando nadie vuelve a mirarla.");
        out.put("fidelity", fidelity);
        out.put("interpretation", interp);
        return out;
    }

    // -----------------------------------------------------------------------
    // HTTP
    // -----------------------------------------------------------------------

    static int clampInt(int v, int lo, int hi) {
        return Math.max(lo, Math.min(hi, v));
    }

    static int queryInt(Map<String, String> q, String key, int def) {
        String raw = q.get(key);
        if (raw == null) return def;
        try {
            return Integer.parseInt(raw);
        } catch (NumberFormatException e) {
            return def;
        }
    }

    static Map<String, String> parseQuery(String raw) {
        Map<String, String> out = new LinkedHashMap<>();
        if (raw == null || raw.isEmpty()) return out;
        for (String pair : raw.split("&")) {
            int i = pair.indexOf('=');
            if (i > 0) out.put(pair.substring(0, i), pair.substring(i + 1));
        }
        return out;
    }

    static String toJson(Object v, int indent) {
        String pad = "  ".repeat(indent);
        String padIn = "  ".repeat(indent + 1);
        if (v == null) return "null";
        if (v instanceof Map<?, ?> m) {
            if (m.isEmpty()) return "{}";
            StringBuilder sb = new StringBuilder("{\n");
            int i = 0;
            for (Map.Entry<?, ?> e : m.entrySet()) {
                sb.append(padIn).append(quote(String.valueOf(e.getKey()))).append(": ")
                  .append(toJson(e.getValue(), indent + 1));
                if (++i < m.size()) sb.append(',');
                sb.append('\n');
            }
            return sb.append(pad).append('}').toString();
        }
        if (v instanceof List<?> l) {
            if (l.isEmpty()) return "[]";
            StringBuilder sb = new StringBuilder("[\n");
            for (int i = 0; i < l.size(); i++) {
                sb.append(padIn).append(toJson(l.get(i), indent + 1));
                if (i < l.size() - 1) sb.append(',');
                sb.append('\n');
            }
            return sb.append(pad).append(']').toString();
        }
        if (v instanceof Number || v instanceof Boolean) return String.valueOf(v);
        return quote(String.valueOf(v));
    }

    static String quote(String s) {
        StringBuilder sb = new StringBuilder("\"");
        for (char c : s.toCharArray()) {
            switch (c) {
                case '"' -> sb.append("\\\"");
                case '\\' -> sb.append("\\\\");
                case '\n' -> sb.append("\\n");
                case '\r' -> sb.append("\\r");
                case '\t' -> sb.append("\\t");
                default -> sb.append(c);
            }
        }
        return sb.append('"').toString();
    }

    public static void main(String[] args) throws Exception {
        int port = Integer.parseInt(System.getenv().getOrDefault("PORT", "8080"));
        HttpServer server = HttpServer.create(new InetSocketAddress("0.0.0.0", port), 0);
        server.setExecutor(Executors.newCachedThreadPool());
        server.createContext("/", Main::handle);
        System.out.println("Servidor Java escuchando en " + port);
        server.start();
    }

    static void handle(HttpExchange ex) throws java.io.IOException {
        String uri = ex.getRequestURI().getPath();
        Map<String, String> q = parseQuery(ex.getRequestURI().getRawQuery());

        int messages = clampInt(queryInt(q, "messages", 3000), 10, 200000);
        int transientPct = clampInt(queryInt(q, "transient_pct", 12), 0, 100);
        int poisonPct = clampInt(queryInt(q, "poison_pct", 4), 0, 100);
        int maxRetries = clampInt(queryInt(q, "max_retries", 3), 0, 20);
        int alertThreshold = clampInt(queryInt(q, "alert_threshold", 50), 0, 100000);
        int sampleSize = clampInt(queryInt(q, "sample_size", 20), 0, 1000);
        int limit = clampInt(queryInt(q, "limit", 500), 1, 200000);

        int status = 200;
        Map<String, Object> payload;

        switch (uri) {
            case "/", "/index" -> {
                Map<String, String> routes = new LinkedHashMap<>();
                routes.put("/health", "Estado basico del servicio.");
                routes.put("/consume-silent?messages=3000", "Cualquier fallo a la DLQ, sin clasificar ni reintentar.");
                routes.put("/consume-observed?messages=3000", "Clasificar, reintentar lo transitorio, alertar.");
                routes.put("/dlq/stats", "Profundidad, antiguedad del mas viejo y desglose por clase de error.");
                routes.put("/dlq/drain?limit=500", "Replay desde la DLQ: que se recupera y que sigue siendo veneno.");
                routes.put("/diagnostics/summary", "Comparativa entre variantes.");
                routes.put("/reset-lab", "Vacia la DLQ y las metricas.");
                payload = new LinkedHashMap<>();
                payload.put("lab", "Problem-Driven Systems Lab");
                payload.put("case", CASE_NAME);
                payload.put("stack", APP_STACK);
                payload.put("goal", "Mostrar que un pipeline con throughput normal y cero errores puede estar "
                        + "perdiendo el 16% de los mensajes, porque los errores se fueron a un lugar que nadie mira.");
                payload.put("arco", "Cierra el arco del caso 15, donde la DLQ nace como politica de rechazo.");
                payload.put("java_specific", "Jerarquia `sealed` de excepciones: el mecanismo de clasificacion mas "
                        + "expresivo del set, con el costo de que capturar desenrolla la pila.");
                payload.put("routes", routes);
            }
            case "/health" -> {
                payload = new LinkedHashMap<>();
                payload.put("status", "ok");
                payload.put("stack", APP_STACK);
                payload.put("case", CASE_NAME);
            }
            case "/consume-silent" -> payload = runScenario("silent", messages, transientPct, poisonPct,
                    maxRetries, alertThreshold, sampleSize);
            case "/consume-observed" -> payload = runScenario("observed", messages, transientPct, poisonPct,
                    maxRetries, alertThreshold, sampleSize);
            case "/dlq/stats" -> payload = dlqStats(alertThreshold);
            case "/dlq/drain" -> payload = dlqDrain(limit, transientPct, poisonPct, maxRetries);
            case "/diagnostics/summary" -> payload = diagnostics(alertThreshold);
            case "/reset-lab" -> {
                synchronized (LOCK) {
                    dlq = new ArrayList<>();
                    alertsFired = 0;
                    metrics = newMetrics();
                }
                payload = new LinkedHashMap<>();
                payload.put("status", "reset");
                payload.put("message", "DLQ y metricas reiniciadas.");
            }
            default -> {
                status = 404;
                payload = new LinkedHashMap<>();
                payload.put("error", "Ruta no encontrada");
                payload.put("path", uri);
            }
        }

        payload.put("timestamp_utc", Instant.now().truncatedTo(ChronoUnit.SECONDS).toString());
        payload.put("pid", ProcessHandle.current().pid());

        byte[] body = toJson(payload, 0).getBytes(StandardCharsets.UTF_8);
        ex.getResponseHeaders().set("Content-Type", "application/json; charset=utf-8");
        ex.sendResponseHeaders(status, body.length);
        try (OutputStream os = ex.getResponseBody()) {
            os.write(body);
        }
    }
}
