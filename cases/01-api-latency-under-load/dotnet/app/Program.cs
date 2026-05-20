using System;
using System.Collections.Concurrent;
using System.Collections.Generic;
using System.Diagnostics;
using System.Linq;
using System.Net;
using System.Text;
using System.Threading;
using System.Threading.Tasks;

// Caso 01 — API lenta bajo carga (stack .NET 8).
//
// Espejo del Main.java equivalente. Mismos endpoints, misma semantica, JSON con
// el mismo shape. Primitivas .NET idiomáticas:
//   - ConcurrentDictionary como cache de summary (lectores no se bloquean con worker)
//   - Interlocked + lock(samples) para metricas
//   - BackgroundService-style: Task.Run periódico con CancellationToken

internal static class Program
{
    private const string CaseName = "01 - API lenta bajo carga";
    private static readonly string Stack = Environment.GetEnvironmentVariable("APP_STACK") ?? ".NET 8";
    private const int SummaryRefreshSeconds = 5;
    private const int MaxSamples = 3000;
    private const int MaxJobRuns = 30;

    private sealed record Customer(int Id, string Name, string Tier);
    private sealed record Order(int Id, int CustomerId, string Region, double Amount);
    private sealed class CustomerSummary { public int OrderCount; public double TotalAmount; }

    private static readonly List<Customer> Customers = new();
    private static readonly List<Order> Orders = new();
    private static readonly ConcurrentDictionary<int, CustomerSummary> SummaryCache = new();
    private static readonly Dictionary<int, Customer> CustomerById = new();
    private static readonly Dictionary<string, List<Order>> OrdersByRegionPrefix = new();

    private static readonly Metrics LegacyMetrics = new();
    private static readonly Metrics OptimizedMetrics = new();
    private static readonly WorkerState Worker = new();
    private static readonly LinkedList<JobRun> JobRuns = new();
    private static readonly object JobRunsLock = new();

    private static async Task Main()
    {
        SeedData();
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
        Console.WriteLine($"[case01-dotnet] listening on {port}");

        var cts = new CancellationTokenSource();
        Console.CancelKeyPress += (_, e) => { e.Cancel = true; cts.Cancel(); try { listener.Stop(); } catch {} };

        _ = Task.Run(() => WorkerLoopAsync(cts.Token));

        while (!cts.IsCancellationRequested)
        {
            HttpListenerContext ctx;
            try { ctx = await listener.GetContextAsync(); }
            catch { break; }
            _ = Task.Run(() => Handle(ctx));
        }
    }

    private static async Task WorkerLoopAsync(CancellationToken ct)
    {
        await Task.Delay(1000, ct).ContinueWith(_ => { });
        while (!ct.IsCancellationRequested)
        {
            RefreshSummary();
            try { await Task.Delay(SummaryRefreshSeconds * 1000, ct); } catch { break; }
        }
    }

    private static void RefreshSummary()
    {
        var sw = Stopwatch.StartNew();
        var next = new Dictionary<int, CustomerSummary>();
        foreach (var o in Orders)
        {
            if (!next.TryGetValue(o.CustomerId, out var s)) { s = new CustomerSummary(); next[o.CustomerId] = s; }
            s.OrderCount++;
            s.TotalAmount = Round2(s.TotalAmount + o.Amount);
        }
        foreach (var kv in next) SummaryCache[kv.Key] = kv.Value;
        sw.Stop();
        var durMs = (long)sw.Elapsed.TotalMilliseconds;
        Worker.Update("ok", durMs, $"refreshed {next.Count} customer summaries");
        lock (JobRunsLock)
        {
            JobRuns.AddFirst(new JobRun(DateTime.UtcNow.ToString("o"), "ok", durMs, next.Count));
            while (JobRuns.Count > MaxJobRuns) JobRuns.RemoveLast();
        }
    }

