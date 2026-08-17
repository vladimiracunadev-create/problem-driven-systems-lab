using System;
using System.Collections.Concurrent;
using System.Collections.Generic;
using System.Linq;
using System.Net;
using System.Text;
using System.Threading;
using System.Threading.Channels;
using System.Threading.Tasks;

// Caso 15 — Backpressure en colas de mensajes — stack .NET 8.
//
// Unbounded: `Channel.CreateUnbounded<T>()`. El productor nunca se entera de que
// el consumidor no da abasto.
// Bounded: `Channel.CreateBounded<T>(options)` con `BoundedChannelFullMode`.
//
// Primitiva .NET distintiva — y es la mas directa de los siete stacks:
//
//   `System.Threading.Channels` es el unico runtime del laboratorio donde la
//   politica de cola llena **es un enum que se pasa al constructor**:
//
//       new BoundedChannelOptions(capacity) {
//           FullMode = BoundedChannelFullMode.Wait          // backpressure
//                    | BoundedChannelFullMode.DropOldest    // descarta el viejo
//                    | BoundedChannelFullMode.DropWrite     // descarta el nuevo
//       }
//
//   En Python, Java o Go la politica se expresa eligiendo QUE METODO llamar en
//   cada sitio de envio — y por lo tanto se puede elegir distinto en dos lugares
//   del mismo sistema sin que nada lo note. Aca la decision se toma UNA VEZ, al
//   construir el canal, y despues todos los productores la heredan.
//
//   El reverso de esa comodidad: `CreateUnbounded()` es igual de facil de
//   escribir y no lleva ninguna advertencia. Un metodo, cero parametros, y el
//   sistema se queda sin freno.
//
// La leccion del caso: ninguna politica es gratis. Bloquear frena al productor,
// descartar pierde datos, y la DLQ muda el problema (eso es el caso 20).

internal static class Program
{
    private const string CaseName = "15 - Backpressure en colas de mensajes";
    private static readonly string Stack = Environment.GetEnvironmentVariable("APP_STACK") ?? ".NET 8";

    private const int MsgBytes = 2048;
    private static readonly string[] Policies = { "block", "drop_oldest", "dead_letter" };

    private readonly record struct Msg(int Seq, long EnqueuedAtTicks);
    private sealed record DlqEntry(int Seq, string Reason, string At);

    private static readonly ConcurrentQueue<DlqEntry> Dlq = new();
    private static Dictionary<string, object> _lastState = new();

    private sealed class Slot
    {
        public long Runs, Produced, Consumed, Dropped, DeadLettered, MaxQueueDepth;
        public double MaxOldestAgeMs, ProducerBlockedMs;
    }

    private static ConcurrentDictionary<string, Slot> _metrics = Fresh();

    private static ConcurrentDictionary<string, Slot> Fresh()
    {
        var d = new ConcurrentDictionary<string, Slot>();
        d["unbounded"] = new Slot();
        d["bounded"] = new Slot();
        return d;
    }

    private static double MsSince(long ticks) =>
        (Stopwatch_GetTimestamp() - ticks) * 1000.0 / System.Diagnostics.Stopwatch.Frequency;

    private static long Stopwatch_GetTimestamp() => System.Diagnostics.Stopwatch.GetTimestamp();

    // ------------------------------------------------------------------
    // Consumidor comun a las dos variantes
    // ------------------------------------------------------------------

    private sealed class ConsumerStats
    {
        public long Consumed;
        public double MaxOldestAgeMs;
    }

    private static async Task Consume(ChannelReader<Msg> reader, int consumeMs, ConsumerStats stats)
    {
        await foreach (var m in reader.ReadAllAsync().ConfigureAwait(false))
        {
            // Se mide ANTES de procesar: la edad del mensaje mas viejo es la
            // latencia real del consumidor final, y sin limite no tiene techo.
            var age = MsSince(m.EnqueuedAtTicks);
            if (age > stats.MaxOldestAgeMs) stats.MaxOldestAgeMs = age;
            if (consumeMs > 0) await Task.Delay(consumeMs).ConfigureAwait(false);
            Interlocked.Increment(ref stats.Consumed);
        }
    }

    // ------------------------------------------------------------------
    // Variante unbounded
    // ------------------------------------------------------------------

