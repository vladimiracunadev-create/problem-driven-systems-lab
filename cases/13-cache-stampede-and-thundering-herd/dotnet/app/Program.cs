using System;
using System.Collections.Concurrent;
using System.Collections.Generic;
using System.Linq;
using System.Net;
using System.Text;
using System.Threading;
using System.Threading.Tasks;

// Caso 13 — Cache stampede (thundering herd) — stack .NET 8.
//
// Naive: la clave expira y los N llamadores concurrentes recalculan el origen.
// `origin_computations == concurrency`.
// Single-flight: `origin_computations == 1` sin importar cuantos lleguen.
//
// Primitiva .NET distintiva — y el matiz que la hace interesante:
//
//   `ConcurrentDictionary.GetOrAdd` NO garantiza que la fabrica corra una sola
//   vez. La documentacion lo dice explicitamente: si varios hilos entran a la
//   vez, la fabrica puede ejecutarse N veces y solo UNA de las instancias gana
//   el puesto en el diccionario. Para una cache de valores eso es apenas
//   desperdicio; para un single-flight es el bug entero — el origen recibe la
//   estampida igual.
//
//   El arreglo idiomatico es envolver el trabajo en `Lazy<Task<T>>` con
//   `LazyThreadSafetyMode.ExecutionAndPublication`: aunque GetOrAdd construya
//   varios Lazy, solo el que quedo en el diccionario recibe `.Value`, y el Lazy
//   garantiza que su fabrica corre exactamente una vez.
//
//   Es el contraste directo con Java, donde `computeIfAbsent` SI es atomico por
//   clave y no hace falta la envoltura. Misma estructura de datos aparente,
//   garantia distinta, y en .NET la garantia hay que traerla uno.
//
// El origen es CPU real (digest iterativo), no `Task.Delay`. Un delay no modela
// lo que duele: que el origen HACE el trabajo N veces.

internal static class Program
{
    private const string CaseName = "13 - Cache stampede y thundering herd";
    private static readonly string Stack = Environment.GetEnvironmentVariable("APP_STACK") ?? ".NET 8";

    private const long TtlBaseMs = 4000;
    private const int JitterPct = 25;
    private const double SoftFraction = 0.6;

    private sealed record CacheEntry(string Value, long ComputedAt, long SoftMs, long HardMs);

    private static readonly ConcurrentDictionary<string, CacheEntry> Cache = new();
    /// El single-flight: Lazy garantiza una sola ejecucion de la fabrica.
    /// El bool dice si ese vuelo REALMENTE tuvo que tocar el origen.
    private static readonly ConcurrentDictionary<string, Lazy<Task<bool>>> Inflight = new();

    private static int _originActive;
    private static int _originPeak;

    private sealed class Slot
    {
        public long Runs;
        public long OriginComputations;
        public long CacheHits;
        public long CoalescedWaiters;
        public long ServedStale;
        public int MaxStampedeDepth;
        public readonly List<double> WallSamples = new();
    }

    private static ConcurrentDictionary<string, Slot> _metrics = FreshMetrics();

    private static ConcurrentDictionary<string, Slot> FreshMetrics()
    {
        var d = new ConcurrentDictionary<string, Slot>();
        d["naive"] = new Slot();
        d["singleflight"] = new Slot();
        return d;
    }

    private static long NowMs() => Environment.TickCount64;

    // ------------------------------------------------------------------
    // Origen: trabajo real
    // ------------------------------------------------------------------

    private static string DigestWork(string key, int rounds)
    {
        unchecked
        {
            int h = 0;
            int salt = Math.Max(1, key.Length);
            long iterations = (long)rounds * 2000L;
            for (long i = 0; i < iterations; i++) h = h * 31 + (int)(i ^ salt);
            return h.ToString("x8");
        }
    }

    private static string ComputeOrigin(string key, int rounds)
    {
        var active = Interlocked.Increment(ref _originActive);
        InterlockedMax(ref _originPeak, active);
        try
        {
            var digest = DigestWork(key, rounds);
            CacheStore(key, digest);
            return digest;
        }
        finally
        {
            Interlocked.Decrement(ref _originActive);
        }
    }

    private static void InterlockedMax(ref int target, int value)
    {
        int current;
        do
        {
            current = Volatile.Read(ref target);
            if (value <= current) return;
        } while (Interlocked.CompareExchange(ref target, value, current) != current);
    }

