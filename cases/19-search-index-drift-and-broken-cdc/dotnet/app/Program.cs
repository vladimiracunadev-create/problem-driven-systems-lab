using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Globalization;
using System.Linq;
using System.Net;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using System.Threading.Tasks;

// Caso 19 — Deriva del indice de busqueda y CDC roto — stack .NET 8.
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
// Primitiva .NET distintiva:
//
//   **LINQ convierte el diagnostico en una consulta.** El diff de tres caras se
//   escribe una vez y se lee como su propia definicion:
//
//       var missing = db.Keys.Except(index.Keys);
//       var orphan  = index.Keys.Except(db.Keys);
//       var stale   = db.Join(index, d => d.Key, i => i.Key, (d, i) => (d, i))
//                       .Where(p => p.d.Value.Version != p.i.Value.Version);
//
//   Go no tiene tipo conjunto y lo escribe a mano; Java lo tiene pero mutando
//   copias con `removeAll`/`retainAll`; Python lo dice mas corto pero sin el
//   `Join` tipado. .NET es el unico que expresa las tres caras como **una sola
//   forma** —consultas sobre secuencias— con el compilador verificando los tipos
//   de las claves en cada paso.
//
//   Y la trampa que viene con eso: **LINQ es perezoso**. `Except` no ejecuta
//   nada hasta que alguien enumera, asi que un diagnostico calculado bajo un
//   lock y enumerado despues puede leer un estado distinto del que comparo.
//   Los `.ToList()` de este archivo no son adorno: son lo que fija el resultado
//   mientras el estado todavia es consistente.

internal static class Program
{
    private const string CaseName = "19 - Deriva del indice de busqueda y CDC roto";
    private static readonly string Stack = Environment.GetEnvironmentVariable("APP_STACK") ?? ".NET 8";
    private static readonly string[] Terms = { "alfa", "beta", "gamma", "delta", "epsilon", "zeta", "eta", "theta" };

    private static readonly Stopwatch Clock = Stopwatch.StartNew();
    private static double NowMs() => Clock.Elapsed.TotalMilliseconds;

    private sealed record Doc(int Version, string Term, bool Deleted, double UpdatedMs);

    private sealed record IdxEntry(int Version, string Term);

    private sealed record Change(long Seq, string Id, int Version, string Term, bool Deleted, double AtMs);

    private sealed class Slot
    {
        public int Runs, Writes, SilentFailures, DriftCount, OutboxRetried;
    }

    private static readonly object Lock = new();
    private static Dictionary<string, Doc> _db = new();
    private static Dictionary<string, IdxEntry> _index = new();
    private static SortedDictionary<long, Change> _outbox = new();
    private static long _checkpoint;
    private static long _seq;
    private static Dictionary<string, Slot> _metrics = NewMetrics();

    private static Dictionary<string, Slot> NewMetrics() =>
        new() { ["drifted"] = new Slot(), ["reconciled"] = new Slot() };

    private static void ResetAll()
    {
        _db = new Dictionary<string, Doc>();
        _index = new Dictionary<string, IdxEntry>();
        _outbox = new SortedDictionary<long, Change>();
        _checkpoint = 0;
        _seq = 0;
    }

    private static double Round(double v, int d) => Math.Round(v, d, MidpointRounding.AwayFromZero);

    /// <summary>
    /// El indice rechaza una fraccion de las escrituras.
    /// El modulo 101 —primo— importa: con 100, las dos escrituras del mismo
    /// documento (i e i+keyspace) caen en el mismo residuo y corren siempre la
    /// misma suerte, asi que nunca se produce deriva `stale`. Con 101 se separan.
    /// </summary>
    private static bool IndexWriteFails(long idx, int failRate) =>
        ((idx * 37) % 101 + 101) % 101 < failRate;

    /// <summary>La escritura al segundo sistema. Lanza, como lanzaria un HttpClient.</summary>
    private static void EscribirIndice(string id, IdxEntry e, bool borrar, long idx, int failRate)
    {
        if (IndexWriteFails(idx, failRate))
            throw new InvalidOperationException($"el indice rechazo la escritura de {id}");
        if (borrar) _index.Remove(id);
        else _index[id] = e;
    }

    // -----------------------------------------------------------------------
    // Variante dual-write: escribir en la base, escribir en el indice, y rezar
    // -----------------------------------------------------------------------