    private static void Handle(HttpListenerContext ctx)
    {
        var sw = Stopwatch.StartNew();
        var path = ctx.Request.Url?.AbsolutePath ?? "/";
        var q = QueryParams(ctx.Request.Url?.Query);
        int status = 200;
        string body;
        Metrics? tracked = null;
        try
        {
            switch (path)
            {
                case "/":
                case "/index":
                    body = IndexJson(); break;
                case "/health":
                    body = $"{{\"status\":\"ok\",\"stack\":\"{Stack}\",\"case\":\"{CaseName}\"}}"; break;
                case "/report-legacy":
                    body = ReportLegacy(Bounded(q.GetValueOrDefault("limit", "20"), 1, 200));
                    tracked = LegacyMetrics; break;
                case "/report-optimized":
                    body = ReportOptimized(Bounded(q.GetValueOrDefault("limit", "20"), 1, 200));
                    tracked = OptimizedMetrics; break;
                case "/batch/status":
                    body = Worker.ToJson(); break;
                case "/job-runs":
                    body = JobRunsJson(); break;
                case "/diagnostics/summary":
                    body = DiagnosticsJson(); break;
                case "/metrics":
                    body = MetricsJson(); break;
                case "/reset-lab":
                    LegacyMetrics.Reset(); OptimizedMetrics.Reset();
                    lock (JobRunsLock) JobRuns.Clear();
                    body = $"{{\"status\":\"reset\",\"stack\":\"{Stack}\"}}"; break;
                default:
                    status = 404; body = $"{{\"error\":\"not_found\",\"path\":\"{Escape(path)}\"}}"; break;
            }
        }
        catch (Exception e) { status = 500; body = $"{{\"error\":\"internal\",\"detail\":\"{Escape(e.Message)}\"}}"; }

        sw.Stop();
        if (tracked != null) tracked.Record(Round2(sw.Elapsed.TotalMilliseconds));
        SendJson(ctx, status, body);
    }

    private static string IndexJson() =>
        "{" +
        "\"lab\":\"Problem-Driven Systems Lab\"," +
        $"\"case\":\"{CaseName}\"," +
        $"\"stack\":\"{Stack}\"," +
        "\"native_primitives\":[\"ConcurrentDictionary (summary cache)\",\"Interlocked (counters)\",\"Task.Delay loop (worker)\"]," +
        "\"routes\":{" +
        "\"/health\":\"liveness check\"," +
        "\"/report-legacy?limit=20\":\"N+1 + filtro no sargable\"," +
        "\"/report-optimized?limit=20\":\"batch en memoria + lectura O(1) de summary cache\"," +
        "\"/batch/status\":\"estado del worker\"," +
        "\"/job-runs\":\"historial de corridas del worker\"," +
        "\"/diagnostics/summary\":\"contraste legacy vs optimized\"," +
        "\"/metrics\":\"avg/p95/p99 por ruta\"," +
        "\"/reset-lab\":\"reinicia contadores e historico\"}}";

    private static string ReportLegacy(int limit)
    {
        long dbHits = 0;
        var sw = Stopwatch.StartNew();
        var scanned = new List<Order>();
        foreach (var o in Orders)
            if (LowerRegion(o.Region).StartsWith("n")) scanned.Add(o);
        dbHits++;
        int take = Math.Min(limit, scanned.Count);
        var sb = new StringBuilder(8192);
        sb.Append("{\"variant\":\"legacy\",\"rows\":[");
        for (int i = 0; i < take; i++)
        {
            var o = scanned[i];
            var c = LookupCustomerOneByOne(o.CustomerId);
            dbHits++;
            SleepMicros(1200);
            if (i > 0) sb.Append(',');
            sb.Append("{\"order_id\":").Append(o.Id)
              .Append(",\"customer\":\"").Append(Escape(c?.Name ?? "")).Append('"')
              .Append(",\"tier\":\"").Append(c?.Tier ?? "").Append('"')
              .Append(",\"region\":\"").Append(o.Region).Append('"')
              .Append(",\"amount\":").Append(F(o.Amount)).Append('}');
        }
        sw.Stop();
        sb.Append("],\"db_hits\":").Append(dbHits)
          .Append(",\"elapsed_ms\":").Append(F(Round2(sw.Elapsed.TotalMilliseconds)))
          .Append(",\"note\":\"N+1 + scan no sargable; cada hit cuesta tiempo real.\"}");
        return sb.ToString();
    }