    private static async Task<string> RunUnbounded(int messages, int consumeMs)
    {
        // Un metodo, cero parametros, cero advertencias — y el sistema sin freno.
        var channel = Channel.CreateUnbounded<Msg>();
        var stats = new ConsumerStats();
        var consumer = Consume(channel.Reader, consumeMs, stats);

        var t0 = Stopwatch_GetTimestamp();
        long peak = 0;
        for (var seq = 0; seq < messages; seq++)
        {
            // TryWrite sobre un canal sin limite SIEMPRE devuelve true.
            channel.Writer.TryWrite(new Msg(seq, Stopwatch_GetTimestamp()));
            var depth = channel.Reader.Count;
            if (depth > peak) peak = depth;
        }
        var depthAtEnd = channel.Reader.Count;
        channel.Writer.Complete();
        await consumer.ConfigureAwait(false);
        var wallMs = MsSince(t0);

        return Json("unbounded", null, null, messages, Interlocked.Read(ref stats.Consumed), 0, 0,
            peak, depthAtEnd, stats.MaxOldestAgeMs, 0, 0, wallMs,
            "Channel.CreateUnbounded(): TryWrite siempre devuelve true y la cola crece hasta donde de la memoria. "
            + "Es un metodo sin parametros y sin advertencias — el sistema queda sin freno con una linea.");
    }

    // ------------------------------------------------------------------
    // Variante bounded: la politica es un enum del constructor
    // ------------------------------------------------------------------

    private static async Task<string> RunBounded(int messages, int capacity, string policy, int consumeMs)
    {
        var fullMode = policy switch
        {
            "block" => BoundedChannelFullMode.Wait,
            "drop_oldest" => BoundedChannelFullMode.DropOldest,
            _ => BoundedChannelFullMode.DropWrite,     // el caso dead_letter lo maneja el productor
        };

        long droppedByChannel = 0;
        var options = new BoundedChannelOptions(capacity)
        {
            FullMode = fullMode,
            SingleReader = true,
            SingleWriter = true,
        };
        // El canal avisa que descarto: no hay que inferirlo de un contador propio.
        var channel = Channel.CreateBounded<Msg>(options, _ => Interlocked.Increment(ref droppedByChannel));

        var stats = new ConsumerStats();
        var consumer = Consume(channel.Reader, consumeMs, stats);

        var t0 = Stopwatch_GetTimestamp();
        long produced = 0, dead = 0, signals = 0;
        double blockedMs = 0;
        long peak = 0;

        for (var seq = 0; seq < messages; seq++)
        {
            var m = new Msg(seq, Stopwatch_GetTimestamp());
            if (policy == "block")
            {
                // WriteAsync sobre FullMode.Wait espera a que haya lugar. Esa
                // espera ES el backpressure: no hay protocolo extra que escribir.
                if (channel.Reader.Count >= capacity) signals++;
                var b0 = Stopwatch_GetTimestamp();
                await channel.Writer.WriteAsync(m).ConfigureAwait(false);
                var waited = MsSince(b0);
                if (waited > 0.5) blockedMs += waited;
                produced++;
            }
            else if (policy == "drop_oldest")
            {
                // FullMode.DropOldest: el canal saca el mas viejo por su cuenta y
                // lo reporta por el callback de arriba.
                if (channel.Reader.Count >= capacity) signals++;
                channel.Writer.TryWrite(m);
                produced++;
            }
            else
            {
                // FullMode.DropWrite + DLQ manual: el canal rechaza el nuevo y el
                // productor decide que hacer con el.
                if (channel.Reader.Count >= capacity)
                {
                    signals++;
                    Dlq.Enqueue(new DlqEntry(seq, "queue_full", DateTime.UtcNow.ToString("o")));
                    while (Dlq.Count > 200) Dlq.TryDequeue(out _);
                    dead++;
                }
                else
                {
                    channel.Writer.TryWrite(m);
                    produced++;
                }
            }
            var depth = channel.Reader.Count;
            if (depth > peak) peak = depth;
        }

        var depthAtEnd = channel.Reader.Count;
        channel.Writer.Complete();
        await consumer.ConfigureAwait(false);
        var wallMs = MsSince(t0);

        var note = policy switch
        {
            "block" => "BoundedChannelFullMode.Wait: WriteAsync espera a que haya lugar y esa espera ES el "
                       + "backpressure. Nada se pierde, pero el productor se frena.",
            "drop_oldest" => "BoundedChannelFullMode.DropOldest: el canal descarta por su cuenta y lo reporta por "
                             + "callback. El productor nunca se frena, pero se pierden datos.",
            _ => "BoundedChannelFullMode.DropWrite + DLQ manual: no se frena ni se pierde, pero el problema se muda a "
                 + "otra cola que alguien tiene que mirar. Si nadie la mira, es el caso 20.",
        };

        return Json("bounded", policy, capacity, produced, Interlocked.Read(ref stats.Consumed),
            Interlocked.Read(ref droppedByChannel), dead, peak, depthAtEnd,
            stats.MaxOldestAgeMs, blockedMs, signals, wallMs, note);
    }

