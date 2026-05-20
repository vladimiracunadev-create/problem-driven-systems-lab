using System;
using System.Collections.Concurrent;
using System.Collections.Generic;
using System.Globalization;
using System.Net;
using System.Text;
using System.Threading;
using System.Threading.Tasks;

// Caso 08 — Extraccion critica de modulo (cutover) — stack .NET 8.
//
// Primitivas .NET distintivas:
//   - Func<TOld,TNew> como proxy de compatibilidad (espejo del Function Java).
//   - List<Action<string>> protegido por lock como event bus (espejo de CopyOnWriteArrayList<Consumer>).
//   - record types para PriceRequestOld/New inmutables.

internal static class Program
{
    private const string CaseName = "08 - Extraccion critica de modulo";
    private static readonly string Stack = Environment.GetEnvironmentVariable("APP_STACK") ?? ".NET 8";
    private const int MaxEvents = 50;

    private sealed record PriceRequestOld(string Sku, double CostUsd);
    private sealed record PriceRequestNew(string Sku, double Price, string Currency);

    private static readonly Func<PriceRequestOld, PriceRequestNew> CompatProxy =
        old => new PriceRequestNew(old.Sku, old.CostUsd * 1.0, "USD");

    private static readonly ConcurrentDictionary<string, bool> CutoverProgress = new();
    private static readonly List<Action<string>> CutoverBus = new();
    private static readonly object BusLock = new();
    private static readonly LinkedList<string> Events = new();
    private static readonly object EventsLock = new();

    private static long _bigbangCalls;
    private static long _bigbangBroken;
    private static long _compatibleCalls;
    private static long _proxyHits;
    private static long _contractTestsPassed;

    static Program()
    {
        CutoverProgress["checkout"] = false;
        CutoverProgress["partners"] = false;
        CutoverProgress["backoffice"] = false;
        CutoverBus.Add(evt =>
        {
            lock (EventsLock)
            {
                Events.AddFirst(evt);
                while (Events.Count > MaxEvents) Events.RemoveLast();
            }
        });
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
        Console.WriteLine($"[case08-dotnet] listening on {port}");

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
                           "\",\"routes\":[\"/health\",\"/pricing-bigbang?consumer=checkout&sku=ABC&cost_usd=100\",\"/pricing-compatible?consumer=checkout&sku=ABC&cost_usd=100\",\"/flows\",\"/diagnostics/summary\",\"/reset-lab\"]}";
                    break;
                case "/health":
                    body = $"{{\"status\":\"ok\",\"stack\":\"{Stack}\",\"case\":\"{CaseName}\"}}";
                    break;
                case "/pricing-bigbang":
                    body = PricingBigbang(q.GetValueOrDefault("consumer", "checkout"),
                                          q.GetValueOrDefault("sku", "ABC"),
                                          ParseDoubleOr(q.GetValueOrDefault("cost_usd", "100"), 100.0));
                    Interlocked.Increment(ref _bigbangCalls);
                    break;
                case "/pricing-compatible":
                    body = PricingCompatible(q.GetValueOrDefault("consumer", "checkout"),
                                             q.GetValueOrDefault("sku", "ABC"),
                                             ParseDoubleOr(q.GetValueOrDefault("cost_usd", "100"), 100.0));
                    Interlocked.Increment(ref _compatibleCalls);
                    break;
                case "/flows":
                    body = FlowsJson();
                    break;
                case "/diagnostics/summary":
                    body = DiagnosticsJson();
                    break;
                case "/reset-lab":
                    Interlocked.Exchange(ref _bigbangCalls, 0);
                    Interlocked.Exchange(ref _bigbangBroken, 0);
                    Interlocked.Exchange(ref _compatibleCalls, 0);
                    Interlocked.Exchange(ref _proxyHits, 0);
                    Interlocked.Exchange(ref _contractTestsPassed, 0);
                    lock (EventsLock) Events.Clear();
                    foreach (var k in new List<string>(CutoverProgress.Keys)) CutoverProgress[k] = false;
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

    private static string PricingBigbang(string consumer, string sku, double costUsd)
    {
        Interlocked.Increment(ref _bigbangBroken);
        Emit("bigbang_broke:" + consumer);
        return "{\"variant\":\"bigbang\",\"consumer\":\"" + consumer + "\",\"sku\":\"" + sku +
               "\",\"status\":\"contract_violation\"," +
               "\"reason\":\"new module expects {price, currency}; consumer sent {sku, cost_usd}\"," +
               "\"note\":\"cutover sin compat layer rompe consumers sensibles.\"}";
    }

    private static string PricingCompatible(string consumer, string sku, double costUsd)
    {
        var old = new PriceRequestOld(sku, costUsd);
        var translated = CompatProxy(old);
        Interlocked.Increment(ref _proxyHits);
        Interlocked.Increment(ref _contractTestsPassed);
        if (CutoverProgress.TryGetValue(consumer, out var done) && !done)
        {
            CutoverProgress[consumer] = true;
            Emit("cutover_done:" + consumer);
        }
        bool cutoverDone = CutoverProgress.TryGetValue(consumer, out var d2) && d2;
        return "{\"variant\":\"compatible\",\"consumer\":\"" + consumer +
               "\",\"sku\":\"" + translated.Sku + "\"," +
               "\"price\":" + F(translated.Price) + "," +
               "\"currency\":\"" + translated.Currency + "\"," +
               "\"compatibility_proxy_hit\":true," +
               "\"cutover_done\":" + (cutoverDone ? "true" : "false") +
               ",\"note\":\"proxy traduce {cost_usd} a {price,currency}; consumer no rompe.\"}";
    }

    private static void Emit(string evt)
    {
        var tagged = "{\"at\":\"" + DateTime.UtcNow.ToString("o") + "\",\"event\":\"" + Escape(evt) + "\"}";
        List<Action<string>> snap;
        lock (BusLock) snap = new List<Action<string>>(CutoverBus);
        foreach (var sub in snap) { try { sub(tagged); } catch { } }
    }

    private static string FlowsJson()
    {
        var sb = new StringBuilder(512);
        sb.Append("{\"cutover_progress\":{");
        bool first = true;
        foreach (var kv in CutoverProgress)
        {
            if (!first) sb.Append(',');
            sb.Append('"').Append(kv.Key).Append("\":").Append(kv.Value ? "true" : "false");
            first = false;
        }
        sb.Append("},\"recent_events\":[");
        lock (EventsLock)
        {
            first = true;
            foreach (var e in Events) { if (!first) sb.Append(','); sb.Append(e); first = false; }
        }
        sb.Append("]}");
        return sb.ToString();
    }

    private static string DiagnosticsJson() =>
        "{\"stack\":\"" + Stack + "\",\"case\":\"" + CaseName + "\"," +
        "\"bigbang\":{\"calls\":" + Interlocked.Read(ref _bigbangCalls) +
        ",\"broken\":" + Interlocked.Read(ref _bigbangBroken) + "}," +
        "\"compatible\":{\"calls\":" + Interlocked.Read(ref _compatibleCalls) +
        ",\"compatibility_proxy_hits\":" + Interlocked.Read(ref _proxyHits) +
        ",\"contract_tests_passed\":" + Interlocked.Read(ref _contractTestsPassed) + "}," +
        "\"flows\":" + FlowsJson() + "}";

    private static double ParseDoubleOr(string raw, double dflt)
    {
        return double.TryParse(raw, NumberStyles.Float, CultureInfo.InvariantCulture, out var v) ? v : dflt;
    }

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
