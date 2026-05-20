using System;
using System.Collections.Concurrent;
using System.Collections.Generic;
using System.Diagnostics;
using System.Net;
using System.Text;
using System.Threading;
using System.Threading.Tasks;

// Caso 11 — Reportes pesados que bloquean operacion — stack .NET 8.
//
// Primitivas .NET distintivas:
//   - Default ThreadPool actua como "main pool" (lo usan los handlers HTTP).
//   - ConcurrentExclusiveSchedulerPair para scheduler dedicado al reporting,
//     equivalente al ExecutorService Java newFixedThreadPool(2). Asi /report-isolated
//     corre fuera del ThreadPool default y no satura los handlers de /order-write.
//   - ThreadPool.GetAvailableThreads + Interlocked counter para activeMain (handlers en vuelo).

internal static class Program
{
    private const string CaseName = "11 - Reportes pesados que bloquean operacion";
    private static readonly string Stack = Environment.GetEnvironmentVariable("APP_STACK") ?? ".NET 8";

    // Scheduler dedicado limitado a 2 tasks concurrentes (espejo de newFixedThreadPool(2))
    private static readonly ConcurrentExclusiveSchedulerPair ReportingPair =
        new(TaskScheduler.Default, maxConcurrencyLevel: 2);
    private static readonly TaskFactory ReportingFactory =
        new(CancellationToken.None, TaskCreationOptions.None, TaskContinuationOptions.None,
            ReportingPair.ConcurrentScheduler);