    private static int RunDrifted(int writes, int failRate, int deletePct)
    {
        lock (Lock)
        {
            ResetAll();
            var keyspace = Math.Max(1, writes / 2);
            var silent = 0;

            for (var i = 0; i < writes; i++)
            {
                var id = $"doc-{i % keyspace}";
                var term = Terms[i % Terms.Length];
                var deleting = ((long)i * 53 % 101 + 101) % 101 < deletePct;

                var version = _db.TryGetValue(id, out var prev) ? prev.Version + 1 : 1;
                _db[id] = new Doc(version, term, deleting, NowMs());

                // AQUI ESTA EL BUG. El catch vacio es la version explicita. En
                // .NET la implicita es igual de comun: `_ = IndexarAsync(doc)`
                // sin await, que manda la excepcion a un Task que nadie observa.
                try
                {
                    EscribirIndice(id, new IdxEntry(version, term), deleting, i, failRate);
                }
                catch (InvalidOperationException)
                {
                    silent++;
                }
            }
            return silent;
        }
    }

    // -----------------------------------------------------------------------
    // Variante outbox + checkpoint + reconciliacion
    // -----------------------------------------------------------------------

    private static int RunReconciled(int writes, int failRate, int deletePct)
    {
        lock (Lock)
        {
            ResetAll();
            var keyspace = Math.Max(1, writes / 2);

            for (var i = 0; i < writes; i++)
            {
                var id = $"doc-{i % keyspace}";
                var term = Terms[i % Terms.Length];
                var deleting = ((long)i * 53 % 101 + 101) % 101 < deletePct;

                var version = _db.TryGetValue(id, out var prev) ? prev.Version + 1 : 1;
                _db[id] = new Doc(version, term, deleting, NowMs());
                // El cambio se anota JUNTO con la escritura, en la MISMA
                // transaccion. Esto si es atomico: los dos son la base.
                _seq++;
                _outbox[_seq] = new Change(_seq, id, version, term, deleting, NowMs());
            }
            return DrainOutbox(failRate, 5);
        }
    }

    /// <summary>
    /// Aplica los cambios pendientes al indice, en orden, reintentando.
    /// En orden porque saltear uno dejaria una version vieja pisando a una nueva;
    /// y el checkpoint avanza solo con la confirmacion, asi que un cambio que no
    /// entra queda <b>pendiente</b>, no perdido.
    /// </summary>
    private static int DrainOutbox(int failRate, int maxRetries)
    {
        var retried = 0;
        // ToList() fija la secuencia AHORA: LINQ es perezoso y el diccionario
        // se muta dentro del bucle.
        var pending = _outbox.Where(kv => kv.Key > _checkpoint).Select(kv => kv.Value).ToList();
        foreach (var entry in pending)
        {
            var applied = false;
            for (var attempt = 0; attempt < maxRetries; attempt++)
            {
                try
                {
                    EscribirIndice(entry.Id, new IdxEntry(entry.Version, entry.Term), entry.Deleted,
                        entry.Seq * (attempt + 1L) + attempt, failRate);
                    applied = true;
                    break;
                }
                catch (InvalidOperationException)
                {
                    retried++;
                }
            }
            if (!applied) break;   // el checkpoint se frena: el cambio queda pendiente
            _checkpoint = entry.Seq;
        }
        return retried;
    }

    // -----------------------------------------------------------------------
    // La deriva de tres caras, como consultas sobre secuencias
    // -----------------------------------------------------------------------

    private static JsonObject ComputeDriftLocked()
    {
        var dbLive = _db.Where(kv => !kv.Value.Deleted).ToDictionary(kv => kv.Key, kv => kv.Value);

        var missing = dbLive.Keys.Except(_index.Keys).OrderBy(x => x, StringComparer.Ordinal).ToList();
        var orphan = _index.Keys.Except(dbLive.Keys).OrderBy(x => x, StringComparer.Ordinal).ToList();
        var stale = dbLive
            .Join(_index, d => d.Key, i => i.Key, (d, i) => new { d.Key, DbV = d.Value, IdxV = i.Value })
            .Where(p => p.DbV.Version != p.IdxV.Version)
            .Select(p => p.Key)
            .ToList();

        var now = NowMs();
        var oldest = missing.Concat(stale)
            .Select(id => now - dbLive[id].UpdatedMs)
            .DefaultIfEmpty(0)
            .Max();

        return new JsonObject
        {
            ["db_count"] = dbLive.Count,
            ["index_count"] = _index.Count,
            ["missing"] = missing.Count,
            ["stale"] = stale.Count,
            ["orphan"] = orphan.Count,
            ["drift_count"] = missing.Count + stale.Count + orphan.Count,
            ["drift_age_ms"] = Round(oldest, 2),
            ["missing_ids"] = new JsonArray(missing.Take(8).Select(x => (JsonNode)x!).ToArray()),
            ["orphan_ids"] = new JsonArray(orphan.Take(8).Select(x => (JsonNode)x!).ToArray()),
            ["last_checkpoint"] = _checkpoint,
            ["outbox_pending"] = _outbox.Count(kv => kv.Key > _checkpoint),
        };
    }