    private static void CacheStore(string key, string value)
    {
        var spread = (int)(TtlBaseMs * JitterPct / 100);
        var jitter = Random.Shared.Next(-spread, spread + 1);
        var hard = TtlBaseMs + jitter;
        Cache[key] = new CacheEntry(value, NowMs(), (long)(hard * SoftFraction), hard);
    }

    /// fresh | stale | miss
    private static string CacheState(string key)
    {
        if (!Cache.TryGetValue(key, out var e)) return "miss";
        var age = NowMs() - e.ComputedAt;
        if (age <= e.SoftMs) return "fresh";
        if (age <= e.HardMs) return "stale";
        return "miss";
    }

    // ------------------------------------------------------------------
    // Los dos llamadores
    // ------------------------------------------------------------------

    private readonly record struct Outcome(double WaitMs, bool Computed, bool Stale, bool Waited);

    /// Compuerta de un solo uso, asincrona a proposito.
    ///
    /// `System.Threading.Barrier` bloquea el hilo que espera. Con 128 llamadores
    /// sobre el ThreadPool eso es un deadlock esperando a ocurrir: la barrera
    /// exige 128 hilos simultaneos y el pool los inyecta de a uno cada ~500 ms.
    /// Esperar con `await` en vez de bloquear libera el hilo mientras tanto.
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

    private static async Task<Outcome> CallerNaive(string key, int rounds, AsyncGate open, AsyncGate read)
    {
        await open.ArriveAndWait().ConfigureAwait(false);
        var t0 = NowMs();
        var state = CacheState(key);
        // Segunda fase: los N ya leyeron la cache antes de que ninguno escriba.
        await read.ArriveAndWait().ConfigureAwait(false);
        if (state == "fresh") return new Outcome(NowMs() - t0, false, false, false);
        ComputeOrigin(key, rounds);
        return new Outcome(NowMs() - t0, true, false, false);
    }

    private static async Task<Outcome> CallerSingleflight(string key, int rounds, AsyncGate open, AsyncGate read)
    {
        await open.ArriveAndWait().ConfigureAwait(false);
        var t0 = NowMs();
        var state = CacheState(key);
        await read.ArriveAndWait().ConfigureAwait(false);
        if (state == "fresh") return new Outcome(NowMs() - t0, false, false, false);

        var mine = new Lazy<Task<bool>>(
            () => Task.Run(() =>
            {
                // Double check dentro del vuelo. Sin esto el patron funciona
                // pero no alcanza: el lider de la primera generacion termina,
                // saca su Lazy del diccionario, y los llamadores que todavia no
                // habian llegado al GetOrAdd se vuelven lideres de una segunda
                // generacion. Con `cost` chico eso da 3 o 4 recalculos en vez
                // de 1 — falta este `if`, no el patron.
                if (CacheState(key) == "fresh") return false;
                ComputeOrigin(key, rounds);
                return true;
            }),
            LazyThreadSafetyMode.ExecutionAndPublication);

        // GetOrAdd puede devolver `mine` o el Lazy de otro hilo. Solo el que
        // quedo en el diccionario recibe `.Value`, y el Lazy garantiza una sola
        // ejecucion de la fabrica. Ese es todo el single-flight.
        var flight = Inflight.GetOrAdd(key, mine);
        var isLeader = ReferenceEquals(flight, mine);

        if (!isLeader && state == "stale")
        {
            // Soft TTL vencida: valor viejo servido sin esperar al refresh.
            return new Outcome(NowMs() - t0, false, true, false);
        }

        bool didCompute;
        try
        {
            didCompute = await flight.Value.ConfigureAwait(false);
        }
        finally
        {
            if (isLeader) Inflight.TryRemove(key, out _);
        }

        return isLeader
            ? new Outcome(NowMs() - t0, didCompute, false, !didCompute)
            : new Outcome(NowMs() - t0, false, false, true);
    }

    // ------------------------------------------------------------------
    // Orquestacion de la rafaga
    // ------------------------------------------------------------------

