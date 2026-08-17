using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Net;
using System.Net.Http;
using System.Net.Sockets;
using System.Text;
using System.Threading;
using System.Threading.Tasks;

// .NET Lab Dispatcher — un solo contenedor, un solo puerto para los casos C#.
//
// Espejo del patron java-dispatcher / python-dispatcher / node-dispatcher:
//   - Spawnea cada caso como subproceso interno (`dotnet /app/cases/0X/Case0X.dll`).
//   - Listening publico en :8500.
//   - Enruta por prefijo de path: /01/* → :9501, /02/* → :9502, ..., /12/* → :9512.
//   - Los puertos internos nunca se exponen al host.
//
// Casos operativos hoy: 01-12.

internal static class Program
{
    private sealed record CaseInfo(string Id, int Port, string Name, string Dir);

    private static readonly List<CaseInfo> Cases = new()
    {
        new CaseInfo("01", 9501, "API lenta bajo carga",            "/app/cases/01"),
        new CaseInfo("02", 9502, "N+1 y cuellos de botella DB",     "/app/cases/02"),
        new CaseInfo("03", 9503, "Observabilidad deficiente",       "/app/cases/03"),
        new CaseInfo("04", 9504, "Timeout chain y retry storms",    "/app/cases/04"),
        new CaseInfo("05", 9505, "Presion de memoria y fugas",      "/app/cases/05"),
        new CaseInfo("06", 9506, "Pipeline roto y delivery fragil", "/app/cases/06"),
        new CaseInfo("07", 9507, "Modernizacion incremental de monolito",   "/app/cases/07"),
        new CaseInfo("08", 9508, "Extraccion critica de modulo",            "/app/cases/08"),
        new CaseInfo("09", 9509, "Integracion externa inestable",           "/app/cases/09"),
        new CaseInfo("10", 9510, "Arquitectura cara para algo simple",      "/app/cases/10"),
        new CaseInfo("11", 9511, "Reportes pesados que bloquean operacion", "/app/cases/11"),
        new CaseInfo("12", 9512, "Punto unico de conocimiento",             "/app/cases/12"),
        new CaseInfo("13", 9513, "Cache stampede y thundering herd",       "/app/cases/13"),
        new CaseInfo("14", 9514, "Agotamiento del pool de conexiones",       "/app/cases/14"),
    };

    private static readonly int DispatchPort =
        int.TryParse(Environment.GetEnvironmentVariable("PORT"), out var p) ? p : 8500;

    private static readonly string AppStack =
        Environment.GetEnvironmentVariable("APP_STACK") ?? ".NET 8";

    private static readonly HttpClient Http = new(new SocketsHttpHandler
    {
        ConnectTimeout = TimeSpan.FromSeconds(2),
        PooledConnectionLifetime = TimeSpan.FromMinutes(5),
    })
    {
        Timeout = TimeSpan.FromSeconds(30),
    };

    private static async Task Main()
    {
        Console.WriteLine($"[dotnet-dispatcher] starting {Cases.Count} case servers...");
        foreach (var c in Cases) SpawnCase(c);

        foreach (var c in Cases) await WaitHealthyAsync(c, TimeSpan.FromSeconds(30));

        var listener = new HttpListener();
        listener.Prefixes.Add($"http://+:{DispatchPort}/");
        try
        {
            listener.Start();
        }
        catch (HttpListenerException)
        {
            // En Linux containers, HttpListener bind a "+" puede no funcionar. Fallback a "*".
            listener = new HttpListener();
            listener.Prefixes.Add($"http://*:{DispatchPort}/");
            listener.Start();
        }
        Console.WriteLine($"[dotnet-dispatcher] listening on {DispatchPort}");

        var cts = new CancellationTokenSource();
        Console.CancelKeyPress += (_, e) => { e.Cancel = true; cts.Cancel(); listener.Stop(); };

        while (!cts.IsCancellationRequested)
        {
            HttpListenerContext ctx;
            try { ctx = await listener.GetContextAsync(); }
            catch (HttpListenerException) { break; }
            catch (ObjectDisposedException) { break; }
            _ = Task.Run(() => HandleAsync(ctx));
        }
    }

