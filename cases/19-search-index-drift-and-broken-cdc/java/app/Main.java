// Caso 19 — Deriva del indice de busqueda y CDC roto — stack Java 21.
//
// Dual-write: la aplicacion escribe en la base y despues en el indice. Cuando la
// segunda escritura falla —y falla, porque son dos sistemas sin transaccion
// comun— nadie se entera. La busqueda sigue respondiendo 200; lo que devuelve
// esta mal.
//
// Outbox + checkpoint + reconciliacion: el cambio se anota junto con la escritura
// a la base, el consumidor aplica en orden y solo avanza el checkpoint cuando la
// aplicacion se confirma, y un barrido repara lo que los dos primeros no cubren.
//
// Las tres formas de deriva, que no son la misma cosa:
//
//   missing  — esta en la base, no en el indice      → la busqueda no lo encuentra
//   stale    — esta en los dos, con version vieja    → la busqueda lo encuentra mal
//   orphan   — esta en el indice, borrado en la base → la busqueda devuelve fantasmas
//
// Primitiva Java distintiva — y en este caso el framework es parte del problema:
//
//   **`@Transactional` hace que el dual-write PAREZCA atomico.** El metodo
//   completo esta dentro de una transaccion, el codigo se lee como una unidad, y
//   el indice de busqueda no participa de esa transaccion:
//
//       @Transactional
//       public void guardar(Documento d) {
//           repo.save(d);              // participa de la transaccion
//           buscador.indexar(d);       // NO participa: es HTTP a otro sistema
//       }
//
//   Si `indexar` lanza, el `save` se revierte — pero si `indexar` falla en
//   silencio, o si el commit falla DESPUES de indexar, los dos lados quedan
//   distintos. La anotacion no miente: cubre lo que puede cubrir. Lo que engaña
//   es que **no hay nada en el codigo que marque donde termina su alcance**.
//
//   La contraparte, y Java la tiene bien: `ConcurrentSkipListMap` da un outbox
//   ordenado por secuencia con `tailMap(checkpoint, false)` como consulta
//   natural de "lo pendiente", y `Set.removeAll` / `retainAll` expresan el diff
//   de tres caras sin escribir el recorrido a mano — algo que Go no puede.

import com.sun.net.httpserver.HttpExchange;
import com.sun.net.httpserver.HttpServer;

import java.io.OutputStream;
import java.net.InetSocketAddress;
import java.nio.charset.StandardCharsets;
import java.time.Instant;
import java.time.temporal.ChronoUnit;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.TreeSet;
import java.util.concurrent.ConcurrentSkipListMap;
import java.util.concurrent.Executors;

public class Main {

    static final String APP_STACK = System.getenv().getOrDefault("APP_STACK", "Java 21");
    static final String CASE_NAME = "19 - Deriva del indice de busqueda y CDC roto";
    static final String[] TERMS = {"alfa", "beta", "gamma", "delta", "epsilon", "zeta", "eta", "theta"};

    static final long START = System.nanoTime();

    static double nowMs() {
        return (System.nanoTime() - START) / 1_000_000.0;
    }

    record Doc(int version, String term, boolean deleted, double updatedMs) { }

    record IdxEntry(int version, String term) { }

    record Change(long seq, String id, int version, String term, boolean deleted, double atMs) { }

    static final class Slot {
        int runs, writes, silentFailures, driftCount, outboxRetried;
    }

    static final Object LOCK = new Object();
    static Map<String, Doc> db = new HashMap<>();
    static Map<String, IdxEntry> index = new HashMap<>();
    // Outbox ordenado por secuencia: `tailMap(checkpoint, false)` es la consulta
    // natural de "lo que falta aplicar", sin filtrar la lista entera.
    static ConcurrentSkipListMap<Long, Change> outbox = new ConcurrentSkipListMap<>();
    static long checkpoint = 0;
    static long seq = 0;
    static Map<String, Slot> metrics = newMetrics();

    static Map<String, Slot> newMetrics() {
        Map<String, Slot> m = new LinkedHashMap<>();
        m.put("drifted", new Slot());
        m.put("reconciled", new Slot());
        return m;
    }