    private static JsonObject ComputeDrift()
    {
        lock (Lock) { return ComputeDriftLocked(); }
    }

    private static JsonObject Reconcile()
    {
        var t0 = NowMs();
        JsonObject before, after;
        lock (Lock)
        {
            before = ComputeDriftLocked();
            var dbLive = _db.Where(kv => !kv.Value.Deleted).ToDictionary(kv => kv.Key, kv => kv.Value);
            foreach (var (id, d) in dbLive)
            {
                if (!_index.TryGetValue(id, out var cur) || cur.Version != d.Version)
                    _index[id] = new IdxEntry(d.Version, d.Term);
            }
            foreach (var id in _index.Keys.Where(k => !dbLive.ContainsKey(k)).ToList())
                _index.Remove(id);
            after = ComputeDriftLocked();
        }

        var bc = before["drift_count"]!.GetValue<int>();
        var ac = after["drift_count"]!.GetValue<int>();
        return new JsonObject
        {
            ["reconcile_duration_ms"] = Round(NowMs() - t0, 2),
            ["drift_before"] = bc,
            ["drift_after"] = ac,
            ["repaired"] = bc - ac,
            ["detail_before"] = new JsonObject
            {
                ["missing"] = before["missing"]!.GetValue<int>(),
                ["stale"] = before["stale"]!.GetValue<int>(),
                ["orphan"] = before["orphan"]!.GetValue<int>(),
            },
            ["state"] = after,
            ["note"] = "El barrido es la red de seguridad de lo que el outbox no cubre: un indice restaurado de un "
                     + "backup viejo, una reindexacion parcial, un borrado manual. Sin el, el outbox garantiza que "
                     + "ningun cambio NUEVO se pierda — pero no arregla los que ya se perdieron.",
        };
    }

    // -----------------------------------------------------------------------
    // Las consultas: medir la deriva desde donde la ve el usuario
    // -----------------------------------------------------------------------

    private static JsonObject RunQueries(int queries)
    {
        int hits = 0, expected = 0, returned = 0;
        lock (Lock)
        {
            var dbLive = _db.Where(kv => !kv.Value.Deleted).ToDictionary(kv => kv.Key, kv => kv.Value);
            for (var q = 0; q < queries; q++)
            {
                var term = Terms[q % Terms.Length];
                var esperados = dbLive.Where(kv => kv.Value.Term == term).Select(kv => kv.Key).ToHashSet();
                var devueltos = _index.Where(kv => kv.Value.Term == term).Select(kv => kv.Key).ToList();
                expected += esperados.Count;
                returned += devueltos.Count;
                hits += devueltos.Count(esperados.Contains);
            }
        }
        return new JsonObject
        {
            ["queries"] = queries,
            ["search_recall_pct"] = Round(hits * 100.0 / Math.Max(1, expected), 2),
            ["search_precision_pct"] = Round(hits * 100.0 / Math.Max(1, returned), 2),
            ["note"] = "Recall bajo = la busqueda no encuentra lo que existe. Precision baja = devuelve lo que ya "
                     + "no existe. Las dos se ven como 'la busqueda anda rara', no como un error.",
        };
    }