    private static string ReportOptimized(int limit)
    {
        long dbHits = 0;
        var sw = Stopwatch.StartNew();
        if (!OrdersByRegionPrefix.TryGetValue("n", out var matched)) matched = new List<Order>();
        dbHits++;
        int take = Math.Min(limit, matched.Count);
        var batch = new Dictionary<int, Customer>();
        for (int i = 0; i < take; i++)
        {
            var cid = matched[i].CustomerId;
            if (!batch.ContainsKey(cid) && CustomerById.TryGetValue(cid, out var cc)) batch[cid] = cc;
        }
        dbHits++;
        SleepMicros(700);
        var sb = new StringBuilder(8192);
        sb.Append("{\"variant\":\"optimized\",\"rows\":[");
        for (int i = 0; i < take; i++)
        {
            var o = matched[i];
            batch.TryGetValue(o.CustomerId, out var c);
            SummaryCache.TryGetValue(o.CustomerId, out var s);
            if (i > 0) sb.Append(',');
            sb.Append("{\"order_id\":").Append(o.Id)
              .Append(",\"customer\":\"").Append(Escape(c?.Name ?? "")).Append('"')
              .Append(",\"tier\":\"").Append(c?.Tier ?? "").Append('"')
              .Append(",\"region\":\"").Append(o.Region).Append('"')
              .Append(",\"amount\":").Append(F(o.Amount))
              .Append(",\"lifetime_orders\":").Append(s?.OrderCount ?? 0)
              .Append(",\"lifetime_amount\":").Append(F(s?.TotalAmount ?? 0.0))
              .Append('}');
        }
        sw.Stop();
        sb.Append("],\"db_hits\":").Append(dbHits)
          .Append(",\"elapsed_ms\":").Append(F(Round2(sw.Elapsed.TotalMilliseconds)))
          .Append(",\"summary_cache_size\":").Append(SummaryCache.Count)
          .Append(",\"note\":\"1 lookup indexado + 1 batch + O(1) sobre summary cache mantenida por worker.\"}");
        return sb.ToString();
    }

    private static string DiagnosticsJson() =>
        "{" +
        $"\"stack\":\"{Stack}\"," +
        $"\"case\":\"{CaseName}\"," +
        $"\"legacy\":{LegacyMetrics.ToJson("legacy")}," +
        $"\"optimized\":{OptimizedMetrics.ToJson("optimized")}," +
        $"\"summary_cache_size\":{SummaryCache.Count}," +
        $"\"worker\":{Worker.ToJson()}}}";

    private static string MetricsJson() =>
        $"{{\"legacy\":{LegacyMetrics.ToJson("legacy")},\"optimized\":{OptimizedMetrics.ToJson("optimized")}}}";

    private static string JobRunsJson()
    {
        var sb = new StringBuilder(1024);
        sb.Append("{\"runs\":[");
        lock (JobRunsLock)
        {
            bool first = true;
            foreach (var r in JobRuns)
            {
                if (!first) sb.Append(',');
                sb.Append(r.ToJson());
                first = false;
            }
        }
        sb.Append("],\"max_runs_kept\":").Append(MaxJobRuns).Append('}');
        return sb.ToString();
    }

    private static void SeedData()
    {
        long seed = 102030L;
        string[] regions = { "north", "south", "east", "west" };
        string[] tiers = { "bronze", "silver", "gold" };
        for (int i = 1; i <= 1600; i++)
        {
            seed = (seed * 9301 + 49297) % 233280;
            var tier = tiers[(int)(seed % tiers.Length)];
            var c = new Customer(i, $"Customer {i}", tier);
            Customers.Add(c);
            CustomerById[i] = c;
        }
        for (int i = 1; i <= 4800; i++)
        {
            seed = (seed * 9301 + 49297) % 233280;
            int cid = 1 + (int)(seed % Customers.Count);
            var region = regions[(int)((seed / 7) % regions.Length)];
            double amount = Round2(20.0 + (seed % 1000));
            var o = new Order(i, cid, region, amount);
            Orders.Add(o);
            var key = region.Substring(0, 1);
            if (!OrdersByRegionPrefix.TryGetValue(key, out var list)) { list = new List<Order>(); OrdersByRegionPrefix[key] = list; }
            list.Add(o);
        }
    }

    private static Customer? LookupCustomerOneByOne(int id)
    {
        foreach (var c in Customers) if (c.Id == id) return c;
        return null;
    }

    private static string LowerRegion(string? r) => r == null ? "" : r.ToLowerInvariant();