    static void resetAll() {
        db = new HashMap<>();
        index = new HashMap<>();
        outbox = new ConcurrentSkipListMap<>();
        checkpoint = 0;
        seq = 0;
    }

    static double round(double v, int d) {
        double f = Math.pow(10, d);
        return Math.round(v * f) / f;
    }

    /**
     * El indice rechaza una fraccion de las escrituras.
     *
     * El modulo 101 —primo— importa: con 100, las dos escrituras del mismo
     * documento (i e i+keyspace) caen en el mismo residuo y corren siempre la
     * misma suerte, asi que nunca se produce deriva `stale`. Con 101 se separan.
     */
    static boolean indexWriteFails(long idx, int failRate) {
        return Math.floorMod(idx * 37, 101) < failRate;
    }

    /** La escritura al segundo sistema. Lanza, como lanzaria un cliente HTTP. */
    static void escribirIndice(String id, IdxEntry e, boolean borrar, long idx, int failRate) {
        if (indexWriteFails(idx, failRate)) {
            throw new IllegalStateException("el indice rechazo la escritura de " + id);
        }
        if (borrar) index.remove(id);
        else index.put(id, e);
    }

    // -----------------------------------------------------------------------
    // Variante dual-write: escribir en la base, escribir en el indice, y rezar
    // -----------------------------------------------------------------------

    static int runDrifted(int writes, int failRate, int deletePct) {
        synchronized (LOCK) {
            resetAll();
            int keyspace = Math.max(1, writes / 2);
            int silent = 0;

            for (int i = 0; i < writes; i++) {
                String id = "doc-" + (i % keyspace);
                String term = TERMS[i % TERMS.length];
                boolean deleting = Math.floorMod((long) i * 53, 101) < deletePct;

                Doc prev = db.get(id);
                int version = prev == null ? 1 : prev.version() + 1;
                db.put(id, new Doc(version, term, deleting, nowMs()));

                // AQUI ESTA EL BUG. El catch vacio es la version explicita; la
                // implicita —y la que de verdad ocurre— es que el commit de la
                // transaccion falle DESPUES de que el indice ya se escribio, o
                // que el cliente HTTP devuelva 202 y el indice nunca aplique.
                try {
                    escribirIndice(id, new IdxEntry(version, term), deleting, i, failRate);
                } catch (RuntimeException ignored) {
                    silent++;
                }
            }
            return silent;
        }
    }

    // -----------------------------------------------------------------------
    // Variante outbox + checkpoint + reconciliacion
    // -----------------------------------------------------------------------

    static int runReconciled(int writes, int failRate, int deletePct) {
        synchronized (LOCK) {
            resetAll();
            int keyspace = Math.max(1, writes / 2);

            for (int i = 0; i < writes; i++) {
                String id = "doc-" + (i % keyspace);
                String term = TERMS[i % TERMS.length];
                boolean deleting = Math.floorMod((long) i * 53, 101) < deletePct;

                Doc prev = db.get(id);
                int version = prev == null ? 1 : prev.version() + 1;
                db.put(id, new Doc(version, term, deleting, nowMs()));
                // El cambio se anota JUNTO con la escritura, en la MISMA
                // transaccion. Esto si es atomico: los dos son la base.
                seq++;
                outbox.put(seq, new Change(seq, id, version, term, deleting, nowMs()));
            }
            return drainOutbox(failRate, 5);
        }
    }

    /**
     * Aplica los cambios pendientes al indice, en orden, reintentando.
     *
     * <ul>
     *   <li><b>En orden</b>: saltear un cambio dejaria una version vieja pisando
     *       a una nueva. `tailMap(checkpoint, false)` da exactamente los
     *       pendientes, ya ordenados.</li>
     *   <li><b>El checkpoint avanza solo con la confirmacion</b>: si un cambio no
     *       entra despues de maxRetries, el consumidor se frena. El cambio queda
     *       pendiente, no perdido.</li>
     * </ul>
     */
    static int drainOutbox(int failRate, int maxRetries) {
        int retried = 0;
        for (Change entry : new ArrayList<>(outbox.tailMap(checkpoint, false).values())) {
            boolean applied = false;
            for (int attempt = 0; attempt < maxRetries; attempt++) {
                try {
                    escribirIndice(entry.id(), new IdxEntry(entry.version(), entry.term()),
                            entry.deleted(), entry.seq() * (attempt + 1L) + attempt, failRate);
                    applied = true;
                    break;
                } catch (RuntimeException e) {
                    retried++;
                }
            }
            if (!applied) break;   // el checkpoint se frena: el cambio queda pendiente
            checkpoint = entry.seq();
        }
        return retried;
    }