    private static JsonObject RunScenario(string variant, int writes, int failRate, int deletePct, int queries)
    {
        var t0 = NowMs();
        int silent = 0, retried = 0;
        if (variant == "drifted") silent = RunDrifted(writes, failRate, deletePct);
        else { retried = RunReconciled(writes, failRate, deletePct); Reconcile(); }

        var drift = ComputeDrift();
        var q = RunQueries(queries);

        lock (Lock)
        {
            var s = _metrics[variant];
            s.Runs++;
            s.Writes += writes;
            s.SilentFailures += silent;
            s.DriftCount += drift["drift_count"]!.GetValue<int>();
            s.OutboxRetried += retried;
        }

        var payload = new JsonObject
        {
            ["variant"] = variant,
            ["writes"] = writes,
            ["fail_rate_pct"] = failRate,
            ["delete_pct"] = deletePct,
            ["silent_failures"] = silent,
            ["outbox_retried"] = retried,
        };
        foreach (var kv in drift.ToList()) { drift.Remove(kv.Key); payload[kv.Key] = kv.Value; }
        foreach (var kv in q.ToList()) { q.Remove(kv.Key); payload[kv.Key] = kv.Value; }
        payload["wall_ms"] = Round(NowMs() - t0, 2);
        payload["note"] = variant == "drifted"
            ? "La escritura al indice fallo y el codigo siguio como si nada. La base y el indice no comparten "
              + "transaccion, asi que la unica forma de enterarse es mirando — y nadie mira, porque la busqueda "
              + "sigue respondiendo 200."
            : "El outbox garantiza que ningun cambio nuevo se pierda, el checkpoint impide saltear uno, y el "
              + "barrido repara lo que los dos primeros no cubren. Deriva final: cero.";
        payload["dotnet_note"] = "LINQ convierte el diagnostico en una consulta: Except para missing y orphan, "
            + "Join para stale, con el compilador verificando los tipos de las claves. La trampa que viene con eso "
            + "es la pereza: Except no ejecuta nada hasta que alguien enumera, asi que los .ToList() fijan el "
            + "resultado mientras el estado todavia es consistente.";
        return payload;
    }

    private static JsonObject IndexState()
    {
        var d = ComputeDrift();
        d["stack"] = Stack;
        d["note"] = "`missing` no se encuentra, `stale` se encuentra mal y `orphan` es un fantasma. Las tres se "
                  + "ven igual desde afuera — 'la busqueda anda rara' — y se arreglan distinto.";
        return d;
    }

    private static JsonObject Diagnostics()
    {
        var variants = new JsonObject();
        lock (Lock)
        {
            foreach (var name in new[] { "drifted", "reconciled" })
            {
                var s = _metrics[name];
                variants[name] = new JsonObject
                {
                    ["runs"] = s.Runs,
                    ["writes"] = s.Writes,
                    ["silent_failures"] = s.SilentFailures,
                    ["drift_count"] = s.DriftCount,
                    ["outbox_retried"] = s.OutboxRetried,
                };
            }
        }
        return new JsonObject
        {
            ["stack"] = Stack,
            ["case"] = CaseName,
            ["variants"] = variants,
            ["index"] = IndexState(),
            ["fidelity"] = new JsonObject
            {
                ["real"] = "El diff de tres caras, el outbox con orden y checkpoint, y el barrido de reconciliacion "
                         + "son codigo de verdad, con la primitiva idiomatica de cada runtime.",
                ["modelado"] = "El indice de busqueda es un Dictionary en memoria, no Elasticsearch. La falla de "
                             + "escritura es deterministica para que el escenario sea reproducible.",
                ["honesto"] = "Lo que importa del caso no es el motor de busqueda: es que la base y el indice son "
                            + "dos sistemas sin transaccion comun.",
            },
            ["interpretation"] = new JsonObject
            {
                ["drifted"] = "drift_count > 0 y recall por debajo de 100 con el servicio respondiendo 200 a todo.",
                ["reconciled"] = "drift_count = 0, recall y precision en 100.",
                ["dotnet_note"] = "Except y Join dicen el diagnostico en una linea cada uno. La pereza de LINQ es "
                                + "el precio: sin ToList(), el diff se evalua cuando alguien lo lee, no cuando se "
                                + "escribio.",
            },
        };
    }

    // -----------------------------------------------------------------------
    // HTTP
    // -----------------------------------------------------------------------

    private static int Clamp(int v, int lo, int hi) => Math.Max(lo, Math.Min(hi, v));

    private static int ParseInt(Dictionary<string, string> q, string key, int fallback) =>
        q.TryGetValue(key, out var raw) && int.TryParse(raw, out var v) ? v : fallback;

    private static Dictionary<string, string> QueryParams(string? raw)
    {
        var d = new Dictionary<string, string>();
        if (string.IsNullOrEmpty(raw)) return d;
        if (raw.StartsWith('?')) raw = raw[1..];
        foreach (var pair in raw.Split('&', StringSplitOptions.RemoveEmptyEntries))
        {
            var parts = pair.Split('=', 2);
            d[WebUtility.UrlDecode(parts[0]) ?? ""] = parts.Length > 1 ? WebUtility.UrlDecode(parts[1]) ?? "" : "";
        }
        return d;
    }