    // ------------------------------------------------------------------
    // JSON + registro
    // ------------------------------------------------------------------

    private static string Json(string variant, string? policy, int? capacity, long produced, long consumed,
        long dropped, long dead, long peak, int depthAtEnd, double oldestMs, double blockedMs,
        long signals, double wallMs, string note)
    {
        var s = _metrics[variant];
        Interlocked.Increment(ref s.Runs);
        Interlocked.Add(ref s.Produced, produced);
        Interlocked.Add(ref s.Consumed, consumed);
        Interlocked.Add(ref s.Dropped, dropped);
        Interlocked.Add(ref s.DeadLettered, dead);
        if (peak > s.MaxQueueDepth) s.MaxQueueDepth = peak;
        if (oldestMs > s.MaxOldestAgeMs) s.MaxOldestAgeMs = oldestMs;
        s.ProducerBlockedMs += blockedMs;

        _lastState = new Dictionary<string, object>
        {
            ["last_variant"] = variant,
            ["last_policy"] = policy ?? "null",
            ["capacity"] = capacity ?? -1,
            ["queue_depth_peak"] = peak,
            ["queue_bytes_peak"] = peak * MsgBytes,
            ["oldest_msg_age_ms_peak"] = Num(oldestMs),
        };

        return "{\"variant\":\"" + variant + "\""
             + ",\"policy\":" + (policy is null ? "null" : "\"" + policy + "\"")
             + ",\"capacity\":" + (capacity is null ? "null" : capacity.Value.ToString())
             + ",\"produced\":" + produced
             + ",\"consumed\":" + consumed
             + ",\"dropped\":" + dropped
             + ",\"dead_lettered\":" + dead
             + ",\"queue_depth_peak\":" + peak
             + ",\"queue_depth_at_end_of_production\":" + depthAtEnd
             + ",\"queue_bytes_peak\":" + peak * MsgBytes
             + ",\"oldest_msg_age_ms_peak\":" + Num(oldestMs)
             + ",\"producer_blocked_ms\":" + Num(blockedMs)
             + ",\"backpressure_signals\":" + signals
             + ",\"wall_ms\":" + Num(wallMs)
             + ",\"throughput_msg_s\":" + Num(wallMs > 0 ? produced / (wallMs / 1000.0) : 0)
             + ",\"note\":\"" + note + "\"}";
    }

    private static string Num(double v) =>
        Math.Round(v, 2).ToString(System.Globalization.CultureInfo.InvariantCulture);

    private static string QueueStateJson() =>
        "{\"last_variant\":\"" + _lastState.GetValueOrDefault("last_variant", "") + "\""
        + ",\"last_policy\":\"" + _lastState.GetValueOrDefault("last_policy", "") + "\""
        + ",\"capacity\":" + _lastState.GetValueOrDefault("capacity", -1)
        + ",\"queue_depth_peak\":" + _lastState.GetValueOrDefault("queue_depth_peak", 0L)
        + ",\"queue_bytes_peak\":" + _lastState.GetValueOrDefault("queue_bytes_peak", 0L)
        + ",\"oldest_msg_age_ms_peak\":" + _lastState.GetValueOrDefault("oldest_msg_age_ms_peak", "0")
        + ",\"dlq_depth\":" + Dlq.Count
        + ",\"msg_bytes\":" + MsgBytes
        + ",\"policies\":[\"block\",\"drop_oldest\",\"dead_letter\"]"
        + ",\"note\":\"queue_depth_peak x msg_bytes es lo que la cola llego a ocupar. CreateUnbounded no tiene techo.\"}";

