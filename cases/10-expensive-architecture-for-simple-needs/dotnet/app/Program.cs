using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Net;
using System.Text;
using System.Threading;
using System.Threading.Tasks;

// Caso 10 — Arquitectura cara para algo simple — stack .NET 8.
//
// Primitivas .NET distintivas:
//   - Stopwatch para medir elapsed real por request.
//   - Dictionary<string,long> directo para right-sized lookup O(1).
//   - StringBuilder loops costosos en featureComplex (CPU real).

internal static class Program
{
    private const string CaseName = "10 - Arquitectura cara para algo simple";
    private static readonly string Stack = Environment.GetEnvironmentVariable("APP_STACK") ?? ".NET 8";

    private static readonly Dictionary<string, long> DirectStore = new();
    private static readonly List<string> Decisions = new();
    private static readonly object DecisionsLock = new();

    private static long _complexCalls;
    private static long _complexTimeouts;
    private static long _rightSizedCalls;

    static Program()
    {
        for (int i = 1; i <= 100; i++) DirectStore["feature-" + i] = i * 10L;
        Decisions.Add("ADR-001: empezar con monolito + Dictionary; revisitar si pasa de 10k QPS sostenido");
        Decisions.Add("ADR-002: posponer queue distribuida hasta que el modelo de datos lo requiera");
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
        Console.WriteLine($"[case10-dotnet] listening on {port}");

        var cts = new CancellationTokenSource();
        Console.CancelKeyPress += (_, e) => { e.Cancel = true; cts.Cancel(); try { listener.Stop(); } catch {} };

        while (!cts.IsCancellationRequested)
        {
            HttpListenerContext ctx;
            try { ctx = await listener.GetContextAsync(); }
            catch { break; }
            _ = Task.Run(() => Handle(ctx));
        }
    }

    private static void Handle(HttpListenerContext ctx)
    {
        var path = ctx.Request.Url?.AbsolutePath ?? "/";
        var q = QueryParams(ctx.Request.Url?.Query);
        int status = 200;
        string body;
        try
        {
            switch (path)
            {
                case "/":
                case "/index":
                    body = "{\"case\":\"" + CaseName + "\",\"stack\":\"" + Stack +
                           "\",\"routes\":[\"/health\",\"/feature-complex?key=feature-1&hops=8\",\"/feature-right-sized?key=feature-1\",\"/decisions\",\"/diagnostics/summary\",\"/reset-lab\"]}";
                    break;
                case "/health":
                    body = $"{{\"status\":\"ok\",\"stack\":\"{Stack}\",\"case\":\"{CaseName}\"}}";
                    break;
                case "/feature-complex":
                    body = FeatureComplex(q.GetValueOrDefault("key", "feature-1"),
                                          Bounded(q.GetValueOrDefault("hops", "8"), 1, 50));
                    Interlocked.Increment(ref _complexCalls);
                    break;
                case "/feature-right-sized":
                    body = FeatureRightSized(q.GetValueOrDefault("key", "feature-1"));
                    Interlocked.Increment(ref _rightSizedCalls);
                    break;
                case "/decisions":
                    body = DecisionsJson();
                    break;
                case "/diagnostics/summary":
                    body = DiagnosticsJson();
                    break;
                case "/reset-lab":
                    Interlocked.Exchange(ref _complexCalls, 0);
                    Interlocked.Exchange(ref _complexTimeouts, 0);
                    Interlocked.Exchange(ref _rightSizedCalls, 0);
                    body = "{\"status\":\"reset\"}";
                    break;
                default:
                    status = 404;
                    body = $"{{\"error\":\"not_found\",\"path\":\"{Escape(path)}\"}}";
                    break;
            }
        }
        catch (Exception e) { status = 500; body = $"{{\"error\":\"internal\",\"detail\":\"{Escape(e.Message)}\"}}"; }
        SendJson(ctx, status, body);
    }