    private static void SendJson(HttpListenerContext ctx, int status, JsonObject payload)
    {
        try
        {
            payload["timestamp_utc"] = DateTime.UtcNow.ToString("yyyy-MM-ddTHH:mm:ssZ", CultureInfo.InvariantCulture);
            payload["pid"] = Environment.ProcessId;
            var bytes = Encoding.UTF8.GetBytes(
                payload.ToJsonString(new JsonSerializerOptions { WriteIndented = true }));
            ctx.Response.StatusCode = status;
            ctx.Response.ContentType = "application/json; charset=utf-8";
            ctx.Response.ContentLength64 = bytes.Length;
            ctx.Response.OutputStream.Write(bytes, 0, bytes.Length);
        }
        catch { }
        finally { try { ctx.Response.OutputStream.Close(); } catch { } }
    }

    private static void Handle(HttpListenerContext ctx)
    {
        var uri = ctx.Request.Url?.AbsolutePath ?? "/";
        var q = QueryParams(ctx.Request.Url?.Query);

        var writes = Clamp(ParseInt(q, "writes", 2000), 10, 200000);
        var failRate = Clamp(ParseInt(q, "fail_rate", 8), 0, 100);
        var deletePct = Clamp(ParseInt(q, "delete_pct", 5), 0, 50);
        var queries = Clamp(ParseInt(q, "queries", 200), 1, 5000);

        var status = 200;
        JsonObject payload;

        switch (uri)
        {
            case "/":
            case "/index":
                payload = new JsonObject
                {
                    ["lab"] = "Problem-Driven Systems Lab",
                    ["case"] = CaseName,
                    ["stack"] = Stack,
                    ["goal"] = "Mostrar que una busqueda que responde 200 puede estar respondiendo mal, y que la "
                             + "unica forma de saberlo es comparar los dos lados a proposito.",
                    ["dotnet_specific"] = "LINQ expresa las tres caras de la deriva como consultas tipadas — y su "
                                        + "pereza es la trampa que hay que fijar con ToList().",
                    ["routes"] = new JsonObject
                    {
                        ["/health"] = "Estado basico del servicio.",
                        ["/search-drifted?writes=2000&fail_rate=8"] = "Dual-write: el indice se desincroniza en silencio.",
                        ["/search-reconciled?writes=2000&fail_rate=8"] = "Outbox + checkpoint + barrido: deriva cero.",
                        ["/reconcile"] = "Un barrido suelto, para ver que encuentra y que repara.",
                        ["/index/state"] = "Las tres caras de la deriva y la antiguedad del cambio mas viejo.",
                        ["/diagnostics/summary"] = "Comparativa entre variantes.",
                        ["/reset-lab"] = "Vacia la base, el indice, el outbox y las metricas.",
                    },
                };
                break;
            case "/health":
                payload = new JsonObject { ["status"] = "ok", ["stack"] = Stack, ["case"] = CaseName };
                break;
            case "/search-drifted":
                payload = RunScenario("drifted", writes, failRate, deletePct, queries);
                break;
            case "/search-reconciled":
                payload = RunScenario("reconciled", writes, failRate, deletePct, queries);
                break;
            case "/reconcile":
                payload = Reconcile();
                break;
            case "/index/state":
                payload = IndexState();
                break;
            case "/diagnostics/summary":
                payload = Diagnostics();
                break;
            case "/reset-lab":
                lock (Lock)
                {
                    ResetAll();
                    _metrics = NewMetrics();
                }
                payload = new JsonObject
                {
                    ["status"] = "reset",
                    ["message"] = "Base, indice, outbox y metricas reiniciados.",
                };
                break;
            default:
                status = 404;
                payload = new JsonObject { ["error"] = "Ruta no encontrada", ["path"] = uri };
                break;
        }

        SendJson(ctx, status, payload);
    }

    private static async Task Main()
    {
        var port = Environment.GetEnvironmentVariable("PORT") ?? "8080";
        var listener = new HttpListener();
        listener.Prefixes.Add($"http://*:{port}/");
        listener.Start();
        Console.WriteLine($"Servidor .NET escuchando en {port}");

        while (true)
        {
            var ctx = await listener.GetContextAsync().ConfigureAwait(false);
            _ = Task.Run(() => Handle(ctx));
        }
    }
}