    private static async Task<string> RunBurst(string variant, string key, int concurrency, int rounds)
    {
        Volatile.Write(ref _originPeak, 0);
        var open = new AsyncGate(concurrency);
        var read = new AsyncGate(concurrency);
        var t0 = NowMs();
        var tasks = Enumerable.Range(0, concurrency).Select(_ => Task.Run(() =>
            variant == "naive"
                ? CallerNaive(key, rounds, open, read)
                : CallerSingleflight(key, rounds, open, read))).ToArray();
        var results = await Task.WhenAll(tasks).ConfigureAwait(false);
        double wallMs = NowMs() - t0;

        long computations = results.Count(r => r.Computed);
        long stale = results.Count(r => r.Stale);
        long waiters = results.Count(r => r.Waited);
        long hits = results.Length - computations - stale - waiters;
        var waits = results.Select(r => r.WaitMs).OrderBy(v => v).ToArray();
        var depth = Volatile.Read(ref _originPeak);

        var s = _metrics[variant];
        Interlocked.Increment(ref s.Runs);
        Interlocked.Add(ref s.OriginComputations, computations);
        Interlocked.Add(ref s.CacheHits, hits);
        Interlocked.Add(ref s.CoalescedWaiters, waiters);
        Interlocked.Add(ref s.ServedStale, stale);
        InterlockedMax(ref s.MaxStampedeDepth, depth);
        lock (s.WallSamples)
        {
            s.WallSamples.Add(wallMs);
            while (s.WallSamples.Count > 200) s.WallSamples.RemoveAt(0);
        }

        Cache.TryGetValue(key, out var current);
        var note = variant == "naive"
            ? "Sin coordinacion: cada llamador que vio el miss recalcula. El origen recibe la rafaga entera."
            : "Lazy<Task<T>> en ConcurrentDictionary: GetOrAdd puede construir varios, pero solo uno se evalua.";

        return "{\"variant\":\"" + variant + "\",\"key\":\"" + Escape(key) + "\""
             + ",\"concurrency\":" + concurrency
             + ",\"cost_rounds\":" + rounds
             + ",\"origin_computations\":" + computations
             + ",\"cache_hits\":" + hits
             + ",\"coalesced_waiters\":" + waiters
             + ",\"served_stale\":" + stale
             + ",\"stampede_depth\":" + depth
             + ",\"wall_ms\":" + Num(wallMs)
             + ",\"p99_wait_ms\":" + Num(Percentile(waits, 99))
             + ",\"max_wait_ms\":" + Num(waits.Length > 0 ? waits[^1] : 0)
             + ",\"value_digest\":\"" + (current?.Value ?? "") + "\""
             + ",\"ttl_base_ms\":" + TtlBaseMs
             + ",\"jitter_pct\":" + JitterPct
             + ",\"note\":\"" + note + "\"}";
    }

    private static double Percentile(double[] sorted, int pct)
    {
        if (sorted.Length == 0) return 0;
        var idx = (int)Math.Ceiling(pct / 100.0 * sorted.Length) - 1;
        idx = Math.Max(0, Math.Min(sorted.Length - 1, idx));
        return sorted[idx];
    }

    private static string Num(double v) => Math.Round(v, 2).ToString(System.Globalization.CultureInfo.InvariantCulture);

    // ------------------------------------------------------------------
    // Rutas
    // ------------------------------------------------------------------

    private static string CacheStateJson()
    {
        var sb = new StringBuilder(512);
        sb.Append("{\"entries\":{");
        var first = true;
        var now = NowMs();
        foreach (var kv in Cache)
        {
            if (!first) sb.Append(',');
            var age = now - kv.Value.ComputedAt;
            sb.Append('"').Append(Escape(kv.Key)).Append("\":{")
              .Append("\"age_ms\":").Append(age)
              .Append(",\"soft_ttl_ms\":").Append(kv.Value.SoftMs)
              .Append(",\"hard_ttl_ms\":").Append(kv.Value.HardMs)
              .Append(",\"soft_expired\":").Append(age > kv.Value.SoftMs ? "true" : "false")
              .Append(",\"hard_expired\":").Append(age > kv.Value.HardMs ? "true" : "false")
              .Append(",\"value_digest\":\"").Append(kv.Value.Value).Append("\"}");
            first = false;
        }
        sb.Append("},\"ttl_base_ms\":").Append(TtlBaseMs)
          .Append(",\"jitter_pct\":").Append(JitterPct)
          .Append(",\"soft_fraction\":").Append(Num(SoftFraction))
          .Append(",\"inflight_keys\":[");
        first = true;
        foreach (var k in Inflight.Keys)
        {
            if (!first) sb.Append(',');
            sb.Append('"').Append(Escape(k)).Append('"');
            first = false;
        }
        sb.Append("]}");
        return sb.ToString();
    }

