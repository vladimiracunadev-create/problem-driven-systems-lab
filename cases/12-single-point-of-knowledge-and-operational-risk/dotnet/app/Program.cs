using System;
using System.Collections.Concurrent;
using System.Collections.Generic;
using System.Net;
using System.Text;
using System.Threading;
using System.Threading.Tasks;

// Caso 12 — Punto unico de conocimiento y riesgo operacional — stack .NET 8.
//
// Primitivas .NET distintivas:
//   - Null-conditional ?. + null-coalescing ?? como runbook codificado
//     (equivalente al Optional<T>.map/orElse de Java).
//   - record types para Incident/Owner inmutables.
//   - Interlocked para coverage/busFactor/contadores.

internal static class Program
{
    private const string CaseName = "12 - Punto unico de conocimiento";
    private static readonly string Stack = Environment.GetEnvironmentVariable("APP_STACK") ?? ".NET 8";
    private const int MaxIncidents = 30;

    private sealed record Owner(string Name, bool Available, Dictionary<string, string> Runbook);
    private sealed record Incident(string At, string Variant, string Scenario, string Result, long MttrMin);

    private static readonly ConcurrentDictionary<string, Owner> Owners = new();
    private static readonly LinkedList<Incident> Incidents = new();
    private static readonly object IncidentsLock = new();
    private static int _coverage = 30;
    private static int _busFactor = 1;
    private static readonly Random Rng = new();

    private static long _legacyIncidents;
    private static long _legacyCrashed;
    private static long _distributedIncidents;
    private static long _distributedHandled;