    private static string FeatureComplex(string key, int hops)
    {
        var sw = Stopwatch.StartNew();
        var payload = new StringBuilder("{\"key\":\"").Append(key).Append("\",\"trace\":[");
        for (int h = 0; h < hops; h++)
        {
            var hop = new StringBuilder(2048);
            hop.Append("\"hop-").Append(h).Append('-');
            for (int i = 0; i < 200; i++) hop.Append((char)('A' + (i % 26)));
            hop.Append('"');
            payload.Append(hop);
            if (h < hops - 1) payload.Append(',');
        }
        payload.Append("],\"final_lookup\":");
        DirectStore.TryGetValue(key, out var value);
        bool hasValue = DirectStore.ContainsKey(key);
        payload.Append(hasValue ? value.ToString() : "null").Append('}');
        sw.Stop();
        long elapsedMs = (long)sw.Elapsed.TotalMilliseconds;

        if (hops > 20)
        {
            Interlocked.Increment(ref _complexTimeouts);
            return "{\"variant\":\"complex\",\"status\":\"internal_timeout\"," +
                   "\"hops\":" + hops + ",\"elapsed_ms\":" + elapsedMs +
                   ",\"services_touched\":" + hops + ",\"cost_usd_month_est\":" + (hops * 25) +
                   ",\"lead_time_days\":" + (hops * 2) +
                   ",\"note\":\"sobrearquitectura: muchos hops, timeout interno bajo seasonal_peak.\"}";
        }
        return "{\"variant\":\"complex\",\"key\":\"" + key + "\"," +
               "\"hops\":" + hops + ",\"elapsed_ms\":" + elapsedMs +
               ",\"services_touched\":" + hops + ",\"cost_usd_month_est\":" + (hops * 25) +
               ",\"lead_time_days\":" + (hops * 2) +
               ",\"value\":" + (hasValue ? value.ToString() : "null") +
               ",\"payload_bytes\":" + payload.Length +
               ",\"note\":\"N hops con serializacion en cada uno; CPU real medido.\"}";
    }

    private static string FeatureRightSized(string key)
    {
        var sw = Stopwatch.StartNew();
        DirectStore.TryGetValue(key, out var value);
        bool hasValue = DirectStore.ContainsKey(key);
        sw.Stop();
        long elapsedMs = (long)sw.Elapsed.TotalMilliseconds;
        return "{\"variant\":\"right_sized\",\"key\":\"" + key + "\"," +
               "\"elapsed_ms\":" + elapsedMs +
               ",\"services_touched\":1,\"cost_usd_month_est\":3" +
               ",\"lead_time_days\":1" +
               ",\"value\":" + (hasValue ? value.ToString() : "null") +
               ",\"note\":\"Dictionary O(1); proporcional al problema real.\"}";
    }

    private static string DecisionsJson()
    {
        var sb = new StringBuilder(1024);
        sb.Append("{\"decisions\":[");
        lock (DecisionsLock)
        {
            bool first = true;
            foreach (var d in Decisions)
            {
                if (!first) sb.Append(',');
                sb.Append('"').Append(Escape(d)).Append('"');
                first = false;
            }
        }
        sb.Append("]}");
        return sb.ToString();
    }

    private static string DiagnosticsJson() =>
        "{\"stack\":\"" + Stack + "\",\"case\":\"" + CaseName + "\"," +
        "\"complex\":{\"calls\":" + Interlocked.Read(ref _complexCalls) +
        ",\"timeouts\":" + Interlocked.Read(ref _complexTimeouts) +
        ",\"behavior\":\"N hops, serializacion costosa por hop, CPU/lead time altos\"}," +
        "\"right_sized\":{\"calls\":" + Interlocked.Read(ref _rightSizedCalls) +
        ",\"behavior\":\"Dictionary O(1), cost minimo, lead time minimo\"}," +
        "\"decisions\":" + DecisionsJson() + "}";

    private static int Bounded(string raw, int min, int max)
    {
        if (!int.TryParse(raw, out var n)) return min;
        return Math.Max(min, Math.Min(n, max));
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
