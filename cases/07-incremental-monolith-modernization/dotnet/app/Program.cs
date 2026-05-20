using System;
using System.Collections.Concurrent;
using System.Collections.Generic;
using System.Net;
using System.Text;
using System.Threading;
using System.Threading.Tasks;

// Caso 07 — Modernizacion incremental de monolito (strangler) — stack .NET 8.
//
// Espejo del Main.java equivalente.
// Primitivas .NET distintivas:
//   - ConcurrentDictionary<string, Func<Request,Response>> como routing table mutable en runtime.
//   - record types para Request/Response inmutables.
//   - Interlocked para contadores LongAdder-equivalent.

internal static class Program
{
    private const string CaseName = "07 - Modernizacion incremental de monolito";
    private static readonly string Stack = Environment.GetEnvironmentVariable("APP_STACK") ?? ".NET 8";

    private sealed record Request(string Consumer, string Op, Dictionary<string, string> Payload);
    private sealed record Response(string Result, string RoutedTo, int BlastRadiusScore, int RiskScore);

    private static readonly ConcurrentDictionary<string, Func<Request, Response>> RoutingTable = new();
    private static readonly ConcurrentDictionary<string, int> MigrationProgress = new();

    private static long _legacyCalls;
    private static long _stranglerCalls;
    private static long _routedToNewModule;

    static Program()
    {
        RoutingTable["billing:change"] = req => new Response("ok-new-module", "new-billing-svc", 1, 1);
        MigrationProgress["billing"] = 100;
        MigrationProgress["orders"] = 0;
        MigrationProgress["inventory"] = 0;
        MigrationProgress["reporting"] = 0;
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
        Console.WriteLine($"[case07-dotnet] listening on {port}");

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
                           "\",\"routes\":[\"/health\",\"/change-legacy?consumer=billing&op=change\",\"/change-strangler?consumer=billing&op=change\",\"/flows\",\"/diagnostics/summary\",\"/reset-lab\"]}";
                    break;
                case "/health":
                    body = $"{{\"status\":\"ok\",\"stack\":\"{Stack}\",\"case\":\"{CaseName}\"}}";
                    break;
                case "/change-legacy":
                    body = ChangeLegacy(q.GetValueOrDefault("consumer", "billing"), q.GetValueOrDefault("op", "change"));
                    Interlocked.Increment(ref _legacyCalls);
                    break;
                case "/change-strangler":
                    body = ChangeStrangler(q.GetValueOrDefault("consumer", "billing"), q.GetValueOrDefault("op", "change"));
                    Interlocked.Increment(ref _stranglerCalls);
                    break;
                case "/flows":
                    body = FlowsJson();
                    break;
                case "/diagnostics/summary":
                    body = DiagnosticsJson();
                    break;
                case "/reset-lab":
                    Interlocked.Exchange(ref _legacyCalls, 0);
                    Interlocked.Exchange(ref _stranglerCalls, 0);
                    Interlocked.Exchange(ref _routedToNewModule, 0);
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

    private static string ChangeLegacy(string consumer, string op)
    {
        int blast = 4;
        int risk = 8;
        return "{\"variant\":\"legacy\",\"consumer\":\"" + consumer + "\",\"op\":\"" + op +
               "\",\"routed_to\":\"shared-monolith\"," +
               "\"blast_radius_score\":" + blast +
               ",\"risk_score\":" + risk +
               ",\"note\":\"cambio en shared_schema afecta los 4 modulos del monolito.\"}";
    }

    private static string ChangeStrangler(string consumer, string op)
    {
        var req = new Request(consumer, op, new Dictionary<string, string>());
        var key = consumer + ":" + op;
        if (RoutingTable.TryGetValue(key, out var handler))
        {
            var r = handler(req);
            Interlocked.Increment(ref _routedToNewModule);
            return "{\"variant\":\"strangler\",\"consumer\":\"" + consumer + "\",\"op\":\"" + op +
                   "\",\"routed_to\":\"" + r.RoutedTo + "\"," +
                   "\"blast_radius_score\":" + r.BlastRadiusScore +
                   ",\"risk_score\":" + r.RiskScore +
                   ",\"note\":\"routing table apunta a nuevo modulo; monolito intocado.\"}";
        }
        return "{\"variant\":\"strangler\",\"consumer\":\"" + consumer + "\",\"op\":\"" + op +
               "\",\"routed_to\":\"legacy-monolith\"," +
               "\"blast_radius_score\":2," +
               "\"risk_score\":4," +
               "\"note\":\"consumer aun no migrado; routing cae al legacy pero con ACL.\"}";
    }

    private static string FlowsJson()
    {
        var sb = new StringBuilder(512);
        sb.Append("{\"migration_progress\":{");
        bool first = true;
        foreach (var kv in MigrationProgress)
        {
            if (!first) sb.Append(',');
            sb.Append('"').Append(kv.Key).Append("\":").Append(kv.Value);
            first = false;
        }
        sb.Append("},\"routing_table_size\":").Append(RoutingTable.Count).Append('}');
        return sb.ToString();
    }

    private static string DiagnosticsJson() =>
        "{\"stack\":\"" + Stack + "\",\"case\":\"" + CaseName + "\"," +
        "\"legacy\":{\"calls\":" + Interlocked.Read(ref _legacyCalls) + ",\"avg_blast_radius\":4,\"avg_risk\":8}," +
        "\"strangler\":{\"calls\":" + Interlocked.Read(ref _stranglerCalls) +
        ",\"routed_to_new_module\":" + Interlocked.Read(ref _routedToNewModule) +
        ",\"routing_table_size\":" + RoutingTable.Count + "}," +
        "\"flows\":" + FlowsJson() + "}";

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
