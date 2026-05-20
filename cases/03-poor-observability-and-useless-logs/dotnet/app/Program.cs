using System;
using System.Collections.Generic;
using System.Globalization;
using System.Net;
using System.Text;
using System.Threading;
using System.Threading.Tasks;

// Caso 03 — Observabilidad deficiente y logs inutiles (stack .NET 8).
// Espejo funcional del Main.java equivalente.
// Primitiva distintiva: AsyncLocal<RequestContext> como espejo del ThreadLocal Java.

internal static class Program
{
    private const string CaseName = "03 - Observabilidad deficiente y logs inutiles";
    private static readonly string Stack = Environment.GetEnvironmentVariable("APP_STACK") ?? ".NET 8";
    private const int MaxLogEntries = 200;

    private sealed record RequestContext(string CorrId, string Route, string StartedAt);
    private static readonly AsyncLocal<RequestContext?> Ctx = new();

    private static readonly LinkedList<string> RecentLogs = new();
    private static readonly object LogsLock = new();

    private static long _legacyErrors, _observableErrors, _legacyRequests, _observableRequests;

    private static async Task Main()
    {
        var port = int.TryParse(Environment.GetEnvironmentVariable("PORT"), out var p) ? p : 8080;
        var listener = new HttpListener();
        listener.Prefixes.Add($"http://+:{port}/");
        try { listener.Start(); }
        catch (HttpListenerException) { listener = new HttpListener(); listener.Prefixes.Add($"http://*:{port}/"); listener.Start(); }
        Console.WriteLine($"[case03-dotnet] listening on {port}");

        while (true)
        {
            HttpListenerContext ctx;
            try { ctx = await listener.GetContextAsync(); } catch { break; }
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
                    body = $"{{\"case\":\"{CaseName}\",\"stack\":\"{Stack}\",\"routes\":[\"/health\",\"/checkout-legacy?total=100\",\"/checkout-observable?total=100\",\"/logs\",\"/metrics\",\"/diagnostics/summary\",\"/reset-lab\"]}}"; break;
                case "/health":
                    body = $"{{\"status\":\"ok\",\"stack\":\"{Stack}\",\"case\":\"{CaseName}\"}}"; break;
                case "/checkout-legacy":
                    body = CheckoutLegacy(q.GetValueOrDefault("total", "100"));
                    Interlocked.Increment(ref _legacyRequests); break;
                case "/checkout-observable":
                    body = CheckoutObservable(q.GetValueOrDefault("total", "100"));
                    Interlocked.Increment(ref _observableRequests); break;
                case "/logs":
                    body = LogsJson(); break;
                case "/metrics":
                case "/diagnostics/summary":
                    body = DiagnosticsJson(); break;
                case "/reset-lab":
                    Interlocked.Exchange(ref _legacyErrors, 0); Interlocked.Exchange(ref _observableErrors, 0);
                    Interlocked.Exchange(ref _legacyRequests, 0); Interlocked.Exchange(ref _observableRequests, 0);
                    lock (LogsLock) RecentLogs.Clear();
                    body = "{\"status\":\"reset\"}"; break;
                default:
                    status = 404; body = $"{{\"error\":\"not_found\",\"path\":\"{Escape(path)}\"}}"; break;
            }
        }
        catch (Exception e) { status = 500; body = $"{{\"error\":\"internal\",\"detail\":\"{Escape(e.Message)}\"}}"; }
        SendJson(ctx, status, body);
    }

    private static string CheckoutLegacy(string totalRaw)
    {
        double total = ParseDoubleOr(totalRaw, 100.0);
        Console.WriteLine("[INFO] processing checkout");
        if (total > 500)
        {
            Console.WriteLine("[ERROR] checkout failed");
            Interlocked.Increment(ref _legacyErrors);
            return "{\"variant\":\"legacy\",\"status\":\"error\",\"note\":\"log dice 'checkout failed' sin id, sin total, sin causa.\"}";
        }
        Console.WriteLine("[INFO] checkout ok");
        return "{\"variant\":\"legacy\",\"status\":\"ok\",\"note\":\"log dice 'checkout ok' sin contexto util.\"}";
    }

    private static string CheckoutObservable(string totalRaw)
    {
        var corrId = Guid.NewGuid().ToString();
        Ctx.Value = new RequestContext(corrId, "checkout-observable", DateTime.UtcNow.ToString("o"));
        try
        {
            double total = ParseDoubleOr(totalRaw, 100.0);
            StructuredLog("info", "checkout_start", new Dictionary<string, string> { ["total"] = F(total) });
            if (total > 500)
            {
                StructuredLog("error", "checkout_failed", new Dictionary<string, string>
                {
                    ["total"] = F(total), ["reason"] = "exceeds_limit", ["limit"] = "500"
                });
                Interlocked.Increment(ref _observableErrors);
                return $"{{\"variant\":\"observable\",\"status\":\"error\",\"correlation_id\":\"{corrId}\",\"reason\":\"exceeds_limit\",\"limit\":500,\"total\":{F(total)}}}";
            }
            StructuredLog("info", "checkout_ok", new Dictionary<string, string> { ["total"] = F(total) });
            return $"{{\"variant\":\"observable\",\"status\":\"ok\",\"correlation_id\":\"{corrId}\",\"total\":{F(total)},\"note\":\"correlation_id propagado en logs estructurados.\"}}";
        }
        finally { Ctx.Value = null; }
    }

    private static void StructuredLog(string level, string evt, Dictionary<string, string> fields)
    {
        var c = Ctx.Value;
        var sb = new StringBuilder(256);
        sb.Append("{\"ts\":\"").Append(DateTime.UtcNow.ToString("o")).Append('"');
        sb.Append(",\"level\":\"").Append(level).Append('"');
        sb.Append(",\"event\":\"").Append(evt).Append('"');
        if (c != null)
        {
            sb.Append(",\"correlation_id\":\"").Append(c.CorrId).Append('"');
            sb.Append(",\"route\":\"").Append(c.Route).Append('"');
        }
        foreach (var kv in fields)
            sb.Append(",\"").Append(kv.Key).Append("\":\"").Append(Escape(kv.Value)).Append('"');
        sb.Append('}');
        var line = sb.ToString();
        lock (LogsLock)
        {
            RecentLogs.AddFirst(line);
            while (RecentLogs.Count > MaxLogEntries) RecentLogs.RemoveLast();
        }
    }

    private static string LogsJson()
    {
        var sb = new StringBuilder(1024);
        sb.Append("{\"entries\":[");
        lock (LogsLock)
        {
            bool first = true;
            foreach (var l in RecentLogs)
            {
                if (!first) sb.Append(',');
                sb.Append(l);
                first = false;
            }
        }
        sb.Append("],\"max_kept\":").Append(MaxLogEntries).Append('}');
        return sb.ToString();
    }

    private static string DiagnosticsJson() =>
        $"{{\"stack\":\"{Stack}\",\"case\":\"{CaseName}\"," +
        $"\"legacy\":{{\"requests\":{Interlocked.Read(ref _legacyRequests)},\"errors\":{Interlocked.Read(ref _legacyErrors)},\"observability\":\"Console.WriteLine sin correlation, sin contexto\"}}," +
        $"\"observable\":{{\"requests\":{Interlocked.Read(ref _observableRequests)},\"errors\":{Interlocked.Read(ref _observableErrors)},\"observability\":\"log estructurado JSON con correlation_id, /logs endpoint\"}}}}";

    private static double ParseDoubleOr(string raw, double d) =>
        double.TryParse(raw, NumberStyles.Any, CultureInfo.InvariantCulture, out var v) ? v : d;
    private static string F(double v) => v.ToString("0.##", CultureInfo.InvariantCulture);
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
