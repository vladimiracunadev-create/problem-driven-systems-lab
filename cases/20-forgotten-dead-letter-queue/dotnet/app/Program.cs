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

// Caso 20 — La dead letter queue olvidada — stack .NET 8.
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
//   tirar trabajo que se podia salvar.
//
// Primitiva .NET distintiva — y es la unica del laboratorio:
//
//   **Los filtros de excepcion: `catch (Ex e) when (condicion)`.**
//
//       try { Procesar(msg); }
//       catch (ErrorProceso e) when (e.EsTransitorio) { Reintentar(); }
//       catch (ErrorProceso e) when (!e.EsTransitorio) { ADlq(msg, e.Clase); }
//
//   La diferencia con `catch` + `if` + `throw;` no es de estilo: **el filtro se
//   evalua ANTES de desenrollar la pila**. Si ninguno matchea, la pila queda
//   intacta y el error sigue subiendo con su stack trace completo.
//
//   Para este caso eso es exactamente el dato que falta. Un registro de DLQ sin
//   el punto de falla original no sirve para depurar — y en Java, donde para
//   clasificar hay que capturar, el `throw` de reenvio ya acorto la pila. .NET
//   es el unico stack del set que puede decidir sin destruir la evidencia.
//
//   El corolario menos conocido: `catch (Exception) when (Log(e))` con un filtro
//   que siempre devuelve `false` es la forma canonica de **registrar sin
//   capturar**. Se ve el error, se anota, y la excepcion sigue su camino.

internal static class Program
{
    private const string CaseName = "20 - La dead letter queue olvidada";
    private static readonly string Stack = Environment.GetEnvironmentVariable("APP_STACK") ?? ".NET 8";
    private static readonly string[] PoisonClasses =
        { "schema_mismatch", "unknown_field", "null_required", "invalid_encoding" };

    private static readonly Stopwatch Clock = Stopwatch.StartNew();
    private static double NowMs() => Clock.Elapsed.TotalMilliseconds;

    /// <summary>
    /// Un solo tipo con una bandera, para que los filtros hagan el trabajo de
    /// clasificar. Es la forma idiomatica en .NET, donde `when` es mas expresivo
    /// que una jerarquia profunda.
    /// </summary>
    private sealed class ErrorProceso : Exception
    {
        public bool EsTransitorio { get; }
        public string Clase { get; }

        public ErrorProceso(string clase, bool esTransitorio) : base($"error de proceso: {clase}")
        {
            Clase = clase;
            EsTransitorio = esTransitorio;
        }
    }

    private sealed record Sample(int Idx, string Payload);

    private sealed class Dead
    {
        public string Id = "";
        public string ErrorClass = "";
        public int Attempts;
        public double FirstSeenMs;
        public Sample? Sample;
    }

    private sealed class Slot
    {
        public int Runs, Consumed, Succeeded, Retried, DeadLettered, AlertsFired;
    }

    private static readonly object Lock = new();
    private static List<Dead> _dlq = new();
    private static int _alertsFired;
    private static Dictionary<string, Slot> _metrics = NewMetrics();

    private static Dictionary<string, Slot> NewMetrics() =>
        new() { ["silent"] = new Slot(), ["observed"] = new Slot() };

    private static double Round(double v, int d) => Math.Round(v, d, MidpointRounding.AwayFromZero);

    /// <summary>
    /// Procesa un mensaje. El transitorio falla solo en el primer intento: es la
    /// definicion de transitorio, y es lo que hace que reintentarlo tenga sentido.
    /// </summary>
    private static void Procesar(int idx, int transientPct, int poisonPct, int attempt)
    {
        if (((long)idx * 53 % 101 + 101) % 101 < poisonPct)
            throw new ErrorProceso(PoisonClasses[idx % PoisonClasses.Length], esTransitorio: false);
        if (((long)idx * 37 % 101 + 101) % 101 < transientPct && attempt == 0)
            throw new ErrorProceso("timeout_downstream", esTransitorio: true);
    }