    private sealed class WorkerState
    {
        private string _status = "init";
        private long _lastDurationMs = -1;
        private string _lastMessage = "worker not started yet";
        private string? _lastHeartbeat;
        private readonly object _lock = new();
        public void Update(string s, long durMs, string msg)
        {
            lock (_lock)
            {
                _status = s; _lastDurationMs = durMs; _lastMessage = msg;
                _lastHeartbeat = DateTime.UtcNow.ToString("o");
            }
        }
        public string ToJson()
        {
            lock (_lock)
            {
                return "{" +
                    "\"worker_name\":\"report-refresh-dotnet\"," +
                    $"\"last_status\":\"{Escape(_status)}\"," +
                    $"\"last_duration_ms\":{_lastDurationMs}," +
                    $"\"last_message\":\"{Escape(_lastMessage)}\"," +
                    $"\"last_heartbeat\":\"{Escape(_lastHeartbeat)}\"}}";
            }
        }
    }

    private sealed record JobRun(string At, string Status, long DurationMs, int CustomersRefreshed)
    {
        public string ToJson() =>
            $"{{\"at\":\"{Escape(At)}\",\"status\":\"{Escape(Status)}\",\"duration_ms\":{DurationMs},\"customers_refreshed\":{CustomersRefreshed}}}";
    }

    private sealed class Metrics
    {
        private long _requests;
        private readonly List<double> _samples = new();
        private readonly object _lock = new();
        public void Record(double elapsedMs)
        {
            Interlocked.Increment(ref _requests);
            lock (_lock)
            {
                _samples.Add(elapsedMs);
                while (_samples.Count > MaxSamples) _samples.RemoveAt(0);
            }
        }
        public void Reset()
        {
            Interlocked.Exchange(ref _requests, 0);
            lock (_lock) _samples.Clear();
        }
        public string ToJson(string label)
        {
            List<double> snap;
            long req = Interlocked.Read(ref _requests);
            lock (_lock) snap = new List<double>(_samples);
            return $"{{\"label\":\"{label}\"," +
                   $"\"requests\":{req}," +
                   $"\"sample_count\":{snap.Count}," +
                   $"\"avg_ms\":{F(Avg(snap))}," +
                   $"\"p95_ms\":{F(Percentile(snap, 95))}," +
                   $"\"p99_ms\":{F(Percentile(snap, 99))}}}";
        }
    }

    private static double Avg(List<double> v) { if (v.Count == 0) return 0.0; double s = 0; foreach (var x in v) s += x; return Round2(s / v.Count); }
    private static double Percentile(List<double> v, int percent)
    {
        if (v.Count == 0) return 0.0;
        var ordered = v.OrderBy(x => x).ToList();
        int idx = Math.Max(0, Math.Min(ordered.Count - 1, (int)Math.Ceiling((percent / 100.0) * ordered.Count) - 1));
        return Round2(ordered[idx]);
    }
    private static double Round2(double v) => Math.Round(v, 2);
    private static string F(double v) => v.ToString("0.##", System.Globalization.CultureInfo.InvariantCulture);

    private static int Bounded(string raw, int min, int max)
    {
        if (!int.TryParse(raw, out var n)) return min;
        return Math.Max(min, Math.Min(n, max));
    }
    private static void SleepMicros(int micros)
    {
        long ticks = micros * (Stopwatch.Frequency / 1_000_000L);
        long start = Stopwatch.GetTimestamp();
        while (Stopwatch.GetTimestamp() - start < ticks) Thread.SpinWait(50);
    }
    private static string Escape(string? v) => v == null ? "" : v.Replace("\\", "\\\\").Replace("\"", "\\\"");
    private static Dictionary<string, string> QueryParams(string? raw)
    {
        var d = new Dictionary<string, string>();
        if (string.IsNullOrEmpty(raw)) return d;
        if (raw.StartsWith("?")) raw = raw.Substring(1);
        foreach (var pair in raw.Split('&', StringSplitOptions.RemoveEmptyEntries))
        {
            var parts = pair.Split('=', 2);
            var k = WebUtility.UrlDecode(parts[0]) ?? "";
            var v = parts.Length > 1 ? (WebUtility.UrlDecode(parts[1]) ?? "") : "";
            d[k] = v;
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
