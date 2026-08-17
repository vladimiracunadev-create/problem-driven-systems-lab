using System;
using System.Collections.Concurrent;
using System.Collections.Generic;
using System.Linq;
using System.Net;
using System.Text;
using System.Threading;
using System.Threading.Tasks;

// Caso 14 — Agotamiento del pool de conexiones — stack .NET 8.
//
// Leaky: sin deadline de adquisicion y con el `Release()` solo en el camino
// feliz. Cada excepcion se lleva una conexion que nunca vuelve al pool.
// Managed: `SemaphoreSlim.WaitAsync(timeout)` para el deadline y `using` para
// la devolucion garantizada.
//
// Primitiva .NET distintiva:
//   `SemaphoreSlim` con `WaitAsync(TimeSpan)` mas un `Lease : IDisposable`.
//
//   `WaitAsync` con timeout devuelve `false` en vez de lanzar: el deadline es
//   un valor de retorno, no una excepcion. Eso hace que "no habia conexion" y
//   "la conexion fallo" sean dos caminos distintos en el codigo, que es
//   exactamente la distincion que el llamador necesita para decidir si
//   reintentar o rendirse.
//
//   La segunda mitad es `using`. El compilador genera el `finally` que llama a
//   `Dispose()` en todos los caminos de salida — igual que try-with-resources en
//   Java. La diferencia con Java es de forma, no de garantia: `using var` no
//   necesita bloque anidado, asi que el codigo correcto queda MAS corto que el
//   incorrecto. Es el unico stack del lab donde hacer lo correcto ahorra lineas.
//
// El "query" es un `Task.Delay` a proposito, al reves que en el caso 13. Una
// conexion se retiene mientras se espera a la red, no mientras se quema CPU.

internal static class Program
{
    private const string CaseName = "14 - Agotamiento del pool de conexiones";
    private static readonly string Stack = Environment.GetEnvironmentVariable("APP_STACK") ?? ".NET 8";

    private const int AcquireTimeoutMs = 200;
    /// Sin deadline la variante leaky no terminaria. El watchdog permite medirla.
    private const int LeakyWatchdogMs = 2000;

    private sealed class Conn
    {
        public int Id { get; init; }
        public int Uses;
    }

    /// Pool sobre SemaphoreSlim + una bolsa concurrente de conexiones libres.
    private sealed class Pool
    {
        public readonly int Size;
        private readonly SemaphoreSlim _permits;
        private readonly ConcurrentBag<Conn> _free = new();
        public long Acquired;
        public long Released;
        private int _waiting;
        public int WaitingPeak;

        public Pool(int size)
        {
            Size = size;
            _permits = new SemaphoreSlim(size, size);
            for (var i = 1; i <= size; i++) _free.Add(new Conn { Id = i });
        }

        /// Devuelve null si vencio el deadline. El timeout es un valor de
        /// retorno, no una excepcion.
        public async Task<Conn?> AcquireAsync(int timeoutMs)
        {
            var w = Interlocked.Increment(ref _waiting);
            InterlockedMax(ref WaitingPeak, w);
            try
            {
                if (!await _permits.WaitAsync(timeoutMs).ConfigureAwait(false)) return null;
            }
            finally
            {
                Interlocked.Decrement(ref _waiting);
            }
            if (!_free.TryTake(out var conn))
            {
                _permits.Release();
                return null;
            }
            Interlocked.Increment(ref conn.Uses);
            Interlocked.Increment(ref Acquired);
            return conn;
        }

        public void Release(Conn? conn)
        {
            if (conn is null) return;
            Interlocked.Increment(ref Released);
            _free.Add(conn);
            _permits.Release();
        }

        /// Lease IDisposable: el compilador genera el finally que lo cierra.
        public async Task<Lease?> LeaseAsync(int timeoutMs)
        {
            var conn = await AcquireAsync(timeoutMs).ConfigureAwait(false);
            return conn is null ? null : new Lease(this, conn);
        }

        public int Available => _free.Count;
        public int WaitingNow => Volatile.Read(ref _waiting);
        public long Leaked => Interlocked.Read(ref Acquired) - Interlocked.Read(ref Released);
    }

