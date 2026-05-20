using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Globalization;
using System.Net;
using System.Text;
using System.Threading;
using System.Threading.Tasks;

// Caso 04 — Timeout chain y retry storms (stack .NET 8).
// Espejo funcional del Main.java equivalente.
// Primitivas distintivas:
//   - CancellationTokenSource + Task.Delay para timeout cooperativo.
//   - Interlocked + lock para breaker state machine.

internal static class Program
{
    private const string CaseName = "04 - Timeout chain y retry storms";
    private static readonly string Stack = Environment.GetEnvironmentVariable("APP_STACK") ?? ".NET 8";
    private const long BreakerCooldownMs = 5000;
    private const int BreakerFailThreshold = 3;

    private static long _legacyRetries, _legacyFailures;
    private static long _resilientCalls, _resilientFallbacks, _resilientShortCircuits;

    private sealed class BreakerState
    {
        public string State = "closed";
        public int FailCount;
        public long OpenedAt;
    }
    private static readonly BreakerState Breaker = new();
    private static readonly object BreakerLock = new();
    private static long _lastFallbackPrice;

    private static readonly Random Rng = new(20420);

    private static async Task Main()
    {
        var port = int.TryParse(Environment.GetEnvironmentVariable("PORT"), out var p) ? p : 8080;
        var listener = new HttpListener();
        listener.Prefixes.Add($"http://+:{port}/");
        try { listener.Start(); }
        catch (HttpListenerException) { listener = new HttpListener(); listener.Prefixes.Add($"http://*:{port}/"); listener.Start(); }
        Console.WriteLine($"[case04-dotnet] listening on {port}");

        while (true)
        {
            HttpListenerContext ctx;
            try { ctx = await listener.GetContextAsync(); } catch { break; }
            _ = Task.Run(() => HandleAsync(ctx));
        }
    }

