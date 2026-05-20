using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Globalization;
using System.Linq;
using System.Net;
using System.Text;
using System.Threading;
using System.Threading.Tasks;

// Caso 02 — N+1 queries y cuellos de botella DB (stack .NET 8).
// Espejo funcional del Main.java equivalente.

internal static class Program
{
    private const string CaseName = "02 - N+1 queries y cuellos de botella DB";
    private static readonly string Stack = Environment.GetEnvironmentVariable("APP_STACK") ?? ".NET 8";
    private const int MaxSamples = 3000;

    private sealed record Order(int Id, int CustomerId);
    private sealed record Item(string Sku, int Qty);

    private static readonly List<Order> Orders = new();
    private static readonly Dictionary<int, List<Item>> ItemsByOrderId = new();
    private static readonly List<Item> AllItems = new();

    private static readonly Metrics LegacyMetrics = new();
    private static readonly Metrics OptimizedMetrics = new();

    private static async Task Main()
    {
        SeedData();
        var port = int.TryParse(Environment.GetEnvironmentVariable("PORT"), out var p) ? p : 8080;
        var listener = new HttpListener();
        listener.Prefixes.Add($"http://+:{port}/");
        try { listener.Start(); }
        catch (HttpListenerException) { listener = new HttpListener(); listener.Prefixes.Add($"http://*:{port}/"); listener.Start(); }
        Console.WriteLine($"[case02-dotnet] listening on {port}");

        while (true)
        {
            HttpListenerContext ctx;
            try { ctx = await listener.GetContextAsync(); } catch { break; }
            _ = Task.Run(() => Handle(ctx));
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
                    body = $"{{\"case\":\"{CaseName}\",\"stack\":\"{Stack}\",\"routes\":[\"/health\",\"/orders-legacy?limit=20\",\"/orders-optimized?limit=20\",\"/diagnostics/summary\",\"/metrics\",\"/reset-lab\"]}}"; break;
                case "/health":
                    body = $"{{\"status\":\"ok\",\"stack\":\"{Stack}\",\"case\":\"{CaseName}\"}}"; break;
                case "/orders-legacy":
                    body = OrdersLegacy(Bounded(q.GetValueOrDefault("limit", "20"), 1, 200));
                    tracked = LegacyMetrics; break;
                case "/orders-optimized":
                    body = OrdersOptimized(Bounded(q.GetValueOrDefault("limit", "20"), 1, 200));
                    tracked = OptimizedMetrics; break;
                case "/diagnostics/summary":
                    body = $"{{\"stack\":\"{Stack}\",\"case\":\"{CaseName}\"," +
                           $"\"orders_total\":{Orders.Count},\"items_total\":{AllItems.Count}," +
                           $"\"avg_items_per_order\":{F(Round2(AllItems.Count / (double)Orders.Count))}," +
                           $"\"legacy\":{LegacyMetrics.ToJson("legacy")}," +
                           $"\"optimized\":{OptimizedMetrics.ToJson("optimized")}}}"; break;
                case "/metrics":
                    body = $"{{\"legacy\":{LegacyMetrics.ToJson("legacy")},\"optimized\":{OptimizedMetrics.ToJson("optimized")}}}"; break;
                case "/reset-lab":
                    LegacyMetrics.Reset(); OptimizedMetrics.Reset();
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

    private static string OrdersLegacy(int limit)
    {
        var sw = Stopwatch.StartNew();
        long dbHits = 1;
        int take = Math.Min(limit, Orders.Count);
        var sb = new StringBuilder(8192);
        sb.Append("{\"variant\":\"legacy\",\"rows\":[");
        for (int i = 0; i < take; i++)
        {
            var o = Orders[i];
            var items = ItemsByOrderId.TryGetValue(o.Id, out var lst) ? lst : new List<Item>();
            dbHits++;
            SleepMicros(900);
            if (i > 0) sb.Append(',');
            sb.Append("{\"order_id\":").Append(o.Id)
              .Append(",\"customer_id\":").Append(o.CustomerId)
              .Append(",\"item_count\":").Append(items.Count)
              .Append(",\"items\":[");
            for (int j = 0; j < items.Count; j++)
            {
                if (j > 0) sb.Append(',');
                sb.Append("{\"sku\":\"").Append(items[j].Sku).Append("\",\"qty\":").Append(items[j].Qty).Append('}');
            }
            sb.Append("]}");
        }
        sw.Stop();
        sb.Append("],\"db_hits\":").Append(dbHits)
          .Append(",\"elapsed_ms\":").Append(F(Round2(sw.Elapsed.TotalMilliseconds)))
          .Append(",\"note\":\"1 query orders + N queries items dentro de bucle.\"}");
        return sb.ToString();
    }

    private static string OrdersOptimized(int limit)
    {
        var sw = Stopwatch.StartNew();
        long dbHits = 1;
        int take = Math.Min(limit, Orders.Count);
        var ids = new List<int>();
        for (int i = 0; i < take; i++) ids.Add(Orders[i].Id);
        var batch = new Dictionary<int, List<Item>>();
        foreach (var id in ids) batch[id] = ItemsByOrderId.TryGetValue(id, out var l) ? l : new List<Item>();
        dbHits++;
        SleepMicros(700);
        var sb = new StringBuilder(8192);
        sb.Append("{\"variant\":\"optimized\",\"rows\":[");
        for (int i = 0; i < take; i++)
        {
            var o = Orders[i];
            var items = batch[o.Id];
            if (i > 0) sb.Append(',');
            sb.Append("{\"order_id\":").Append(o.Id)
              .Append(",\"customer_id\":").Append(o.CustomerId)
              .Append(",\"item_count\":").Append(items.Count)
              .Append(",\"items\":[");
            for (int j = 0; j < items.Count; j++)
            {
                if (j > 0) sb.Append(',');
                sb.Append("{\"sku\":\"").Append(items[j].Sku).Append("\",\"qty\":").Append(items[j].Qty).Append('}');
            }
            sb.Append("]}");
        }
        sw.Stop();
        sb.Append("],\"db_hits\":").Append(dbHits)
          .Append(",\"elapsed_ms\":").Append(F(Round2(sw.Elapsed.TotalMilliseconds)))
          .Append(",\"note\":\"1 query orders + 1 batch items (IN-style) + ensamblado en memoria.\"}");
        return sb.ToString();
    }

    private static void SeedData()
    {
        long seed = 270718L;
        for (int i = 1; i <= 600; i++)
        {
            seed = (seed * 9301 + 49297) % 233280;
            int cid = 1 + (int)(seed % 500);
            Orders.Add(new Order(i, cid));
            int n = 2 + (int)(seed % 5);
            var list = new List<Item>();
            for (int j = 1; j <= n; j++)
            {
                seed = (seed * 9301 + 49297) % 233280;
                var it = new Item("SKU-" + (1000 + (int)(seed % 9000)), 1 + (int)(seed % 8));
                list.Add(it); AllItems.Add(it);
            }
            ItemsByOrderId[i] = list;
        }
    }

    private sealed class Metrics
    {
        private long _requests;
        private readonly List<double> _samples = new();
        private readonly object _lock = new();
        public void Record(double elapsedMs)
        {
            Interlocked.Increment(ref _requests);
            lock (_lock) { _samples.Add(elapsedMs); while (_samples.Count > MaxSamples) _samples.RemoveAt(0); }
        }
        public void Reset() { Interlocked.Exchange(ref _requests, 0); lock (_lock) _samples.Clear(); }
        public string ToJson(string label)
        {
            List<double> snap; long req = Interlocked.Read(ref _requests);
            lock (_lock) snap = new List<double>(_samples);
            return $"{{\"label\":\"{label}\",\"requests\":{req},\"sample_count\":{snap.Count}," +
                   $"\"avg_ms\":{F(Avg(snap))},\"p95_ms\":{F(Percentile(snap, 95))},\"p99_ms\":{F(Percentile(snap, 99))}}}";
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
    private static string F(double v) => v.ToString("0.##", CultureInfo.InvariantCulture);
    private static int Bounded(string raw, int min, int max) { if (!int.TryParse(raw, out var n)) return min; return Math.Max(min, Math.Min(n, max)); }
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
        try { var bytes = Encoding.UTF8.GetBytes(body); ctx.Response.StatusCode = status; ctx.Response.ContentType = "application/json; charset=utf-8"; ctx.Response.ContentLength64 = bytes.Length; ctx.Response.OutputStream.Write(bytes, 0, bytes.Length); }
        catch { } finally { try { ctx.Response.OutputStream.Close(); } catch { } }
    }
}