    // -----------------------------------------------------------------------
    // La deriva de tres caras, con las operaciones de conjunto de la stdlib
    // -----------------------------------------------------------------------

    static Map<String, Object> computeDriftLocked() {
        Map<String, Doc> dbLive = new HashMap<>();
        db.forEach((k, v) -> { if (!v.deleted()) dbLive.put(k, v); });

        Set<String> missing = new HashSet<>(dbLive.keySet());
        missing.removeAll(index.keySet());

        Set<String> orphan = new HashSet<>(index.keySet());
        orphan.removeAll(dbLive.keySet());

        Set<String> comunes = new HashSet<>(dbLive.keySet());
        comunes.retainAll(index.keySet());
        Set<String> stale = new HashSet<>();
        for (String id : comunes) {
            if (index.get(id).version() != dbLive.get(id).version()) stale.add(id);
        }

        double now = nowMs();
        double oldest = 0;
        for (String id : missing) oldest = Math.max(oldest, now - dbLive.get(id).updatedMs());
        for (String id : stale) oldest = Math.max(oldest, now - dbLive.get(id).updatedMs());

        Map<String, Object> out = new LinkedHashMap<>();
        out.put("db_count", dbLive.size());
        out.put("index_count", index.size());
        out.put("missing", missing.size());
        out.put("stale", stale.size());
        out.put("orphan", orphan.size());
        out.put("drift_count", missing.size() + stale.size() + orphan.size());
        out.put("drift_age_ms", round(oldest, 2));
        out.put("missing_ids", new ArrayList<>(new TreeSet<>(missing)).stream().limit(8).toList());
        out.put("orphan_ids", new ArrayList<>(new TreeSet<>(orphan)).stream().limit(8).toList());
        out.put("last_checkpoint", checkpoint);
        out.put("outbox_pending", outbox.tailMap(checkpoint, false).size());
        return out;
    }

    static Map<String, Object> computeDrift() {
        synchronized (LOCK) {
            return computeDriftLocked();
        }
    }

    static Map<String, Object> reconcile() {
        double t0 = nowMs();
        Map<String, Object> before;
        Map<String, Object> after;
        synchronized (LOCK) {
            before = computeDriftLocked();
            Map<String, Doc> dbLive = new HashMap<>();
            db.forEach((k, v) -> { if (!v.deleted()) dbLive.put(k, v); });
            dbLive.forEach((id, d) -> {
                IdxEntry cur = index.get(id);
                if (cur == null || cur.version() != d.version()) index.put(id, new IdxEntry(d.version(), d.term()));
            });
            index.keySet().removeIf(id -> !dbLive.containsKey(id));
            after = computeDriftLocked();
        }
        int bc = (int) before.get("drift_count");
        int ac = (int) after.get("drift_count");
        Map<String, Object> detail = new LinkedHashMap<>();
        detail.put("missing", before.get("missing"));
        detail.put("stale", before.get("stale"));
        detail.put("orphan", before.get("orphan"));

        Map<String, Object> out = new LinkedHashMap<>();
        out.put("reconcile_duration_ms", round(nowMs() - t0, 2));
        out.put("drift_before", bc);
        out.put("drift_after", ac);
        out.put("repaired", bc - ac);
        out.put("detail_before", detail);
        out.put("state", after);
        out.put("note", "El barrido es la red de seguridad de lo que el outbox no cubre: un indice restaurado de "
                + "un backup viejo, una reindexacion parcial, un borrado manual. Sin el, el outbox garantiza que "
                + "ningun cambio NUEVO se pierda — pero no arregla los que ya se perdieron.");
        return out;
    }