    private sealed class Lease : IDisposable
    {
        private readonly Pool _pool;
        public readonly Conn Conn;
        public Lease(Pool pool, Conn conn) { _pool = pool; Conn = conn; }
        public void Dispose() => _pool.Release(Conn);
    }

    private static Pool _pool = new(4);

    private sealed class Slot
    {
        public long Runs;
        public long Completed;
        public long FailedQuery;
        public long FailedTimeout;
        public long Hung;
        public int MaxLeaked;
        public readonly List<double> WaitSamples = new();
    }

    private static ConcurrentDictionary<string, Slot> _metrics = FreshMetrics();

    private static ConcurrentDictionary<string, Slot> FreshMetrics()
    {
        var d = new ConcurrentDictionary<string, Slot>();
        d["leaky"] = new Slot();
        d["managed"] = new Slot();
        return d;
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

    /// Reparto determinista de fallos.
    ///
    /// `idx % 100 < failRate` parece equivalente y no lo es: con 24 requests y
    /// failRate=25 fallarian las 24, porque todos los indices son menores que 25.
    private static bool Fails(int idx, int failRate) => idx * 37 % 100 < failRate;

    /// El trabajo que retiene la conexion: una espera, no CPU.
    private static async Task RunQuery(Conn conn, int queryMs, bool shouldFail)
    {
        await Task.Delay(queryMs).ConfigureAwait(false);
        if (shouldFail) throw new InvalidOperationException($"query fallo en la conexion {conn.Id}");
    }

    private readonly record struct Outcome(string Kind, double WaitMs);

    // ------------------------------------------------------------------
    // Variante leaky
    // ------------------------------------------------------------------

    private static async Task<Outcome> WorkerLeaky(int idx, int queryMs, int failRate)
    {
        var t0 = Environment.TickCount64;
        var conn = await _pool.AcquireAsync(LeakyWatchdogMs).ConfigureAwait(false);
        double waitMs = Environment.TickCount64 - t0;
        if (conn is null) return new Outcome("hung", waitMs);

        // El bug: no hay using ni finally. Si RunQuery lanza, la linea de
        // Release nunca se ejecuta. Nada en los logs dice "se fugo una
        // conexion" — el pool simplemente se achica en silencio.
        try
        {
            await RunQuery(conn, queryMs, Fails(idx, failRate)).ConfigureAwait(false);
        }
        catch (InvalidOperationException)
        {
            return new Outcome("failed_query", waitMs);
        }
        _pool.Release(conn);
        return new Outcome("completed", waitMs);
    }

    // ------------------------------------------------------------------
    // Variante managed
    // ------------------------------------------------------------------

    private static async Task<Outcome> WorkerManaged(int idx, int queryMs, int failRate)
    {
        var t0 = Environment.TickCount64;
        var lease = await _pool.LeaseAsync(AcquireTimeoutMs).ConfigureAwait(false);
        double waitMs = Environment.TickCount64 - t0;
        if (lease is null)
        {
            // Falla rapido y de forma contable: "no habia conexion" es un
            // camino distinto de "la conexion fallo".
            return new Outcome("failed_timeout", waitMs);
        }

        // `using var` sin bloque anidado: el codigo correcto queda mas corto
        // que el incorrecto. El compilador genera el finally que llama a
        // Dispose() en todos los caminos de salida.
        using var held = lease;
        try
        {
            await RunQuery(held.Conn, queryMs, Fails(idx, failRate)).ConfigureAwait(false);
            return new Outcome("completed", waitMs);
        }
        catch (InvalidOperationException)
        {
            return new Outcome("failed_query", waitMs);
        }
    }

    // ------------------------------------------------------------------
    // Orquestacion
    // ------------------------------------------------------------------

    private static async Task<string> RunLoad(string variant, int requests, int poolSize, int queryMs, int failRate)
    {
        _pool = new Pool(poolSize);
        var t0 = Environment.TickCount64;
        var tasks = Enumerable.Range(0, requests).Select(i => Task.Run(() =>
            variant == "leaky" ? WorkerLeaky(i, queryMs, failRate) : WorkerManaged(i, queryMs, failRate))).ToArray();
        var results = await Task.WhenAll(tasks).ConfigureAwait(false);
        double wallMs = Environment.TickCount64 - t0;

        long completed = results.Count(r => r.Kind == "completed");
        long failedQuery = results.Count(r => r.Kind == "failed_query");
        long failedTimeout = results.Count(r => r.Kind == "failed_timeout");
        long hung = results.Count(r => r.Kind == "hung");
        var waits = results.Select(r => r.WaitMs).OrderBy(v => v).ToArray();

        var s = _metrics[variant];
        Interlocked.Increment(ref s.Runs);
        Interlocked.Add(ref s.Completed, completed);
        Interlocked.Add(ref s.FailedQuery, failedQuery);
        Interlocked.Add(ref s.FailedTimeout, failedTimeout);
        Interlocked.Add(ref s.Hung, hung);
        InterlockedMax(ref s.MaxLeaked, (int)_pool.Leaked);
        lock (s.WaitSamples)
        {
            s.WaitSamples.AddRange(waits);
            while (s.WaitSamples.Count > 500) s.WaitSamples.RemoveAt(0);
        }

        var note = variant == "leaky"
            ? "Sin deadline y con Release solo en el camino feliz: cada excepcion se lleva una conexion y el pool se achica en silencio."
            : "WaitAsync(timeout) + using: los fallos siguen ocurriendo, pero fallan rapido y devuelven la conexion.";

        return "{\"variant\":\"" + variant + "\",\"requests\":" + requests
             + ",\"pool_size\":" + poolSize
             + ",\"query_ms\":" + queryMs
             + ",\"fail_rate_pct\":" + failRate
             + ",\"acquire_timeout_ms\":" + (variant == "managed" ? AcquireTimeoutMs.ToString() : "null")
             + ",\"completed\":" + completed
             + ",\"failed_query\":" + failedQuery
             + ",\"failed_timeout\":" + failedTimeout
             + ",\"hung\":" + hung
             + ",\"acquired\":" + Interlocked.Read(ref _pool.Acquired)
             + ",\"released\":" + Interlocked.Read(ref _pool.Released)
             + ",\"leaked\":" + _pool.Leaked
             + ",\"pool_available_after\":" + _pool.Available
             + ",\"pool_waiting_peak\":" + _pool.WaitingPeak
             + ",\"pool_wait_ms_p99\":" + Num(Percentile(waits, 99))
             + ",\"pool_wait_ms_max\":" + Num(waits.Length > 0 ? waits[^1] : 0)
             + ",\"wall_ms\":" + Num(wallMs)
             + ",\"littles_law\":" + LittlesLaw(requests, queryMs, wallMs)
             + ",\"note\":\"" + note + "\"}";
    }

    private static string LittlesLaw(int requests, int queryMs, double wallMs)
    {
        if (wallMs <= 0)
            return "{\"avg_throughput_rps\":0,\"avg_query_ms\":" + queryMs + ",\"recommended_pool_size\":1}";
        var rps = requests / (wallMs / 1000.0);
        var recommended = Math.Max(1, (int)Math.Ceiling(rps * (queryMs / 1000.0)) + 2);
        return "{\"avg_throughput_rps\":" + Num(rps)
             + ",\"avg_query_ms\":" + queryMs
             + ",\"recommended_pool_size\":" + recommended
             + ",\"formula\":\"ceil(throughput_rps * query_s) + 2 de buffer\"}";
    }

    private static double Percentile(double[] sorted, int pct)
    {
        if (sorted.Length == 0) return 0;
        var idx = (int)Math.Ceiling(pct / 100.0 * sorted.Length) - 1;
        return sorted[Math.Max(0, Math.Min(sorted.Length - 1, idx))];
    }

    private static string Num(double v) =>
        Math.Round(v, 2).ToString(System.Globalization.CultureInfo.InvariantCulture);

    private static string PoolStateJson() =>
        "{\"initialized\":true,\"pool_size\":" + _pool.Size
        + ",\"available\":" + _pool.Available
        + ",\"acquired_total\":" + Interlocked.Read(ref _pool.Acquired)
        + ",\"released_total\":" + Interlocked.Read(ref _pool.Released)
        + ",\"leaked\":" + _pool.Leaked
        + ",\"waiting_now\":" + _pool.WaitingNow
        + ",\"waiting_peak\":" + _pool.WaitingPeak
        + ",\"acquire_timeout_ms\":" + AcquireTimeoutMs
        + ",\"leaky_watchdog_ms\":" + LeakyWatchdogMs + "}";

    private static string VariantJson(string name)
    {
        var s = _metrics[name];
        double avg, p99;
        lock (s.WaitSamples)
        {
            var arr = s.WaitSamples.OrderBy(v => v).ToArray();
            avg = arr.Length == 0 ? 0 : arr.Average();
            p99 = Percentile(arr, 99);
        }
        return "\"" + name + "\":{\"runs\":" + Interlocked.Read(ref s.Runs)
             + ",\"completed\":" + Interlocked.Read(ref s.Completed)
             + ",\"failed_query\":" + Interlocked.Read(ref s.FailedQuery)
             + ",\"failed_timeout\":" + Interlocked.Read(ref s.FailedTimeout)
             + ",\"hung\":" + Interlocked.Read(ref s.Hung)
             + ",\"max_leaked\":" + Volatile.Read(ref s.MaxLeaked)
             + ",\"avg_wait_ms\":" + Num(avg)
             + ",\"p99_wait_ms\":" + Num(p99) + "}";
    }

    private static string DiagnosticsJson() =>
        "{\"stack\":\"" + Stack + "\",\"case\":\"" + CaseName + "\",\"variants\":{"
        + VariantJson("leaky") + "," + VariantJson("managed") + "}"
        + ",\"pool\":" + PoolStateJson()
        + ",\"interpretation\":{"
        + "\"leaky\":\"leaked > 0 y hung > 0: las conexiones perdidas en el camino de excepcion no vuelven, y lo que llega despues espera a algo que ya no existe.\","
        + "\"managed\":\"leaked = 0 siempre. Los fallos de query se siguen contando, pero la conexion vuelve al pool y el que no alcanza recibe un timeout rapido.\","
        + "\"dotnet_note\":\"WaitAsync devuelve false en vez de lanzar: 'no habia conexion' y 'la conexion fallo' quedan como dos caminos distintos en el codigo.\"}}";

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
        Console.WriteLine($"[case14-dotnet] listening on {port}");

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
        var requests = Clamp(ParseInt(q.GetValueOrDefault("requests"), 24), 1, 200);
        var poolSize = Clamp(ParseInt(q.GetValueOrDefault("pool"), 4), 1, 64);
        var queryMs = Clamp(ParseInt(q.GetValueOrDefault("query_ms"), 25), 1, 500);
        var failRate = Clamp(ParseInt(q.GetValueOrDefault("fail_rate"), 25), 0, 100);

        var status = 200;
        string body;
        try
        {
            switch (path)
            {
                case "/":
                case "/index":
                    body = "{\"case\":\"" + CaseName + "\",\"stack\":\"" + Stack
                         + "\",\"dotnet_specific\":\"SemaphoreSlim.WaitAsync(timeout) para el deadline + Lease IDisposable con using para la devolucion.\""
                         + ",\"routes\":[\"/health\",\"/pool-leaky?requests=24&pool=4&query_ms=25&fail_rate=25\",\"/pool-managed?requests=24&pool=4&query_ms=25&fail_rate=25\",\"/pool/state\",\"/diagnostics/summary\",\"/reset-lab\"]}";
                    break;
                case "/health":
                    body = $"{{\"status\":\"ok\",\"stack\":\"{Stack}\",\"case\":\"{CaseName}\"}}";
                    break;
                case "/pool-leaky":
                    body = await RunLoad("leaky", requests, poolSize, queryMs, failRate).ConfigureAwait(false);
                    break;
                case "/pool-managed":
                    body = await RunLoad("managed", requests, poolSize, queryMs, failRate).ConfigureAwait(false);
                    break;
                case "/pool/state":
                    body = PoolStateJson();
                    break;
                case "/diagnostics/summary":
                    body = DiagnosticsJson();
                    break;
                case "/reset-lab":
                    _pool = new Pool(poolSize);
                    _metrics = FreshMetrics();
                    body = "{\"status\":\"reset\",\"message\":\"Pool reconstruido y metricas reiniciadas.\"}";
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