    // -----------------------------------------------------------------------
    // Variante silenciosa: cualquier fallo va a la DLQ, y nadie vuelve
    // -----------------------------------------------------------------------

    private static JsonObject ConsumeSilent(int messages, int transientPct, int poisonPct)
    {
        lock (Lock) { _dlq = new List<Dead>(); _alertsFired = 0; }
        int consumed = 0, succeeded = 0, deadCount = 0;
        var t0 = NowMs();

        for (var i = 0; i < messages; i++)
        {
            consumed++;
            try
            {
                Procesar(i, transientPct, poisonPct, 0);
                succeeded++;
            }
            catch (Exception)
            {
                // El bug entero. `catch (Exception)` sin filtro no mira QUE error
                // es, no reintenta, y no guarda por que fallo. Ademas se traga
                // los bugs del propio consumidor junto con los datos malos.
                lock (Lock)
                {
                    _dlq.Add(new Dead { Id = $"msg-{i}", ErrorClass = "unclassified", Attempts = 1, FirstSeenMs = NowMs() });
                }
                deadCount++;
            }
        }

        return new JsonObject
        {
            ["consumed"] = consumed,
            ["succeeded"] = succeeded,
            ["retried"] = 0,
            ["dead_lettered"] = deadCount,
            ["alerts_fired"] = 0,
            ["sampled"] = 0,
            ["wall_ms"] = Round(NowMs() - t0, 2),
        };
    }

    // -----------------------------------------------------------------------
    // Variante observada: clasificar con FILTROS, reintentar, medir, alertar
    // -----------------------------------------------------------------------

    private static JsonObject ConsumeObserved(int messages, int transientPct, int poisonPct,
        int maxRetries, int alertThreshold, int sampleSize)
    {
        lock (Lock) { _dlq = new List<Dead>(); _alertsFired = 0; }
        int consumed = 0, succeeded = 0, retried = 0, deadCount = 0, sampled = 0;
        var t0 = NowMs();

        for (var i = 0; i < messages; i++)
        {
            consumed++;
            for (var attempt = 0; attempt <= maxRetries; attempt++)
            {
                try
                {
                    Procesar(i, transientPct, poisonPct, attempt);
                    succeeded++;
                    break;
                }
                // El filtro `when` se evalua ANTES de desenrollar la pila. Si
                // ninguno matchea, el error sube con su stack trace intacto.
                catch (ErrorProceso e) when (e.EsTransitorio)
                {
                    retried++;
                    if (attempt == maxRetries)
                    {
                        lock (Lock)
                        {
                            _dlq.Add(new Dead { Id = $"msg-{i}", ErrorClass = "transient_exhausted",
                                Attempts = attempt + 1, FirstSeenMs = NowMs() });
                        }
                        deadCount++;
                    }
                }
                catch (ErrorProceso e) when (!e.EsTransitorio)
                {
                    // Venenoso: reintentarlo es quemar CPU. Va a la DLQ ya
                    // mismo, con su clase y —para los primeros— una muestra.
                    Sample? muestra = null;
                    if (sampled < sampleSize)
                    {
                        muestra = new Sample(i, $"{{\"id\": {i}, \"campo\": \"...\"}}");
                        sampled++;
                    }
                    lock (Lock)
                    {
                        _dlq.Add(new Dead { Id = $"msg-{i}", ErrorClass = e.Clase,
                            Attempts = attempt + 1, FirstSeenMs = NowMs(), Sample = muestra });
                    }
                    deadCount++;
                    break;
                }
                // No hay `catch (Exception)`: un error que no supimos clasificar
                // NO va a la DLQ. Sube, con la pila entera.
            }
        }

        var alerts = 0;
        lock (Lock)
        {
            if (_dlq.Count > alertThreshold) { _alertsFired++; alerts = 1; }
        }

        return new JsonObject
        {
            ["consumed"] = consumed,
            ["succeeded"] = succeeded,
            ["retried"] = retried,
            ["dead_lettered"] = deadCount,
            ["alerts_fired"] = alerts,
            ["sampled"] = sampled,
            ["wall_ms"] = Round(NowMs() - t0, 2),
        };
    }