    private static long _legacyReports;
    private static long _isolatedReports;
    private static long _orderWrites;
    private static long _orderWritesDegraded;
    private static long _mainActive; // handlers en vuelo

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
        Console.WriteLine($"[case11-dotnet] listening on {port}");

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
        Interlocked.Increment(ref _mainActive);
        try
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
                               "\",\"routes\":[\"/health\",\"/report-legacy?rows=200000\",\"/report-isolated?rows=200000\",\"/order-write\",\"/activity\",\"/diagnostics/summary\",\"/reset-lab\"]}";
                        break;
                    case "/health":
                        body = $"{{\"status\":\"ok\",\"stack\":\"{Stack}\",\"case\":\"{CaseName}\"}}";
                        break;
                    case "/report-legacy":
                        body = ReportLegacy(Bounded(q.GetValueOrDefault("rows", "200000"), 1000, 5_000_000));
                        Interlocked.Increment(ref _legacyReports);
                        break;
                    case "/report-isolated":
                        body = ReportIsolated(Bounded(q.GetValueOrDefault("rows", "200000"), 1000, 5_000_000));
                        Interlocked.Increment(ref _isolatedReports);
                        break;
                    case "/order-write":
                        body = OrderWrite();
                        Interlocked.Increment(ref _orderWrites);
                        break;
                    case "/activity":
                        body = ActivityJson();
                        break;
                    case "/diagnostics/summary":
                        body = DiagnosticsJson();
                        break;
                    case "/reset-lab":
                        Interlocked.Exchange(ref _legacyReports, 0);
                        Interlocked.Exchange(ref _isolatedReports, 0);
                        Interlocked.Exchange(ref _orderWrites, 0);
                        Interlocked.Exchange(ref _orderWritesDegraded, 0);
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
        finally
        {
            Interlocked.Decrement(ref _mainActive);
        }
    }

    /** Legacy: trabajo CPU corre inline en el thread del ThreadPool que sirve este handler. */
    private static string ReportLegacy(int rows)
    {
        var sw = Stopwatch.StartNew();
        long checksum = 0;
        for (int i = 0; i < rows; i++)
        {
            checksum += (i * 13L) % 7;
            if ((i & 0xFFFF) == 0) Thread.Yield();
        }
        sw.Stop();
        long elapsedMs = (long)sw.Elapsed.TotalMilliseconds;
        ThreadPool.GetAvailableThreads(out int worker, out _);
        ThreadPool.GetMaxThreads(out int maxWorker, out _);
        int active = maxWorker - worker;
        return "{\"variant\":\"legacy\",\"rows\":" + rows +
               ",\"checksum\":" + checksum +
               ",\"elapsed_ms\":" + elapsedMs +
               ",\"ran_on_pool\":\"main\"," +
               "\"main_pool_active\":" + active +
               ",\"main_pool_queue\":" + Interlocked.Read(ref _mainActive) +
               ",\"note\":\"corre en el ThreadPool default que sirve /order-write; mas reportes = mas saturacion.\"}";
    }

    /** Isolated: trabajo sale al ReportingScheduler dedicado, ThreadPool default intacto. */
    private static string ReportIsolated(int rows)
    {
        var sw = Stopwatch.StartNew();
        var task = ReportingFactory.StartNew(() =>
        {
            long checksum = 0;
            for (int i = 0; i < rows; i++) checksum += (i * 13L) % 7;
            return checksum;
        });
        long checksum;
        try { checksum = task.Result; }
        catch (Exception e)
        {
            return "{\"variant\":\"isolated\",\"status\":\"error\",\"detail\":\"" + Escape(e.Message) + "\"}";
        }
        sw.Stop();
        long elapsedMs = (long)sw.Elapsed.TotalMilliseconds;
        ThreadPool.GetAvailableThreads(out int worker, out _);
        ThreadPool.GetMaxThreads(out int maxWorker, out _);
        int active = maxWorker - worker;
        return "{\"variant\":\"isolated\",\"rows\":" + rows +
               ",\"checksum\":" + checksum +
               ",\"elapsed_ms\":" + elapsedMs +
               ",\"ran_on_pool\":\"reporting\"," +
               "\"main_pool_active\":" + active +
               ",\"main_pool_queue\":" + Interlocked.Read(ref _mainActive) +
               ",\"note\":\"corre en scheduler dedicado; /order-write conserva su latencia normal.\"}";
    }

    private static string OrderWrite()
    {
        ThreadPool.GetAvailableThreads(out int workerBefore, out _);
        ThreadPool.GetMaxThreads(out int maxWorker, out _);
        int activeBefore = maxWorker - workerBefore;
        var sw = Stopwatch.StartNew();
        try { Thread.Sleep(20); } catch { }
        sw.Stop();
        long elapsedMs = (long)sw.Elapsed.TotalMilliseconds;
        bool degraded = elapsedMs > 100;
        if (degraded) Interlocked.Increment(ref _orderWritesDegraded);
        return "{\"variant\":\"order-write\"," +
               "\"elapsed_ms\":" + elapsedMs +
               ",\"degraded\":" + (degraded ? "true" : "false") +
               ",\"main_pool_active_at_entry\":" + activeBefore +
               ",\"note\":\"" + (degraded
                    ? "la latencia subio por saturacion del pool principal"
                    : "operacion responde con latencia normal") + "\"}";
    }

    private static string ActivityJson()
    {
        ThreadPool.GetAvailableThreads(out int worker, out _);
        ThreadPool.GetMaxThreads(out int maxWorker, out _);
        ThreadPool.GetMinThreads(out int minWorker, out _);
        int active = maxWorker - worker;
        return "{\"main_pool_active\":" + active +
               ",\"main_pool_queue\":" + Interlocked.Read(ref _mainActive) +
               ",\"main_pool_size\":" + minWorker +
               ",\"main_pool_max\":" + maxWorker +
               ",\"order_writes\":" + Interlocked.Read(ref _orderWrites) +
               ",\"order_writes_degraded\":" + Interlocked.Read(ref _orderWritesDegraded) + "}";
    }

    private static string DiagnosticsJson() =>
        "{\"stack\":\"" + Stack + "\",\"case\":\"" + CaseName + "\"," +
        "\"legacy\":{\"reports\":" + Interlocked.Read(ref _legacyReports) +
        ",\"behavior\":\"bloquea thread del ThreadPool default, /order-write se degrada\"}," +
        "\"isolated\":{\"reports\":" + Interlocked.Read(ref _isolatedReports) +
        ",\"behavior\":\"corre en scheduler dedicado, /order-write intacto\"}," +
        "\"activity\":" + ActivityJson() + "}";

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