    private static void SpawnCase(CaseInfo c)
    {
        var dll = Path.Combine(c.Dir, $"Case{c.Id}.dll");
        var psi = new ProcessStartInfo("dotnet", dll)
        {
            WorkingDirectory = c.Dir,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            UseShellExecute = false,
        };
        psi.Environment["PORT"] = c.Port.ToString();
        psi.Environment["APP_STACK"] = AppStack;
        psi.Environment["ASPNETCORE_URLS"] = $"http://0.0.0.0:{c.Port}";

        var p = Process.Start(psi) ?? throw new InvalidOperationException($"Could not spawn case {c.Id}");
        // descartar stdout/stderr para no llenar buffers (igual que java pb.redirectOutput DISCARD)
        _ = Task.Run(async () => { try { while (!p.StandardOutput.EndOfStream) await p.StandardOutput.ReadLineAsync(); } catch {} });
        _ = Task.Run(async () => { try { while (!p.StandardError.EndOfStream) await p.StandardError.ReadLineAsync(); } catch {} });
        Console.WriteLine($"  case {c.Id} → interno :{c.Port} (pid {p.Id})");
    }

    private static async Task WaitHealthyAsync(CaseInfo c, TimeSpan timeout)
    {
        var deadline = DateTime.UtcNow + timeout;
        while (DateTime.UtcNow < deadline)
        {
            try
            {
                using var req = new HttpRequestMessage(HttpMethod.Get, $"http://127.0.0.1:{c.Port}/health");
                using var cts = new CancellationTokenSource(TimeSpan.FromMilliseconds(800));
                var resp = await Http.SendAsync(req, cts.Token);
                if ((int)resp.StatusCode == 200)
                {
                    Console.WriteLine($"  case {c.Id} healthy");
                    return;
                }
            }
            catch { /* ignore */ }
            await Task.Delay(500);
        }
        Console.Error.WriteLine($"[dotnet-dispatcher] case {c.Id} did not become healthy in {timeout.TotalMilliseconds}ms");
    }