    // -----------------------------------------------------------------------
    // La DLQ como cola observable, no como agujero
    // -----------------------------------------------------------------------

    private static JsonObject DlqStats(int alertThreshold)
    {
        JsonObject porClase = new();
        int depth;
        double oldest;
        var muestras = new JsonArray();
        int alerts;

        lock (Lock)
        {
            foreach (var g in _dlq.GroupBy(m => m.ErrorClass).OrderBy(g => g.Key, StringComparer.Ordinal))
                porClase[g.Key] = g.Count();

            var now = NowMs();
            oldest = _dlq.Select(m => now - m.FirstSeenMs).DefaultIfEmpty(0).Max();
            depth = _dlq.Count;
            alerts = _alertsFired;
            foreach (var m in _dlq.Where(m => m.Sample is not null).Take(5))
                muestras.Add(new JsonObject { ["idx"] = m.Sample!.Idx, ["payload"] = m.Sample.Payload });
        }

        return new JsonObject
        {
            ["dlq_depth"] = depth,
            ["dlq_oldest_msg_age_ms"] = Round(oldest, 2),
            ["by_error_class"] = porClase,
            ["alert_threshold"] = alertThreshold,
            ["over_threshold"] = depth > alertThreshold,
            ["alerts_fired"] = alerts,
            ["samples"] = muestras,
            ["note"] = "Una DLQ sin profundidad publicada, sin antiguedad del mensaje mas viejo y sin desglose por "
                     + "clase de error no es una cola: es un agujero. by_error_class convierte 'hay 4.000 mensajes' "
                     + "en 'hay un bug de schema y tres timeouts'.",
        };
    }

    /// <summary>
    /// Replay desde la DLQ. Lo que se recupera vuelve; lo venenoso sigue ahi.
    /// Una DLQ que solo recibe es un cementerio; una de la que se puede volver
    /// es un buffer.
    /// </summary>
    private static JsonObject DlqDrain(int limit, int transientPct, int poisonPct, int maxRetries)
    {
        var t0 = NowMs();
        List<Dead> lote, resto;
        lock (Lock)
        {
            var n = Math.Min(limit, _dlq.Count);
            lote = _dlq.Take(n).ToList();
            resto = _dlq.Skip(n).ToList();
        }

        int ok = 0, fallo = 0;
        var quedan = new List<Dead>();
        foreach (var m in lote)
        {
            var idx = int.Parse(m.Id[4..], CultureInfo.InvariantCulture);
            var recuperado = false;
            for (var attempt = 1; attempt <= maxRetries; attempt++)
            {
                try
                {
                    Procesar(idx, transientPct, poisonPct, attempt);
                    recuperado = true;
                    break;
                }
                catch (ErrorProceso e) when (e.EsTransitorio) { /* sigue intentando */ }
                catch (ErrorProceso) { break; }
            }
            if (recuperado) ok++;
            else { fallo++; m.Attempts += maxRetries; quedan.Add(m); }
        }

        int depth;
        lock (Lock)
        {
            quedan.AddRange(resto);
            _dlq = quedan;
            depth = _dlq.Count;
        }

        return new JsonObject
        {
            ["drain_limit"] = limit,
            ["drained_ok"] = ok,
            ["drain_failed"] = fallo,
            ["recovered_pct"] = Round(ok * 100.0 / Math.Max(1, ok + fallo), 2),
            ["drain_duration_ms"] = Round(NowMs() - t0, 2),
            ["dlq_depth_after"] = depth,
            ["note"] = "Lo que se recupera en el replay es exactamente lo que nunca deberia haber estado aca: "
                     + "errores transitorios que un reintento habria resuelto. Lo que sigue fallando es veneno de "
                     + "verdad, y necesita un cambio de codigo o de datos — no otro reintento.",
        };
    }

