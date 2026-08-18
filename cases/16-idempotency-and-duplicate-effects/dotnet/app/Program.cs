using System;
using System.Collections.Concurrent;
using System.Collections.Generic;
using System.Linq;
using System.Net;
using System.Text;
using System.Threading;
using System.Threading.Tasks;

// Caso 16 — Idempotencia y efectos duplicados — stack .NET 8.
//
// Unsafe: N reintentos del mismo pago aplican N cargos.
// Idempotent: `Idempotency-Key` persistida + outbox pattern.
//
// Primitiva .NET distintiva:
//   `ConcurrentDictionary.TryAdd(key, value)`.
//
//   Devuelve `true` si la clave se agrego y `false` si ya estaba. Es la misma
//   operacion que `putIfAbsent` de Java, `LoadOrStore` de Go y `entry()` de
//   Rust — con la diferencia de forma de que aca el resultado es un `bool`
//   directo, asi que el `if` se lee como la pregunta del negocio:
//
//       if (Idempotency.TryAdd(key, mine))  ->  "es la primera vez que veo esto"
//
//   Vale notar el contraste con el caso 13: alli `GetOrAdd` **no** garantizaba
//   fabrica unica y hubo que envolver en `Lazy<T>`. `TryAdd` si es atomico —
//   porque no ejecuta ninguna fabrica, solo intenta insertar un valor ya
//   construido. Las dos APIs viven en la misma clase y tienen garantias
//   distintas, y saber cual es cual es la diferencia entre cobrar una vez y
//   cobrar cinco.
//
// La segunda mitad es el **outbox pattern**: el cargo va a la base y el email a
// una cola, sin transaccion que los abarque. El outbox escribe el efecto en la
// misma escritura que el cargo y deja que un worker lo entregue.

internal static class Program
{
    private const string CaseName = "16 - Idempotencia y efectos duplicados";
    private static readonly string Stack = Environment.GetEnvironmentVariable("APP_STACK") ?? ".NET 8";

    private const long DedupeWindowMs = 24L * 60 * 60 * 1000;

    private sealed class Entry
    {
        public volatile string? Response;
        public readonly long StoredAt = DateTimeOffset.UtcNow.ToUnixTimeMilliseconds();
    }

    private sealed record OutboxRow(string Key, string Kind, long AmountCents, string At, string Status, string Via);

    private static ConcurrentDictionary<string, long> _ledger = new();
    private static ConcurrentDictionary<string, Entry> _idempotency = new();
    private static List<OutboxRow> _outbox = new();
    private static List<OutboxRow> _delivered = new();
    private static readonly object BoxLock = new();

    private sealed class Slot
    {
        public long Runs, Attempts, ChargesApplied, DuplicatesPrevented, DuplicatesApplied,
                    IdempotencyHits, SideEffects, Overcharged;
    }

    private static ConcurrentDictionary<string, Slot> _metrics = Fresh();

    private static ConcurrentDictionary<string, Slot> Fresh()
    {
        var d = new ConcurrentDictionary<string, Slot>();
        d["unsafe"] = new Slot();
        d["idempotent"] = new Slot();
        return d;
    }

    private static long NowMs() => DateTimeOffset.UtcNow.ToUnixTimeMilliseconds();
    private static string NowIso() => DateTime.UtcNow.ToString("o");

    private static long ApplyCharge(string account, long amount) =>
        _ledger.AddOrUpdate(account, amount, (_, cur) => cur + amount);

    /// El efecto DIRECTO, fuera de la transaccion del cargo.
    private static void EmitDirect(string key, long amount)
    {
        lock (BoxLock)
        {
            _delivered.Add(new OutboxRow(key, "payment_receipt_email", amount, NowIso(), "delivered", "direct"));
            while (_delivered.Count > 200) _delivered.RemoveAt(0);
        }
    }

    /// Escribe el efecto en el outbox, junto al cargo. No lo entrega.
    private static void EnqueueOutbox(string key, long amount)
    {
        lock (BoxLock)
        {
            _outbox.Add(new OutboxRow(key, "payment_receipt_email", amount, NowIso(), "pending", "outbox"));
            while (_outbox.Count > 200) _outbox.RemoveAt(0);
        }
    }

    /// El worker que mueve el outbox al destino real. Idempotente por diseño.
    private static int DrainOutbox()
    {
        var moved = 0;
        lock (BoxLock)
        {
            for (var i = 0; i < _outbox.Count; i++)
            {
                if (_outbox[i].Status != "pending") continue;
                var done = _outbox[i] with { Status = "delivered" };
                _outbox[i] = done;
                _delivered.Add(done);
                moved++;
            }
            while (_delivered.Count > 200) _delivered.RemoveAt(0);
        }
        return moved;
    }