    private static async Task HandleAsync(HttpListenerContext ctx)
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
                    body = $"{{\"case\":\"{CaseName}\",\"stack\":\"{Stack}\",\"routes\":[\"/health\",\"/quote-legacy?fail=on\",\"/quote-resilient?fail=on\",\"/dependency/state\",\"/diagnostics/summary\",\"/reset-lab\"]}}"; break;
                case "/health":
                    body = $"{{\"status\":\"ok\",\"stack\":\"{Stack}\",\"case\":\"{CaseName}\"}}"; break;
                case "/quote-legacy":
                    body = await QuoteLegacyAsync(q.GetValueOrDefault("fail", "off") == "on"); break;
                case "/quote-resilient":
                    body = await QuoteResilientAsync(q.GetValueOrDefault("fail", "off") == "on");
                    Interlocked.Increment(ref _resilientCalls); break;
                case "/dependency/state":
                    body = BreakerJson(); break;
                case "/diagnostics/summary":
                    body = DiagnosticsJson(); break;
                case "/reset-lab":
                    Interlocked.Exchange(ref _legacyRetries, 0); Interlocked.Exchange(ref _legacyFailures, 0);
                    Interlocked.Exchange(ref _resilientCalls, 0); Interlocked.Exchange(ref _resilientFallbacks, 0);
                    Interlocked.Exchange(ref _resilientShortCircuits, 0);
                    lock (BreakerLock) { Breaker.State = "closed"; Breaker.FailCount = 0; Breaker.OpenedAt = 0; }
                    body = "{\"status\":\"reset\"}"; break;
                default:
                    status = 404; body = $"{{\"error\":\"not_found\",\"path\":\"{Escape(path)}\"}}"; break;
            }
        }
        catch (Exception e) { status = 500; body = $"{{\"error\":\"internal\",\"detail\":\"{Escape(e.Message)}\"}}"; }
        SendJson(ctx, status, body);
    }

    private static async Task<string> QuoteLegacyAsync(bool fail)
    {
        var sw = Stopwatch.StartNew();
        for (int attempt = 1; attempt <= 5; attempt++)
        {
            Interlocked.Increment(ref _legacyRetries);
            try
            {
                long quote = await CallProviderAsync(fail, 800, CancellationToken.None);
                sw.Stop();
                return $"{{\"variant\":\"legacy\",\"status\":\"ok\",\"attempts\":{attempt},\"quote\":{quote},\"elapsed_ms\":{F(Round2(sw.Elapsed.TotalMilliseconds))}}}";
            }
            catch { /* sin backoff, sin breaker */ }
        }
        Interlocked.Increment(ref _legacyFailures);
        sw.Stop();
        return $"{{\"variant\":\"legacy\",\"status\":\"failed\",\"attempts\":5,\"elapsed_ms\":{F(Round2(sw.Elapsed.TotalMilliseconds))},\"note\":\"5 reintentos sin backoff agotaron al proveedor; sin circuit breaker.\"}}";
    }

    private static async Task<string> QuoteResilientAsync(bool fail)
    {
        var sw = Stopwatch.StartNew();
        string state; int failCount; long openedAt;
        lock (BreakerLock) { state = Breaker.State; failCount = Breaker.FailCount; openedAt = Breaker.OpenedAt; }
        long nowMs = DateTimeOffset.UtcNow.ToUnixTimeMilliseconds();
        if (state == "open" && (nowMs - openedAt) < BreakerCooldownMs)
        {
            Interlocked.Increment(ref _resilientShortCircuits);
            long fb = Interlocked.Read(ref _lastFallbackPrice);
            sw.Stop();
            return $"{{\"variant\":\"resilient\",\"status\":\"short_circuited\",\"breaker\":\"open\",\"fallback_quote\":{fb},\"elapsed_ms\":{F(Round2(sw.Elapsed.TotalMilliseconds))},\"note\":\"breaker abierto, devuelve fallback sin tocar al proveedor.\"}}";
        }

        using var cts = new CancellationTokenSource(TimeSpan.FromMilliseconds(300));
        try
        {
            long quote = await CallProviderAsync(fail, 800, cts.Token);
            OnSuccess();
            Interlocked.Exchange(ref _lastFallbackPrice, quote);
            lock (BreakerLock) state = Breaker.State;
            sw.Stop();
            return $"{{\"variant\":\"resilient\",\"status\":\"ok\",\"quote\":{quote},\"breaker\":\"{state}\",\"elapsed_ms\":{F(Round2(sw.Elapsed.TotalMilliseconds))}}}";
        }
        catch (Exception e)
        {
            OnFailure();
            Interlocked.Increment(ref _resilientFallbacks);
            long fb = Interlocked.Read(ref _lastFallbackPrice);
            lock (BreakerLock) state = Breaker.State;
            var cause = e is OperationCanceledException ? "timeout" : "provider_error";
            sw.Stop();
            return $"{{\"variant\":\"resilient\",\"status\":\"fallback\",\"breaker\":\"{state}\",\"fallback_quote\":{fb},\"elapsed_ms\":{F(Round2(sw.Elapsed.TotalMilliseconds))},\"cause\":\"{cause}\"}}";
        }
    }

    private static void OnSuccess()
    {
        lock (BreakerLock) { Breaker.State = "closed"; Breaker.FailCount = 0; Breaker.OpenedAt = 0; }
    }

    private static void OnFailure()
    {
        lock (BreakerLock)
        {
            int fails = Breaker.FailCount + 1;
            if (fails >= BreakerFailThreshold) { Breaker.State = "open"; Breaker.FailCount = fails; Breaker.OpenedAt = DateTimeOffset.UtcNow.ToUnixTimeMilliseconds(); }
            else { Breaker.FailCount = fails; }
        }
    }

    private static async Task<long> CallProviderAsync(bool fail, int latencyMs, CancellationToken ct)
    {
        await Task.Delay(latencyMs, ct);
        if (fail) throw new Exception("provider_unavailable");
        lock (Rng) return 100L + Rng.Next(900);
    }

    private static string BreakerJson()
    {
        string state; int failCount; long openedAt;
        lock (BreakerLock) { state = Breaker.State; failCount = Breaker.FailCount; openedAt = Breaker.OpenedAt; }
        long cooldownLeft = Math.Max(0, BreakerCooldownMs - (DateTimeOffset.UtcNow.ToUnixTimeMilliseconds() - openedAt));
        return $"{{\"state\":\"{state}\",\"fail_count\":{failCount},\"opened_at\":{openedAt},\"cooldown_left_ms\":{cooldownLeft},\"threshold\":{BreakerFailThreshold},\"cooldown_ms\":{BreakerCooldownMs}}}";
    }

    private static string DiagnosticsJson() =>
        $"{{\"stack\":\"{Stack}\",\"case\":\"{CaseName}\"," +
        $"\"legacy\":{{\"retries_total\":{Interlocked.Read(ref _legacyRetries)},\"failures\":{Interlocked.Read(ref _legacyFailures)},\"note\":\"reintentos lineales sin breaker producen retry storm\"}}," +
        $"\"resilient\":{{\"calls\":{Interlocked.Read(ref _resilientCalls)},\"fallbacks\":{Interlocked.Read(ref _resilientFallbacks)},\"short_circuits\":{Interlocked.Read(ref _resilientShortCircuits)},\"breaker\":{BreakerJson()}}}}}";

    private static double Round2(double v) => Math.Round(v, 2);
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