    private static JsonObject RunScenario(string variant, int messages, int transientPct, int poisonPct,
        int maxRetries, int alertThreshold, int sampleSize)
    {
        var r = variant == "silent"
            ? ConsumeSilent(messages, transientPct, poisonPct)
            : ConsumeObserved(messages, transientPct, poisonPct, maxRetries, alertThreshold, sampleSize);
        var stats = DlqStats(alertThreshold);

        var consumed = r["consumed"]!.GetValue<int>();
        var deadLettered = r["dead_lettered"]!.GetValue<int>();

        lock (Lock)
        {
            var s = _metrics[variant];
            s.Runs++;
            s.Consumed += consumed;
            s.Succeeded += r["succeeded"]!.GetValue<int>();
            s.Retried += r["retried"]!.GetValue<int>();
            s.DeadLettered += deadLettered;
            s.AlertsFired += r["alerts_fired"]!.GetValue<int>();
        }

        var payload = new JsonObject
        {
            ["variant"] = variant,
            ["messages"] = messages,
            ["transient_pct"] = transientPct,
            ["poison_pct"] = poisonPct,
            ["max_retries"] = variant == "observed" ? maxRetries : 0,
        };
        foreach (var kv in r.ToList()) { r.Remove(kv.Key); payload[kv.Key] = kv.Value; }
        foreach (var k in new[] { "dlq_depth", "dlq_oldest_msg_age_ms", "by_error_class", "alert_threshold", "over_threshold" })
        {
            var node = stats[k];
            stats.Remove(k);
            payload[k] = node;
        }
        payload["dead_letter_rate_pct"] = Round(deadLettered * 100.0 / Math.Max(1, consumed), 2);
        payload["note"] = variant == "silent"
            ? "El consumidor no clasifico nada: transitorio y venenoso fueron al mismo lugar, sin reintentar y sin "
              + "registrar por que. El pipeline se ve sano —throughput normal, cero errores— porque los errores se "
              + "fueron a otro lado. Y nadie va a volver."
            : "Lo transitorio se reintento y casi todo se recupero; solo el veneno llego a la DLQ, con su clase de "
              + "error y una muestra del payload. La profundidad esta publicada y el umbral disparo alerta.";
        payload["dotnet_note"] = "Los filtros `catch (Ex e) when (...)` son la unica primitiva del laboratorio que "
            + "decide ANTES de desenrollar la pila: si ninguno matchea, el error sube con su stack trace intacto. "
            + "Para un registro de DLQ eso es exactamente el dato que falta, y en Java el `throw` de reenvio ya lo "
            + "acorto.";
        return payload;
    }