    private static async Task HandleAsync(HttpListenerContext ctx)
    {
        var req = ctx.Request;
        var path = req.Url?.AbsolutePath ?? "/";
        try
        {
            if (path == "/" || path == "/index" || path == "/index.html")
            {
                await SendJsonAsync(ctx, 200, IndexJson());
                return;
            }
            if (path == "/health")
            {
                await SendJsonAsync(ctx, 200, $"{{\"status\":\"ok\",\"stack\":\"{AppStack}\",\"role\":\"dispatcher\"}}");
                return;
            }
            if (path.Length < 3 || path[0] != '/')
            {
                await SendJsonAsync(ctx, 404, "{\"error\":\"not_found\",\"hint\":\"usa /01/..., /02/..., ..., /12/...\"}");
                return;
            }
            var caseId = path.Substring(1, 2);
            var target = Cases.FirstOrDefault(c => c.Id == caseId);
            if (target == null)
            {
                await SendJsonAsync(ctx, 404, $"{{\"error\":\"case_not_found\",\"case\":\"{Escape(caseId)}\"}}");
                return;
            }
            var remainder = path.Length > 3 ? path.Substring(3) : "/";
            var query = req.Url?.Query ?? "";
            var url = $"http://127.0.0.1:{target.Port}{remainder}{query}";

            using var proxied = new HttpRequestMessage(new HttpMethod(req.HttpMethod), url);
            byte[] bodyBytes = Array.Empty<byte>();
            if (req.HasEntityBody)
            {
                using var ms = new MemoryStream();
                await req.InputStream.CopyToAsync(ms);
                bodyBytes = ms.ToArray();
            }
            if (bodyBytes.Length > 0 && !string.Equals(req.HttpMethod, "GET", StringComparison.OrdinalIgnoreCase))
            {
                proxied.Content = new ByteArrayContent(bodyBytes);
                var ct = req.ContentType;
                if (!string.IsNullOrEmpty(ct))
                {
                    try { proxied.Content.Headers.TryAddWithoutValidation("Content-Type", ct); } catch {}
                }
            }
            foreach (string? hk in req.Headers.AllKeys)
            {
                if (hk == null) continue;
                if (hk.Equals("Host", StringComparison.OrdinalIgnoreCase)) continue;
                if (hk.Equals("Content-Length", StringComparison.OrdinalIgnoreCase)) continue;
                if (hk.Equals("Content-Type", StringComparison.OrdinalIgnoreCase)) continue;
                var v = req.Headers[hk];
                if (v == null) continue;
                if (!proxied.Headers.TryAddWithoutValidation(hk, v) && proxied.Content != null)
                {
                    proxied.Content.Headers.TryAddWithoutValidation(hk, v);
                }
            }

            HttpResponseMessage resp;
            try { resp = await Http.SendAsync(proxied, HttpCompletionOption.ResponseHeadersRead); }
            catch (Exception e)
            {
                await SendJsonAsync(ctx, 502,
                    $"{{\"error\":\"upstream_unavailable\",\"case\":\"{caseId}\",\"detail\":\"{Escape(e.Message)}\"}}");
                return;
            }

            try
            {
                ctx.Response.StatusCode = (int)resp.StatusCode;
                foreach (var h in resp.Headers)
                {
                    if (h.Key.Equals("Transfer-Encoding", StringComparison.OrdinalIgnoreCase)) continue;
                    if (h.Key.Equals("Content-Length", StringComparison.OrdinalIgnoreCase)) continue;
                    try { ctx.Response.Headers[h.Key] = string.Join(",", h.Value); } catch {}
                }
                foreach (var h in resp.Content.Headers)
                {
                    if (h.Key.Equals("Content-Length", StringComparison.OrdinalIgnoreCase)) continue;
                    try { ctx.Response.Headers[h.Key] = string.Join(",", h.Value); } catch {}
                }
                var body = await resp.Content.ReadAsByteArrayAsync();
                ctx.Response.ContentLength64 = body.Length;
                await ctx.Response.OutputStream.WriteAsync(body, 0, body.Length);
            }
            finally
            {
                resp.Dispose();
            }
        }
        catch (Exception e)
        {
            try { await SendJsonAsync(ctx, 500, $"{{\"error\":\"dispatcher_internal\",\"detail\":\"{Escape(e.Message)}\"}}"); }
            catch { /* ignore */ }
        }
        finally
        {
            try { ctx.Response.OutputStream.Close(); } catch {}
        }
    }

    private static string IndexJson()
    {
        var sb = new StringBuilder(1024);
        sb.Append("{\"lab\":\"Problem-Driven Systems Lab\",\"stack\":\"").Append(AppStack)
          .Append("\",\"role\":\"dispatcher\",\"usage\":\"GET /{caso}/{ruta}  →  e.g. /01/health, /04/quote-resilient\",\"cases\":{");
        var first = true;
        foreach (var c in Cases)
        {
            if (!first) sb.Append(',');
            sb.Append('"').Append(c.Id).Append("\":{\"name\":\"").Append(Escape(c.Name))
              .Append("\",\"health\":\"/").Append(c.Id).Append("/health\",\"index\":\"/")
              .Append(c.Id).Append("/\",\"internal_port\":").Append(c.Port).Append('}');
            first = false;
        }
        sb.Append("}}");
        return sb.ToString();
    }

    private static async Task SendJsonAsync(HttpListenerContext ctx, int status, string body)
    {
        var bytes = Encoding.UTF8.GetBytes(body);
        ctx.Response.StatusCode = status;
        ctx.Response.ContentType = "application/json; charset=utf-8";
        ctx.Response.ContentLength64 = bytes.Length;
        await ctx.Response.OutputStream.WriteAsync(bytes, 0, bytes.Length);
    }

    private static string Escape(string? v) =>
        v == null ? "" : v.Replace("\\", "\\\\").Replace("\"", "\\\"");
}