    // -----------------------------------------------------------------------
    // Las consultas: medir la deriva desde donde la ve el usuario
    // -----------------------------------------------------------------------

    static Map<String, Object> runQueries(int queries) {
        int hits = 0, expected = 0, returned = 0;
        synchronized (LOCK) {
            Map<String, Doc> dbLive = new HashMap<>();
            db.forEach((k, v) -> { if (!v.deleted()) dbLive.put(k, v); });
            for (int q = 0; q < queries; q++) {
                String term = TERMS[q % TERMS.length];
                Set<String> esperados = new HashSet<>();
                dbLive.forEach((id, d) -> { if (d.term().equals(term)) esperados.add(id); });
                for (Map.Entry<String, IdxEntry> e : index.entrySet()) {
                    if (e.getValue().term().equals(term)) {
                        returned++;
                        if (esperados.contains(e.getKey())) hits++;
                    }
                }
                expected += esperados.size();
            }
        }
        Map<String, Object> out = new LinkedHashMap<>();
        out.put("queries", queries);
        out.put("search_recall_pct", round(hits * 100.0 / Math.max(1, expected), 2));
        out.put("search_precision_pct", round(hits * 100.0 / Math.max(1, returned), 2));
        out.put("note", "Recall bajo = la busqueda no encuentra lo que existe. Precision baja = devuelve lo que ya "
                + "no existe. Las dos se ven como 'la busqueda anda rara', no como un error.");
        return out;
    }

    static Map<String, Object> runScenario(String variant, int writes, int failRate, int deletePct, int queries) {
        double t0 = nowMs();
        int silent = 0, retried = 0;
        if ("drifted".equals(variant)) {
            silent = runDrifted(writes, failRate, deletePct);
        } else {
            retried = runReconciled(writes, failRate, deletePct);
            reconcile();
        }

        Map<String, Object> drift = computeDrift();
        Map<String, Object> q = runQueries(queries);

        synchronized (LOCK) {
            Slot s = metrics.get(variant);
            s.runs++;
            s.writes += writes;
            s.silentFailures += silent;
            s.driftCount += (int) drift.get("drift_count");
            s.outboxRetried += retried;
        }

        Map<String, Object> out = new LinkedHashMap<>();
        out.put("variant", variant);
        out.put("writes", writes);
        out.put("fail_rate_pct", failRate);
        out.put("delete_pct", deletePct);
        out.put("silent_failures", silent);
        out.put("outbox_retried", retried);
        out.putAll(drift);
        out.putAll(q);
        out.put("wall_ms", round(nowMs() - t0, 2));
        out.put("note", "drifted".equals(variant)
                ? "La escritura al indice fallo y el codigo siguio como si nada. La base y el indice no comparten "
                + "transaccion, asi que la unica forma de enterarse es mirando — y nadie mira, porque la busqueda "
                + "sigue respondiendo 200."
                : "El outbox garantiza que ningun cambio nuevo se pierda, el checkpoint impide saltear uno, y el "
                + "barrido repara lo que los dos primeros no cubren. Deriva final: cero.");
        out.put("java_note", "@Transactional hace que el dual-write parezca atomico: el metodo se lee como una "
                + "unidad y el indice no participa de la transaccion. Lo que Java si aporta es "
                + "ConcurrentSkipListMap con tailMap(checkpoint, false) como consulta natural de lo pendiente, y "
                + "removeAll/retainAll para el diff de tres caras sin escribir el recorrido.");
        return out;
    }

    static Map<String, Object> indexState() {
        Map<String, Object> d = computeDrift();
        d.put("stack", APP_STACK);
        d.put("note", "`missing` no se encuentra, `stale` se encuentra mal y `orphan` es un fantasma. Las tres se "
                + "ven igual desde afuera — 'la busqueda anda rara' — y se arreglan distinto.");
        return d;
    }