    private static JsonObject Diagnostics(int alertThreshold)
    {
        var variants = new JsonObject();
        lock (Lock)
        {
            foreach (var name in new[] { "silent", "observed" })
            {
                var s = _metrics[name];
                variants[name] = new JsonObject
                {
                    ["runs"] = s.Runs,
                    ["consumed"] = s.Consumed,
                    ["succeeded"] = s.Succeeded,
                    ["retried"] = s.Retried,
                    ["dead_lettered"] = s.DeadLettered,
                    ["alerts_fired"] = s.AlertsFired,
                };
            }
        }
        return new JsonObject
        {
            ["stack"] = Stack,
            ["case"] = CaseName,
            ["variants"] = variants,
            ["dlq"] = DlqStats(alertThreshold),
            ["arco_con_el_caso_15"] = "En el caso 15 la DLQ NACE: es la politica de rechazo que salva al productor "
                                    + "de bloquearse cuando la cola se llena. Aca se ve que pasa cuando nadie vuelve.",
            ["fidelity"] = new JsonObject
            {
                ["real"] = "La clasificacion con filtros, el reintento con presupuesto acotado, el desglose por "
                         + "clase, el muestreo de payloads y el replay desde la DLQ son codigo de verdad.",
                ["modelado"] = "La DLQ es una lista en memoria, no SQS ni RabbitMQ. La clase de error de cada "
                             + "mensaje es deterministica para que el escenario sea reproducible.",
                ["honesto"] = "Lo que define el caso no es el broker: es que un mensaje que falla tiene que ir a "
                            + "algun lado, y que ese lado necesita profundidad, antiguedad, clasificacion y salida.",
            },
            ["interpretation"] = new JsonObject
            {
                ["silent"] = "dead_letter_rate_pct alto, by_error_class con una sola entrada ('unclassified') y "
                           + "alerts_fired en cero. El pipeline se ve sano.",
                ["observed"] = "dead_letter_rate_pct bajo —solo el veneno—, by_error_class desglosado y la alerta "
                             + "disparada.",
                ["dotnet_note"] = "`catch (Exception) when (Log(e))` con un filtro que devuelve false es la forma "
                                + "canonica de registrar sin capturar: se ve el error, se anota, y sigue su camino.",
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

        var messages = Clamp(ParseInt(q, "messages", 3000), 10, 200000);
        var transientPct = Clamp(ParseInt(q, "transient_pct", 12), 0, 100);
        var poisonPct = Clamp(ParseInt(q, "poison_pct", 4), 0, 100);
        var maxRetries = Clamp(ParseInt(q, "max_retries", 3), 0, 20);
        var alertThreshold = Clamp(ParseInt(q, "alert_threshold", 50), 0, 100000);
        var sampleSize = Clamp(ParseInt(q, "sample_size", 20), 0, 1000);
        var limit = Clamp(ParseInt(q, "limit", 500), 1, 200000);

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
                    ["goal"] = "Mostrar que un pipeline con throughput normal y cero errores puede estar perdiendo "
                             + "el 16% de los mensajes, porque los errores se fueron a un lugar que nadie mira.",
                    ["arco"] = "Cierra el arco del caso 15, donde la DLQ nace como politica de rechazo.",
                    ["dotnet_specific"] = "Filtros de excepcion `when (...)`: la unica primitiva del lab que decide "
                                        + "antes de desenrollar la pila.",
                    ["routes"] = new JsonObject
                    {
                        ["/health"] = "Estado basico del servicio.",
                        ["/consume-silent?messages=3000"] = "Cualquier fallo a la DLQ, sin clasificar ni reintentar.",
                        ["/consume-observed?messages=3000"] = "Clasificar con filtros, reintentar, alertar.",
                        ["/dlq/stats"] = "Profundidad, antiguedad del mas viejo y desglose por clase de error.",
                        ["/dlq/drain?limit=500"] = "Replay desde la DLQ: que se recupera y que sigue siendo veneno.",
                        ["/diagnostics/summary"] = "Comparativa entre variantes.",
                        ["/reset-lab"] = "Vacia la DLQ y las metricas.",
                    },
                };
                break;
            case "/health":
                payload = new JsonObject { ["status"] = "ok", ["stack"] = Stack, ["case"] = CaseName };
                break;
            case "/consume-silent":
                payload = RunScenario("silent", messages, transientPct, poisonPct, maxRetries, alertThreshold, sampleSize);
                break;
            case "/consume-observed":
                payload = RunScenario("observed", messages, transientPct, poisonPct, maxRetries, alertThreshold, sampleSize);
                break;
            case "/dlq/stats":
                payload = DlqStats(alertThreshold);
                break;
            case "/dlq/drain":
                payload = DlqDrain(limit, transientPct, poisonPct, maxRetries);
                break;
            case "/diagnostics/summary":
                payload = Diagnostics(alertThreshold);
                break;
            case "/reset-lab":
                lock (Lock)
                {
                    _dlq = new List<Dead>();
                    _alertsFired = 0;
                    _metrics = NewMetrics();
                }
                payload = new JsonObject { ["status"] = "reset", ["message"] = "DLQ y metricas reiniciadas." };
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
