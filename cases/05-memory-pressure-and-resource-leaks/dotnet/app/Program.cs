using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Globalization;
using System.Net;
using System.Text;
using System.Threading;
using System.Threading.Tasks;

// Caso 05 — Presion de memoria y fugas de recursos (stack .NET 8).
// Espejo funcional del Main.java equivalente.
// Primitivas distintivas:
//   - GC.GetTotalMemory + Process.WorkingSet64 para medir presion real.
//   - LinkedHashMap-equivalente: Dictionary + LinkedList para LRU manual.

internal static class Program
{
    private const string CaseName = "05 - Presion de memoria y fugas de recursos";
    private static readonly string Stack = Environment.GetEnvironmentVariable("APP_STACK") ?? ".NET 8";
    private const int OptimizedCap = 1000;

    // Leak real: lista estatica que crece para siempre.
    private static readonly List<byte[]> LegacyAccumulator = new();
    private static readonly object LegacyLock = new();

    // LRU acotada: Dictionary + LinkedList (orden de acceso).
    private static readonly Dictionary<int, LinkedListNode<KeyValuePair<int, byte[]>>> CacheMap = new();
    private static readonly LinkedList<KeyValuePair<int, byte[]>> CacheList = new();
    private static readonly object CacheLock = new();

    private static long _legacyRequests, _optimizedRequests, _optimizedEvictions;