    private static string DlqJson(int limit)
    {
        var items = Dlq.Reverse().Take(limit).ToArray();
        var sb = new StringBuilder(512);
        sb.Append("{\"dlq_depth\":").Append(Dlq.Count).Append(",\"limit\":").Append(limit).Append(",\"messages\":[");
        for (var i = 0; i < items.Length; i++)
        {
            if (i > 0) sb.Append(',');
            sb.Append("{\"seq\":").Append(items[i].Seq).Append(",\"reason\":\"").Append(items[i].Reason)
              .Append("\",\"at\":\"").Append(items[i].At).Append("\"}");
        }
        sb.Append("],\"note\":\"La DLQ no resuelve el backpressure: lo muda. El caso 20 trata que pasa cuando nadie la mira.\"}");
        return sb.ToString();
    }

    private static string VariantJson(string name)
    {
        var s = _metrics[name];
        return "\"" + name + "\":{\"runs\":" + Interlocked.Read(ref s.Runs)
             + ",\"produced\":" + Interlocked.Read(ref s.Produced)
             + ",\"consumed\":" + Interlocked.Read(ref s.Consumed)
             + ",\"dropped\":" + Interlocked.Read(ref s.Dropped)
             + ",\"dead_lettered\":" + Interlocked.Read(ref s.DeadLettered)
             + ",\"max_queue_depth\":" + s.MaxQueueDepth
             + ",\"max_oldest_age_ms\":" + Num(s.MaxOldestAgeMs)
             + ",\"producer_blocked_ms\":" + Num(s.ProducerBlockedMs) + "}";
    }

    private static string DiagnosticsJson() =>
        "{\"stack\":\"" + Stack + "\",\"case\":\"" + CaseName + "\",\"variants\":{"
        + VariantJson("unbounded") + "," + VariantJson("bounded") + "}"
        + ",\"dlq_depth\":" + Dlq.Count
        + ",\"interpretation\":{"
        + "\"unbounded\":\"producer_blocked_ms = 0 y dropped = 0 se ven bien hasta que se mira queue_depth_peak y oldest_msg_age_ms_peak.\","
        + "\"bounded\":\"Las tres politicas pagan algo distinto: block paga latencia del productor, drop_oldest paga datos, dead_letter paga deuda operativa.\","
        + "\"dotnet_note\":\"Es el unico stack donde la politica de cola llena es un enum del constructor: se decide una vez y todos los productores la heredan, en vez de elegirse metodo por metodo en cada sitio de envio.\"}}";

    // ------------------------------------------------------------------
    // HTTP
    // ------------------------------------------------------------------

    private static async Task Main()
    {
        var port = int.TryParse(Environment.GetEnvironmentVariable("PORT"), out var p) ? p : 8080;
        var listener = new HttpListener();
        listener.Prefixes.Add($"http://+:{port}/");
        try { listener.Start(); }
        catch (HttpListenerException)
        {
            listener = new HttpListener();
            listener.Prefixes.Add($"http://*:{port}/");
            listener.Start();
        }
        Console.WriteLine($"[case15-dotnet] listening on {port}");

        var cts = new CancellationTokenSource();
        Console.CancelKeyPress += (_, e) => { e.Cancel = true; cts.Cancel(); try { listener.Stop(); } catch { } };

        while (!cts.IsCancellationRequested)
        {
            HttpListenerContext ctx;
            try { ctx = await listener.GetContextAsync().ConfigureAwait(false); }
            catch { break; }
            _ = Task.Run(() => Handle(ctx));
        }
    }