    private readonly record struct Outcome(bool Applied, bool Hit, double LookupMs);

    /// Compuerta asincrona de un solo uso: los reintentos de un cliente con
    /// timeout llegan casi juntos, no en fila.
    private sealed class AsyncGate
    {
        private readonly int _parties;
        private int _arrived;
        private readonly TaskCompletionSource _tcs = new(TaskCreationOptions.RunContinuationsAsynchronously);
        public AsyncGate(int parties) => _parties = parties;
        public Task ArriveAndWait()
        {
            if (Interlocked.Increment(ref _arrived) >= _parties) _tcs.TrySetResult();
            return _tcs.Task;
        }
    }

    private static async Task<Outcome> AttemptUnsafe(string key, string account, long amount, AsyncGate gate)
    {
        await gate.ArriveAndWait().ConfigureAwait(false);
        ApplyCharge(account, amount);
        EmitDirect(key, amount);
        return new Outcome(true, false, 0);
    }

    private static async Task<Outcome> AttemptIdempotent(string key, string account, long amount, AsyncGate gate)
    {
        await gate.ArriveAndWait().ConfigureAwait(false);
        var sw = System.Diagnostics.Stopwatch.StartNew();

        if (_idempotency.TryGetValue(key, out var existing) && NowMs() - existing.StoredAt > DedupeWindowMs)
        {
            // Fuera de la ventana: la clave caduco y esto es una operacion nueva.
            _idempotency.TryRemove(key, out _);
        }

        var mine = new Entry();
        // TryAdd SI es atomico — a diferencia de GetOrAdd con fabrica, que en el
        // caso 13 hubo que envolver en Lazy<T>. Aca no se ejecuta ninguna
        // fabrica: solo se intenta insertar un valor ya construido.
        if (_idempotency.TryAdd(key, mine))
        {
            // El cargo y el efecto pendiente se escriben JUNTOS.
            var balance = ApplyCharge(account, amount);
            EnqueueOutbox(key, amount);
            mine.Response = "{\"status\":\"charged\",\"key\":\"" + Escape(key) + "\",\"account\":\"" + Escape(account)
                          + "\",\"amount_cents\":" + amount + ",\"balance_cents\":" + balance + "}";
            return new Outcome(true, false, sw.Elapsed.TotalMilliseconds);
        }

        // Reintento: se devuelve exactamente la misma respuesta que habria
        // recibido el intento original.
        _idempotency.TryGetValue(key, out var winner);
        var deadline = NowMs() + 5000;
        while (winner?.Response is null && NowMs() < deadline)
        {
            await Task.Yield();
            _idempotency.TryGetValue(key, out winner);
        }
        return new Outcome(false, true, sw.Elapsed.TotalMilliseconds);
    }

    private static async Task<string> RunAttempts(string variant, string key, string account, long amount, int attempts)
    {
        var gate = new AsyncGate(attempts);
        var sw = System.Diagnostics.Stopwatch.StartNew();
        var tasks = Enumerable.Range(0, attempts).Select(_ => Task.Run(() =>
            variant == "unsafe"
                ? AttemptUnsafe(key, account, amount, gate)
                : AttemptIdempotent(key, account, amount, gate))).ToArray();
        var results = await Task.WhenAll(tasks).ConfigureAwait(false);
        var wallMs = sw.Elapsed.TotalMilliseconds;

        long applied = results.Count(r => r.Applied);
        long hits = results.Count(r => r.Hit);
        var lookups = results.Where(r => r.LookupMs > 0).Select(r => r.LookupMs).ToArray();
        var deliveredNow = variant == "idempotent" ? DrainOutbox() : 0;

        _ledger.TryGetValue(account, out var balance);
        long pending, deliveredTotal;
        lock (BoxLock)
        {
            pending = _outbox.Count(r => r.Status == "pending");
            deliveredTotal = _delivered.Count;
        }
        var overcharged = Math.Max(0, applied - 1) * amount;
        long effects = variant == "unsafe" ? attempts : deliveredNow;

        var s = _metrics[variant];
        Interlocked.Increment(ref s.Runs);
        Interlocked.Add(ref s.Attempts, attempts);
        Interlocked.Add(ref s.ChargesApplied, applied);
        Interlocked.Add(ref s.DuplicatesPrevented, hits);
        Interlocked.Add(ref s.DuplicatesApplied, Math.Max(0, applied - 1));
        Interlocked.Add(ref s.IdempotencyHits, hits);
        Interlocked.Add(ref s.SideEffects, effects);
        Interlocked.Add(ref s.Overcharged, overcharged);

        var note = variant == "unsafe"
            ? "Sin clave de idempotencia: cada reintento aplica su propio cargo y publica su propio efecto. El cliente reintento por un timeout, no porque quisiera pagar de nuevo."
            : "TryAdd resuelve la carrera en una sola operacion atomica + outbox en la misma escritura que el cargo: un cobro, un efecto, y los reintentos reciben la respuesta guardada.";

        return "{\"variant\":\"" + variant + "\",\"key\":\"" + Escape(key) + "\",\"account\":\"" + Escape(account) + "\""
             + ",\"attempts\":" + attempts
             + ",\"amount_cents\":" + amount
             + ",\"charges_applied\":" + applied
             + ",\"duplicates_prevented\":" + hits
             + ",\"duplicates_applied\":" + Math.Max(0, applied - 1)
             + ",\"idempotency_hits\":" + hits
             + ",\"balance_cents\":" + balance
             + ",\"overcharged_cents\":" + overcharged
             + ",\"side_effects_emitted\":" + effects
             + ",\"side_effect_transport\":\"" + (variant == "unsafe"
                    ? "directo, fuera de la transaccion" : "outbox, en la misma escritura que el cargo") + "\""
             + ",\"outbox_pending\":" + pending
             + ",\"outbox_delivered\":" + deliveredTotal
             + ",\"lookup_overhead_ms\":" + Num(lookups.Length > 0 ? lookups.Average() : 0, 3)
             + ",\"dedupe_window_ms\":" + DedupeWindowMs
             + ",\"wall_ms\":" + Num(wallMs)
             + ",\"note\":\"" + note + "\"}";
    }