    private static async Task Main()
    {
        var port = int.TryParse(Environment.GetEnvironmentVariable("PORT"), out var p) ? p : 8080;
        var listener = new HttpListener();
        listener.Prefixes.Add($"http://+:{port}/");
        try { listener.Start(); }
        catch (HttpListenerException) { listener = new HttpListener(); listener.Prefixes.Add($"http://*:{port}/"); listener.Start(); }
        Console.WriteLine($"[case05-dotnet] listening on {port}");

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
                    body = $"{{\"case\":\"{CaseName}\",\"stack\":\"{Stack}\",\"routes\":[\"/health\",\"/batch-legacy?size_kb=64\",\"/batch-optimized?size_kb=64\",\"/state\",\"/diagnostics/summary\",\"/reset-lab\"]}}"; break;
                case "/health":
                    body = $"{{\"status\":\"ok\",\"stack\":\"{Stack}\",\"case\":\"{CaseName}\"}}"; break;
                case "/batch-legacy":
                    body = BatchLegacy(Bounded(q.GetValueOrDefault("size_kb", "64"), 1, 4096));
                    Interlocked.Increment(ref _legacyRequests); break;
                case "/batch-optimized":
                    body = BatchOptimized(Bounded(q.GetValueOrDefault("size_kb", "64"), 1, 4096));
                    Interlocked.Increment(ref _optimizedRequests); break;
                case "/state":
                    body = StateJson(); break;
                case "/diagnostics/summary":
                    body = DiagnosticsJson(); break;
                case "/reset-lab":
                    lock (LegacyLock) LegacyAccumulator.Clear();
                    lock (CacheLock) { CacheMap.Clear(); CacheList.Clear(); }
                    Interlocked.Exchange(ref _legacyRequests, 0); Interlocked.Exchange(ref _optimizedRequests, 0); Interlocked.Exchange(ref _optimizedEvictions, 0);
                    GC.Collect();
                    body = "{\"status\":\"reset\",\"note\":\"acumuladores limpios + GC.Collect() invocado.\"}"; break;
                default:
                    status = 404; body = $"{{\"error\":\"not_found\",\"path\":\"{Escape(path)}\"}}"; break;
            }
        }
        catch (Exception e) { status = 500; body = $"{{\"error\":\"internal\",\"detail\":\"{Escape(e.Message)}\"}}"; }
        SendJson(ctx, status, body);
    }

    private static string BatchLegacy(int sizeKb)
    {
        var payload = new byte[sizeKb * 1024];
        for (int i = 0; i < payload.Length; i++) payload[i] = (byte)(i & 0xff);
        int retained;
        lock (LegacyLock) { LegacyAccumulator.Add(payload); retained = LegacyAccumulator.Count; }
        return $"{{\"variant\":\"legacy\",\"appended_kb\":{sizeKb},\"retained_count\":{retained},\"retained_kb_estimate\":{retained * sizeKb},\"note\":\"se acumula en lista estatica sin eviccion → fuga real cross-request.\"}}";
    }

    private static string BatchOptimized(int sizeKb)
    {
        var payload = new byte[sizeKb * 1024];
        for (int i = 0; i < payload.Length; i++) payload[i] = (byte)(i & 0xff);
        int key = (int)(Stopwatch.GetTimestamp() & 0x7FFFFFFF);
        int afterSize;
        bool evicted = false;
        lock (CacheLock)
        {
            int beforeSize = CacheList.Count;
            var node = new LinkedListNode<KeyValuePair<int, byte[]>>(new KeyValuePair<int, byte[]>(key, payload));
            CacheList.AddLast(node);
            CacheMap[key] = node;
            while (CacheList.Count > OptimizedCap)
            {
                var eldest = CacheList.First!;
                CacheList.RemoveFirst();
                CacheMap.Remove(eldest.Value.Key);
                evicted = true;
            }
            afterSize = CacheList.Count;
            if (evicted) Interlocked.Increment(ref _optimizedEvictions);
        }
        return $"{{\"variant\":\"optimized\",\"appended_kb\":{sizeKb},\"retained_count\":{afterSize},\"cap\":{OptimizedCap},\"evictions_total\":{Interlocked.Read(ref _optimizedEvictions)},\"note\":\"LRU manual (Dictionary + LinkedList) mantiene cap fijo, memoria estable.\"}}";
    }

    private static string StateJson()
    {
        long totalBytes = GC.GetTotalMemory(false);
        long workingSet;
        try { workingSet = Process.GetCurrentProcess().WorkingSet64; } catch { workingSet = 0; }
        int legacyCount; int optCount;
        lock (LegacyLock) legacyCount = LegacyAccumulator.Count;
        lock (CacheLock) optCount = CacheList.Count;
        long totalMb = totalBytes / (1024 * 1024);
        long wsMb = workingSet / (1024 * 1024);
        return "{" +
               $"\"stack\":\"{Stack}\"," +
               $"\"heap_used_mb\":{totalMb}," +
               $"\"heap_total_mb\":{totalMb}," +
               $"\"heap_max_mb\":-1," +
               $"\"heap_free_mb\":-1," +
               $"\"working_set_mb\":{wsMb}," +
               $"\"legacy_retained_count\":{legacyCount}," +
               $"\"optimized_retained_count\":{optCount}," +
               $"\"optimized_cap\":{OptimizedCap}}}";
    }

    private static string DiagnosticsJson()
    {
        int legacyCount; int optCount;
        lock (LegacyLock) legacyCount = LegacyAccumulator.Count;
        lock (CacheLock) optCount = CacheList.Count;
        return $"{{\"stack\":\"{Stack}\",\"case\":\"{CaseName}\"," +
               $"\"legacy\":{{\"requests\":{Interlocked.Read(ref _legacyRequests)},\"retained_count\":{legacyCount},\"behavior\":\"sin eviccion, leak monoticamente creciente\"}}," +
               $"\"optimized\":{{\"requests\":{Interlocked.Read(ref _optimizedRequests)},\"retained_count\":{optCount},\"evictions\":{Interlocked.Read(ref _optimizedEvictions)},\"cap\":{OptimizedCap},\"behavior\":\"LRU manual con cap fijo\"}}," +
               $"\"runtime\":{StateJson()}}}";
    }

    private static int Bounded(string raw, int min, int max) { if (!int.TryParse(raw, out var n)) return min; return Math.Max(min, Math.Min(n, max)); }
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