    private static async Task Handle(HttpListenerContext ctx)
    {
        var path = ctx.Request.Url?.AbsolutePath ?? "/";
        var q = QueryParams(ctx.Request.Url?.Query);
        var messages = Clamp(ParseInt(q.GetValueOrDefault("messages"), 120), 1, 2000);
        var capacity = Clamp(ParseInt(q.GetValueOrDefault("capacity"), 32), 1, 1000);
        var consumeMs = Clamp(ParseInt(q.GetValueOrDefault("consume_ms"), 2), 0, 100);
        var limit = Clamp(ParseInt(q.GetValueOrDefault("limit"), 20), 1, 200);
        var policy = q.GetValueOrDefault("policy", "block");
        if (!Policies.Contains(policy)) policy = "block";

        var status = 200;
        string body;
        try
        {
            switch (path)
            {
                case "/":
                case "/index":
                    body = "{\"case\":\"" + CaseName + "\",\"stack\":\"" + Stack
                         + "\",\"dotnet_specific\":\"System.Threading.Channels: la politica de cola llena es un enum del constructor (BoundedChannelFullMode), no una eleccion de metodo en cada envio.\""
                         + ",\"routes\":[\"/health\",\"/produce-unbounded?messages=120&consume_ms=2\",\"/produce-bounded?messages=120&capacity=32&policy=block&consume_ms=2\",\"/produce-bounded?messages=120&capacity=32&policy=drop_oldest\",\"/produce-bounded?messages=120&capacity=32&policy=dead_letter\",\"/queue/state\",\"/dlq?limit=20\",\"/diagnostics/summary\",\"/reset-lab\"]"
                         + ",\"allowed_policies\":[\"block\",\"drop_oldest\",\"dead_letter\"]}";
                    break;
                case "/health":
                    body = $"{{\"status\":\"ok\",\"stack\":\"{Stack}\",\"case\":\"{CaseName}\"}}";
                    break;
                case "/produce-unbounded":
                    body = await RunUnbounded(messages, consumeMs).ConfigureAwait(false);
                    break;
                case "/produce-bounded":
                    body = await RunBounded(messages, capacity, policy, consumeMs).ConfigureAwait(false);
                    break;
                case "/queue/state":
                    body = QueueStateJson();
                    break;
                case "/dlq":
                    body = DlqJson(limit);
                    break;
                case "/diagnostics/summary":
                    body = DiagnosticsJson();
                    break;
                case "/reset-lab":
                    while (Dlq.TryDequeue(out _)) { }
                    _metrics = Fresh();
                    _lastState = new Dictionary<string, object>();
                    body = "{\"status\":\"reset\",\"message\":\"DLQ y metricas reiniciadas.\"}";
                    break;
                default:
                    status = 404;
                    body = $"{{\"error\":\"Ruta no encontrada\",\"path\":\"{Escape(path)}\"}}";
                    break;
            }
        }
        catch (Exception e)
        {
            status = 500;
            body = $"{{\"error\":\"internal\",\"detail\":\"{Escape(e.Message)}\"}}";
        }

        SendJson(ctx, status, body);
    }

    private static int ParseInt(string? raw, int fallback) => int.TryParse(raw, out var v) ? v : fallback;

    private static int Clamp(int v, int lo, int hi) => Math.Max(lo, Math.Min(hi, v));

    private static string Escape(string? v) =>
        v == null ? "" : v.Replace("\\", "\\\\").Replace("\"", "\\\"");

    private static Dictionary<string, string> QueryParams(string? raw)
    {
        var d = new Dictionary<string, string>();
        if (string.IsNullOrEmpty(raw)) return d;
        if (raw.StartsWith('?')) raw = raw[1..];
        foreach (var pair in raw.Split('&', StringSplitOptions.RemoveEmptyEntries))
        {
            var parts = pair.Split('=', 2);
            d[WebUtility.UrlDecode(parts[0]) ?? ""] =
                parts.Length > 1 ? WebUtility.UrlDecode(parts[1]) ?? "" : "";
        }
        return d;
    }

    private static void SendJson(HttpListenerContext ctx, int status, string body)
    {
        try
        {
            var bytes = Encoding.UTF8.GetBytes(body);
            ctx.Response.StatusCode = status;
            ctx.Response.ContentType = "application/json; charset=utf-8";
            ctx.Response.ContentLength64 = bytes.Length;
            ctx.Response.OutputStream.Write(bytes, 0, bytes.Length);
        }
        catch { }
        finally { try { ctx.Response.OutputStream.Close(); } catch { } }
    }
}