    private static string Num(double v, int digits = 2) =>
        Math.Round(v, digits).ToString(System.Globalization.CultureInfo.InvariantCulture);

    private static string IdempotencyStateJson()
    {
        var sb = new StringBuilder(512);
        sb.Append("{\"keys\":{");
        var first = true;
        var now = NowMs();
        foreach (var kv in _idempotency)
        {
            if (!first) sb.Append(',');
            var age = now - kv.Value.StoredAt;
            sb.Append('"').Append(Escape(kv.Key)).Append("\":{\"age_ms\":").Append(age)
              .Append(",\"expired\":").Append(age > DedupeWindowMs ? "true" : "false")
              .Append(",\"has_response\":").Append(kv.Value.Response is not null ? "true" : "false").Append('}');
            first = false;
        }
        sb.Append("},\"key_count\":").Append(_idempotency.Count).Append(",\"ledger_cents\":{");
        first = true;
        foreach (var kv in _ledger)
        {
            if (!first) sb.Append(',');
            sb.Append('"').Append(Escape(kv.Key)).Append("\":").Append(kv.Value);
            first = false;
        }
        sb.Append("},\"dedupe_window_ms\":").Append(DedupeWindowMs)
          .Append(",\"note\":\"La tabla de idempotencia necesita ventana y limpieza: una clave que vive para siempre es una tabla que crece para siempre.\"}");
        return sb.ToString();
    }

    private static string RowsJson(List<OutboxRow> list, int limit)
    {
        var sb = new StringBuilder(256);
        sb.Append('[');
        var n = 0;
        for (var i = list.Count - 1; i >= 0 && n < limit; i--, n++)
        {
            if (n > 0) sb.Append(',');
            var r = list[i];
            sb.Append("{\"key\":\"").Append(Escape(r.Key)).Append("\",\"kind\":\"").Append(r.Kind)
              .Append("\",\"amount_cents\":").Append(r.AmountCents).Append(",\"at\":\"").Append(r.At)
              .Append("\",\"status\":\"").Append(r.Status).Append("\",\"via\":\"").Append(r.Via).Append("\"}");
        }
        sb.Append(']');
        return sb.ToString();
    }

    private static string OutboxJson(int limit)
    {
        lock (BoxLock)
        {
            return "{\"outbox_pending\":" + _outbox.Count(r => r.Status == "pending")
                 + ",\"outbox_total\":" + _outbox.Count
                 + ",\"delivered_total\":" + _delivered.Count
                 + ",\"limit\":" + limit
                 + ",\"outbox\":" + RowsJson(_outbox, limit)
                 + ",\"delivered\":" + RowsJson(_delivered, limit)
                 + ",\"note\":\"El outbox se escribe en la misma transaccion que el cargo. El worker que lo drena puede reintentar sin miedo: entregar dos veces el mismo row es visible y corregible, perder el efecto no.\"}";
        }
    }