    private static string VariantJson(string name)
    {
        var s = _metrics[name];
        double avg, p99;
        lock (s.WallSamples)
        {
            var arr = s.WallSamples.OrderBy(v => v).ToArray();
            avg = arr.Length == 0 ? 0 : arr.Average();
            p99 = Percentile(arr, 99);
        }
        return "\"" + name + "\":{\"runs\":" + Interlocked.Read(ref s.Runs)
             + ",\"origin_computations\":" + Interlocked.Read(ref s.OriginComputations)
             + ",\"cache_hits\":" + Interlocked.Read(ref s.CacheHits)
             + ",\"coalesced_waiters\":" + Interlocked.Read(ref s.CoalescedWaiters)
             + ",\"served_stale\":" + Interlocked.Read(ref s.ServedStale)
             + ",\"max_stampede_depth\":" + Volatile.Read(ref s.MaxStampedeDepth)
             + ",\"avg_wall_ms\":" + Num(avg)
             + ",\"p99_wall_ms\":" + Num(p99) + "}";
    }

    private static string DiagnosticsJson() =>
        "{\"stack\":\"" + Stack + "\",\"case\":\"" + CaseName + "\",\"variants\":{"
        + VariantJson("naive") + "," + VariantJson("singleflight") + "}"
        + ",\"origin_total_computations\":"
        + (Interlocked.Read(ref _metrics["naive"].OriginComputations)
           + Interlocked.Read(ref _metrics["singleflight"].OriginComputations))
        + ",\"interpretation\":{"
        + "\"naive\":\"origin_computations crece linealmente con la concurrencia: el origen ve la rafaga completa.\","
        + "\"singleflight\":\"origin_computations se mantiene en 1 por expiracion, sin importar cuantos llamadores lleguen.\","
        + "\"dotnet_note\":\"GetOrAdd no garantiza fabrica unica; la garantia la aporta Lazy con ExecutionAndPublication.\"}}";

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
        Console.WriteLine($"[case13-dotnet] listening on {port}");

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
        var key = q.GetValueOrDefault("key", "report-alpha");
        if (key.Length > 60) key = key[..60];
        var concurrency = Clamp(ParseInt(q.GetValueOrDefault("concurrency"), 16), 1, 128);
        var rounds = Clamp(ParseInt(q.GetValueOrDefault("cost"), 40), 1, 400);

        var status = 200;
        string body;
        try
        {
            switch (path)
            {
                case "/":
                case "/index":
                    body = "{\"case\":\"" + CaseName + "\",\"stack\":\"" + Stack
                         + "\",\"dotnet_specific\":\"ConcurrentDictionary<string, Lazy<Task<T>>> con ExecutionAndPublication: la garantia de fabrica unica la da Lazy, no GetOrAdd.\""
                         + ",\"routes\":[\"/health\",\"/cache-naive?key=report-alpha&concurrency=16&cost=40\",\"/cache-singleflight?key=report-alpha&concurrency=16&cost=40\",\"/cache/state\",\"/diagnostics/summary\",\"/reset-lab\"]}";
                    break;
                case "/health":
                    body = $"{{\"status\":\"ok\",\"stack\":\"{Stack}\",\"case\":\"{CaseName}\"}}";
                    break;
                case "/cache-naive":
                    body = await RunBurst("naive", key, concurrency, rounds).ConfigureAwait(false);
                    break;
                case "/cache-singleflight":
                    body = await RunBurst("singleflight", key, concurrency, rounds).ConfigureAwait(false);
                    break;
                case "/cache/state":
                    body = CacheStateJson();
                    break;
                case "/diagnostics/summary":
                    body = DiagnosticsJson();
                    break;
                case "/reset-lab":
                    Cache.Clear();
                    Inflight.Clear();
                    _metrics = FreshMetrics();
                    Volatile.Write(ref _originPeak, 0);
                    body = "{\"status\":\"reset\",\"message\":\"Cache y metricas reiniciadas.\"}";
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

    private static int ParseInt(string? raw, int fallback) =>
        int.TryParse(raw, out var v) ? v : fallback;

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