    static Program()
    {
        Owners["alice"] = new Owner("alice", true, new Dictionary<string, string>
        {
            ["db_failover"] = "step1; step2; step3",
            ["cache_purge"] = "redis-cli flushall on prod-cache-01",
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
        Console.WriteLine($"[case12-dotnet] listening on {port}");

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
                           "\",\"routes\":[\"/health\",\"/incident-legacy?scenario=owner_absent&runbook=db_failover\",\"/incident-distributed?scenario=owner_absent&runbook=db_failover\",\"/share-knowledge?owner=bob&runbook=db_failover\",\"/incidents\",\"/diagnostics/summary\",\"/reset-lab\"]}";
                    break;
                case "/health":
                    body = $"{{\"status\":\"ok\",\"stack\":\"{Stack}\",\"case\":\"{CaseName}\"}}";
                    break;
                case "/incident-legacy":
                    body = IncidentLegacy(q.GetValueOrDefault("scenario", "owner_absent"),
                                          q.GetValueOrDefault("runbook", "db_failover"));
                    Interlocked.Increment(ref _legacyIncidents);
                    break;
                case "/incident-distributed":
                    body = IncidentDistributed(q.GetValueOrDefault("scenario", "owner_absent"),
                                               q.GetValueOrDefault("runbook", "db_failover"));
                    Interlocked.Increment(ref _distributedIncidents);
                    break;
                case "/share-knowledge":
                    body = ShareKnowledge(q.GetValueOrDefault("owner", "bob"),
                                          q.GetValueOrDefault("runbook", "db_failover"));
                    break;
                case "/incidents":
                    body = IncidentsJson();
                    break;
                case "/diagnostics/summary":
                    body = DiagnosticsJson();
                    break;
                case "/reset-lab":
                    Interlocked.Exchange(ref _legacyIncidents, 0);
                    Interlocked.Exchange(ref _legacyCrashed, 0);
                    Interlocked.Exchange(ref _distributedIncidents, 0);
                    Interlocked.Exchange(ref _distributedHandled, 0);
                    Interlocked.Exchange(ref _coverage, 30);
                    Interlocked.Exchange(ref _busFactor, 1);
                    Owners.Clear();
                    Owners["alice"] = new Owner("alice", true, new Dictionary<string, string>
                    {
                        ["db_failover"] = "step1; step2; step3",
                        ["cache_purge"] = "redis-cli flushall on prod-cache-01",
                    });
                    lock (IncidentsLock) Incidents.Clear();
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

    /// Legacy: acceso ciego a estructura anidada -> NRE cuando owner ausente.
    private static string IncidentLegacy(string scenario, string runbookKey)
    {
        try
        {
            Owner? owner = PickOwnerLegacy(scenario);
            // acceso ciego: si owner == null, NRE
            string script = owner!.Runbook[runbookKey]; // KeyNotFoundException si runbook missing
            string executed = script.ToUpperInvariant();
            long mttr = 25 + (long)(Rng.NextDouble() * 30);
            Record("legacy", scenario, "executed", mttr);
            var preview = Escape(executed);
            if (preview.Length > 40) preview = preview.Substring(0, 40);
            return "{\"variant\":\"legacy\",\"status\":\"executed\",\"scenario\":\"" + scenario +
                   "\",\"runbook\":\"" + runbookKey + "\",\"mttr_min\":" + mttr +
                   ",\"executed_preview\":\"" + preview + "...\"}";
        }
        catch (Exception e)
        {
            Interlocked.Increment(ref _legacyCrashed);
            Record("legacy", scenario, "crashed: " + e.GetType().Name, 120);
            return "{\"variant\":\"legacy\",\"status\":\"crashed\",\"scenario\":\"" + scenario +
                   "\",\"reason\":\"" + e.GetType().Name + ": " + Escape(e.Message) + "\"" +
                   ",\"mttr_min\":120,\"note\":\"acceso ciego a estructuras anidadas falla cuando owner/runbook ausente.\"}";
        }
    }

    /// Distributed: ?. + ?? = runbook codificado, no crashea.
    private static string IncidentDistributed(string scenario, string runbookKey)
    {
        Owner? owner = PickOwnerDistributed(scenario);
        // optional chaining .NET: si owner null o runbook missing -> null
        string? script = null;
        if (owner != null && owner.Runbook.TryGetValue(runbookKey, out var s)) script = s;
        long mttr;
        string result;
        if (script == null)
        {
            mttr = 35 + (long)(Rng.NextDouble() * 15);
            result = "owner_absent_handled_via_team_runbook";
        }
        else
        {
            mttr = 15 + (long)(Rng.NextDouble() * 10);
            result = "executed_by_primary";
        }
        Interlocked.Increment(ref _distributedHandled);
        Record("distributed", scenario, result, mttr);
        bool primaryAvailable = owner?.Available ?? false;
        return "{\"variant\":\"distributed\",\"status\":\"handled\",\"scenario\":\"" + scenario +
               "\",\"runbook\":\"" + runbookKey + "\"," +
               "\"result\":\"" + result + "\"," +
               "\"primary_available\":" + (primaryAvailable ? "true" : "false") +
               ",\"mttr_min\":" + mttr +
               ",\"coverage\":" + _coverage +
               ",\"bus_factor\":" + _busFactor +
               ",\"note\":\"?. + ?? = runbook codificado.\"}";
    }

    private static string ShareKnowledge(string name, string runbookKey)
    {
        if (!Owners.TryGetValue("alice", out var alice))
            return "{\"status\":\"error\",\"reason\":\"primary owner missing\"}";
        Owners[name] = new Owner(name, true, new Dictionary<string, string>(alice.Runbook));
        int newCoverage = Math.Min(100, _coverage + 15);
        Interlocked.Exchange(ref _coverage, newCoverage);
        Interlocked.Increment(ref _busFactor);
        return "{\"status\":\"shared\",\"new_owner\":\"" + name +
               "\",\"runbook\":\"" + runbookKey + "\"," +
               "\"coverage\":" + newCoverage +
               ",\"bus_factor\":" + _busFactor +
               ",\"note\":\"el conocimiento dejo de vivir solo en alice; bus factor sube.\"}";
    }

    private static Owner? PickOwnerLegacy(string scenario)
    {
        if (scenario == "owner_absent" || scenario == "night_shift") return null;
        return Owners.TryGetValue("alice", out var a) ? a : null;
    }

    private static Owner? PickOwnerDistributed(string scenario)
    {
        if (scenario == "owner_absent" || scenario == "night_shift")
        {
            foreach (var o in Owners.Values)
                if (o.Name != "alice" && o.Available) return o;
            return null;
        }
        return Owners.TryGetValue("alice", out var a) ? a : null;
    }

    private static void Record(string variant, string scenario, string result, long mttr)
    {
        lock (IncidentsLock)
        {
            Incidents.AddFirst(new Incident(DateTime.UtcNow.ToString("o"), variant, scenario, result, mttr));
            while (Incidents.Count > MaxIncidents) Incidents.RemoveLast();
        }
    }

    private static string IncidentsJson()
    {
        var sb = new StringBuilder(1024);
        sb.Append("{\"recent\":[");
        lock (IncidentsLock)
        {
            bool first = true;
            foreach (var i in Incidents)
            {
                if (!first) sb.Append(',');
                sb.Append("{\"at\":\"").Append(i.At).Append("\",\"variant\":\"").Append(i.Variant)
                  .Append("\",\"scenario\":\"").Append(i.Scenario).Append("\",\"result\":\"").Append(Escape(i.Result))
                  .Append("\",\"mttr_min\":").Append(i.MttrMin).Append('}');
                first = false;
            }
        }
        sb.Append("]}");
        return sb.ToString();
    }

    private static string DiagnosticsJson() =>
        "{\"stack\":\"" + Stack + "\",\"case\":\"" + CaseName + "\"," +
        "\"legacy\":{\"incidents\":" + Interlocked.Read(ref _legacyIncidents) +
        ",\"crashed\":" + Interlocked.Read(ref _legacyCrashed) +
        ",\"behavior\":\"acceso ciego, NRE cuando estructura ausente\"}," +
        "\"distributed\":{\"incidents\":" + Interlocked.Read(ref _distributedIncidents) +
        ",\"handled\":" + Interlocked.Read(ref _distributedHandled) +
        ",\"behavior\":\"?.+?? = runbook codificado\"}," +
        "\"coverage\":" + _coverage +
        ",\"bus_factor\":" + _busFactor + "}";

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