    private static string VariantJson(string name)
    {
        var s = _metrics[name];
        return "\"" + name + "\":{\"runs\":" + Interlocked.Read(ref s.Runs)
             + ",\"attempts\":" + Interlocked.Read(ref s.Attempts)
             + ",\"charges_applied\":" + Interlocked.Read(ref s.ChargesApplied)
             + ",\"duplicates_prevented\":" + Interlocked.Read(ref s.DuplicatesPrevented)
             + ",\"duplicates_applied\":" + Interlocked.Read(ref s.DuplicatesApplied)
             + ",\"idempotency_hits\":" + Interlocked.Read(ref s.IdempotencyHits)
             + ",\"side_effects_emitted\":" + Interlocked.Read(ref s.SideEffects)
             + ",\"overcharged_cents\":" + Interlocked.Read(ref s.Overcharged) + "}";
    }

    private static string DiagnosticsJson()
    {
        long pending, deliveredTotal;
        lock (BoxLock)
        {
            pending = _outbox.Count(r => r.Status == "pending");
            deliveredTotal = _delivered.Count;
        }
        return "{\"stack\":\"" + Stack + "\",\"case\":\"" + CaseName + "\",\"variants\":{"
             + VariantJson("unsafe") + "," + VariantJson("idempotent") + "}"
             + ",\"outbox_pending\":" + pending
             + ",\"outbox_delivered\":" + deliveredTotal
             + ",\"interpretation\":{"
             + "\"unsafe\":\"charges_applied = attempts: cada reintento cobro de nuevo. overcharged_cents es plata real que el negocio tiene que devolver.\","
             + "\"idempotent\":\"charges_applied = 1 y duplicates_prevented = attempts - 1, sin importar cuantas veces reintente el cliente.\","
             + "\"dotnet_note\":\"TryAdd SI es atomico, a diferencia de GetOrAdd con fabrica que en el caso 13 hubo que envolver en Lazy. Las dos APIs viven en la misma clase con garantias distintas, y saber cual es cual es la diferencia entre cobrar una vez y cobrar cinco.\"}}";
    }

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
        Console.WriteLine($"[case16-dotnet] listening on {port}");

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
        var key = q.GetValueOrDefault("key", "order-4711");
        if (key.Length > 60) key = key[..60];
        var account = q.GetValueOrDefault("account", "acct-1");
        if (account.Length > 40) account = account[..40];
        var attempts = Clamp(ParseInt(q.GetValueOrDefault("attempts"), 5), 1, 64);
        long amount = Clamp(ParseInt(q.GetValueOrDefault("amount"), 2500), 1, 10_000_000);
        var limit = Clamp(ParseInt(q.GetValueOrDefault("limit"), 20), 1, 200);

        var status = 200;
        string body;
        try
        {
            switch (path)
            {
                case "/":
                case "/index":
                    body = "{\"case\":\"" + CaseName + "\",\"stack\":\"" + Stack
                         + "\",\"dotnet_specific\":\"ConcurrentDictionary.TryAdd: atomico de verdad, a diferencia de GetOrAdd con fabrica. El if se lee como la pregunta del negocio: es la primera vez que veo esto.\""
                         + ",\"routes\":[\"/health\",\"/charge-unsafe?key=order-4711&attempts=5&amount=2500\",\"/charge-idempotent?key=order-4711&attempts=5&amount=2500\",\"/idempotency/state\",\"/outbox?limit=20\",\"/diagnostics/summary\",\"/reset-lab\"]}";
                    break;
                case "/health":
                    body = $"{{\"status\":\"ok\",\"stack\":\"{Stack}\",\"case\":\"{CaseName}\"}}";
                    break;
                case "/charge-unsafe":
                    body = await RunAttempts("unsafe", key, account, amount, attempts).ConfigureAwait(false);
                    break;
                case "/charge-idempotent":
                    body = await RunAttempts("idempotent", key, account, amount, attempts).ConfigureAwait(false);
                    break;
                case "/idempotency/state":
                    body = IdempotencyStateJson();
                    break;
                case "/outbox":
                    body = OutboxJson(limit);
                    break;
                case "/diagnostics/summary":
                    body = DiagnosticsJson();
                    break;
                case "/reset-lab":
                    _ledger = new ConcurrentDictionary<string, long>();
                    _idempotency = new ConcurrentDictionary<string, Entry>();
                    lock (BoxLock) { _outbox = new List<OutboxRow>(); _delivered = new List<OutboxRow>(); }
                    _metrics = Fresh();
                    body = "{\"status\":\"reset\",\"message\":\"Ledger, claves de idempotencia y outbox reiniciados.\"}";
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
