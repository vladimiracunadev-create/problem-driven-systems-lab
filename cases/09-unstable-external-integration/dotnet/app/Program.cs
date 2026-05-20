using System;
using System.Collections.Concurrent;
using System.Collections.Generic;
using System.Globalization;
using System.Net;
using System.Text;
using System.Threading;
using System.Threading.Tasks;

// Caso 09 — Integracion externa inestable — stack .NET 8.
//
// Primitivas .NET distintivas:
//   - SemaphoreSlim como budget de cuota (espejo del Semaphore Java).
//   - ConcurrentDictionary como snapshot cache thread-safe.
//   - Volatile string ref + lock para estado del breaker (closed/open/half_open).

internal static class Program
{
    private const string CaseName = "09 - Integracion externa inestable";
    private static readonly string Stack = Environment.GetEnvironmentVariable("APP_STACK") ?? ".NET 8";
    private const int BudgetPerWindow = 5;

    private static readonly ConcurrentDictionary<string, string> SnapshotCache = new();
    private static readonly SemaphoreSlim ProviderBudget = new(BudgetPerWindow, BudgetPerWindow);
    private static string _breaker = "closed";
    private static readonly object BreakerLock = new();

    private static long _legacyCalls;
    private static long _legacyFailures;
    private static long _hardenedCalls;
    private static long _hardenedFromCache;
    private static long _hardenedBudgetDenied;

    static Program()
    {
        SnapshotCache["widget-A"] = "{\"name\":\"Widget A\",\"price\":42.0,\"snapshot_at\":\"2026-05-01T00:00:00Z\"}";
        SnapshotCache["widget-B"] = "{\"name\":\"Widget B\",\"price\":13.5,\"snapshot_at\":\"2026-05-01T00:00:00Z\"}";
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
        Console.WriteLine($"[case09-dotnet] listening on {port}");

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
                           "\",\"routes\":[\"/health\",\"/catalog-legacy?sku=widget-A&scenario=drift\",\"/catalog-hardened?sku=widget-A&scenario=drift\",\"/sync-events\",\"/diagnostics/summary\",\"/reset-lab\"]}";
                    break;
                case "/health":
                    body = $"{{\"status\":\"ok\",\"stack\":\"{Stack}\",\"case\":\"{CaseName}\"}}";
                    break;
                case "/catalog-legacy":
                    body = CatalogLegacy(q.GetValueOrDefault("sku", "widget-A"), q.GetValueOrDefault("scenario", "ok"));
                    Interlocked.Increment(ref _legacyCalls);
                    break;
                case "/catalog-hardened":
                    body = CatalogHardened(q.GetValueOrDefault("sku", "widget-A"), q.GetValueOrDefault("scenario", "ok"));
                    Interlocked.Increment(ref _hardenedCalls);
                    break;
                case "/sync-events":
                    body = StateJson();
                    break;
                case "/diagnostics/summary":
                    body = DiagnosticsJson();
                    break;
                case "/reset-lab":
                    Interlocked.Exchange(ref _legacyCalls, 0);
                    Interlocked.Exchange(ref _legacyFailures, 0);
                    Interlocked.Exchange(ref _hardenedCalls, 0);
                    Interlocked.Exchange(ref _hardenedFromCache, 0);
                    Interlocked.Exchange(ref _hardenedBudgetDenied, 0);
                    // drain + refill
                    while (ProviderBudget.CurrentCount > 0) ProviderBudget.Wait(0);
                    ProviderBudget.Release(BudgetPerWindow);
                    lock (BreakerLock) _breaker = "closed";
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

    private static string CatalogLegacy(string sku, string scenario)
    {
        bool drift = scenario == "drift" || scenario == "rate_limit" || scenario == "maintenance";
        if (drift)
        {
            Interlocked.Increment(ref _legacyFailures);
            return "{\"variant\":\"legacy\",\"sku\":\"" + sku + "\",\"status\":\"failed\"," +
                   "\"scenario\":\"" + scenario + "\"," +
                   "\"note\":\"provider devuelve drift de esquema / rate limit / maintenance; sin cache, falla.\"}";
        }
        return "{\"variant\":\"legacy\",\"sku\":\"" + sku + "\",\"status\":\"ok\"," +
               "\"data\":{\"name\":\"" + sku + " Live\",\"price\":42.0}," +
               "\"note\":\"hit directo al provider, sin budget ni cache.\"}";
    }

    private static string CatalogHardened(string sku, string scenario)
    {
        bool drift = scenario == "drift" || scenario == "rate_limit" || scenario == "maintenance";

        if (!ProviderBudget.Wait(0))
        {
            Interlocked.Increment(ref _hardenedBudgetDenied);
            return FromSnapshot(sku, "budget_exhausted", "budget de cuota agotado; sirviendo snapshot cacheado.");
        }
        if (drift)
        {
            lock (BreakerLock) _breaker = "open";
            return FromSnapshot(sku, "provider_failing", "provider con drift/rate_limit/maintenance; snapshot cacheado.");
        }
        var fresh = "{\"name\":\"" + sku + " Live\",\"price\":42.0,\"snapshot_at\":\"" + DateTime.UtcNow.ToString("o") + "\"}";
        SnapshotCache[sku] = fresh;
        string br;
        lock (BreakerLock) { _breaker = "closed"; br = _breaker; }
        return "{\"variant\":\"hardened\",\"sku\":\"" + sku + "\",\"status\":\"ok\"," +
               "\"data\":" + fresh + ",\"served_from\":\"provider\"," +
               "\"budget_remaining\":" + ProviderBudget.CurrentCount +
               ",\"breaker\":\"" + br + "\"}";
    }

    private static string FromSnapshot(string sku, string reason, string note)
    {
        Interlocked.Increment(ref _hardenedFromCache);
        var cached = SnapshotCache.TryGetValue(sku, out var c) ? c : "null";
        string br;
        lock (BreakerLock) br = _breaker;
        return "{\"variant\":\"hardened\",\"sku\":\"" + sku + "\",\"status\":\"served_from_cache\"," +
               "\"reason\":\"" + reason + "\"," +
               "\"data\":" + cached + "," +
               "\"served_from\":\"snapshot_cache\"," +
               "\"budget_remaining\":" + ProviderBudget.CurrentCount +
               ",\"breaker\":\"" + br + "\"," +
               "\"note\":\"" + note + "\"}";
    }

    private static string StateJson()
    {
        string br;
        lock (BreakerLock) br = _breaker;
        return "{\"breaker\":\"" + br + "\"," +
               "\"budget_remaining\":" + ProviderBudget.CurrentCount +
               ",\"budget_max\":" + BudgetPerWindow +
               ",\"snapshot_cache_size\":" + SnapshotCache.Count + "}";
    }

    private static string DiagnosticsJson() =>
        "{\"stack\":\"" + Stack + "\",\"case\":\"" + CaseName + "\"," +
        "\"legacy\":{\"calls\":" + Interlocked.Read(ref _legacyCalls) +
        ",\"failures\":" + Interlocked.Read(ref _legacyFailures) + "}," +
        "\"hardened\":{\"calls\":" + Interlocked.Read(ref _hardenedCalls) +
        ",\"served_from_cache\":" + Interlocked.Read(ref _hardenedFromCache) +
        ",\"budget_denied\":" + Interlocked.Read(ref _hardenedBudgetDenied) + "}," +
        "\"state\":" + StateJson() + "}";

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