    static Map<String, Object> diagnostics() {
        Map<String, Object> variants = new LinkedHashMap<>();
        synchronized (LOCK) {
            for (String name : new String[]{"drifted", "reconciled"}) {
                Slot s = metrics.get(name);
                Map<String, Object> m = new LinkedHashMap<>();
                m.put("runs", s.runs);
                m.put("writes", s.writes);
                m.put("silent_failures", s.silentFailures);
                m.put("drift_count", s.driftCount);
                m.put("outbox_retried", s.outboxRetried);
                variants.put(name, m);
            }
        }
        Map<String, Object> fidelity = new LinkedHashMap<>();
        fidelity.put("real", "El diff de tres caras, el outbox con orden y checkpoint, y el barrido de "
                + "reconciliacion son codigo de verdad, con la primitiva idiomatica de cada runtime.");
        fidelity.put("modelado", "El indice de busqueda es un HashMap en memoria, no Elasticsearch. La falla de "
                + "escritura es deterministica para que el escenario sea reproducible.");
        fidelity.put("honesto", "Lo que importa del caso no es el motor de busqueda: es que la base y el indice "
                + "son dos sistemas sin transaccion comun. Eso es igual de cierto con un HashMap.");

        Map<String, Object> interp = new LinkedHashMap<>();
        interp.put("drifted", "drift_count > 0 y recall por debajo de 100 con el servicio respondiendo 200 a todo.");
        interp.put("reconciled", "drift_count = 0, recall y precision en 100.");
        interp.put("java_note", "El riesgo de Java en este caso no es tecnico sino de lectura: @Transactional "
                + "sugiere una atomicidad que no alcanza al indice, y nada en el codigo marca donde termina.");

        Map<String, Object> out = new LinkedHashMap<>();
        out.put("stack", APP_STACK);
        out.put("case", CASE_NAME);
        out.put("variants", variants);
        out.put("index", indexState());
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

        int writes = clampInt(queryInt(q, "writes", 2000), 10, 200000);
        int failRate = clampInt(queryInt(q, "fail_rate", 8), 0, 100);
        int deletePct = clampInt(queryInt(q, "delete_pct", 5), 0, 50);
        int queries = clampInt(queryInt(q, "queries", 200), 1, 5000);

        int status = 200;
        Map<String, Object> payload;

        switch (uri) {
            case "/", "/index" -> {
                Map<String, String> routes = new LinkedHashMap<>();
                routes.put("/health", "Estado basico del servicio.");
                routes.put("/search-drifted?writes=2000&fail_rate=8", "Dual-write: el indice se desincroniza en silencio.");
                routes.put("/search-reconciled?writes=2000&fail_rate=8", "Outbox + checkpoint + barrido: deriva cero.");
                routes.put("/reconcile", "Un barrido suelto, para ver que encuentra y que repara.");
                routes.put("/index/state", "Las tres caras de la deriva y la antiguedad del cambio mas viejo.");
                routes.put("/diagnostics/summary", "Comparativa entre variantes.");
                routes.put("/reset-lab", "Vacia la base, el indice, el outbox y las metricas.");
                payload = new LinkedHashMap<>();
                payload.put("lab", "Problem-Driven Systems Lab");
                payload.put("case", CASE_NAME);
                payload.put("stack", APP_STACK);
                payload.put("goal", "Mostrar que una busqueda que responde 200 puede estar respondiendo mal, y que "
                        + "la unica forma de saberlo es comparar los dos lados a proposito.");
                payload.put("java_specific", "@Transactional hace parecer atomico un dual-write que no lo es; "
                        + "ConcurrentSkipListMap.tailMap es la consulta natural de lo pendiente.");
                payload.put("routes", routes);
            }
            case "/health" -> {
                payload = new LinkedHashMap<>();
                payload.put("status", "ok");
                payload.put("stack", APP_STACK);
                payload.put("case", CASE_NAME);
            }
            case "/search-drifted" -> payload = runScenario("drifted", writes, failRate, deletePct, queries);
            case "/search-reconciled" -> payload = runScenario("reconciled", writes, failRate, deletePct, queries);
            case "/reconcile" -> payload = reconcile();
            case "/index/state" -> payload = indexState();
            case "/diagnostics/summary" -> payload = diagnostics();
            case "/reset-lab" -> {
                synchronized (LOCK) {
                    resetAll();
                    metrics = newMetrics();
                }
                payload = new LinkedHashMap<>();
                payload.put("status", "reset");
                payload.put("message", "Base, indice, outbox y metricas reiniciados.");
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
