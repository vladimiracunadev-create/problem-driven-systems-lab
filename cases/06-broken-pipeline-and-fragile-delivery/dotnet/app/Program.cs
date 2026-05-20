using System;
using System.Collections.Concurrent;
using System.Collections.Generic;
using System.Globalization;
using System.Net;
using System.Text;
using System.Threading;
using System.Threading.Tasks;

// Caso 06 — Pipeline roto y delivery fragil (stack .NET 8).
// Espejo funcional del Main.java equivalente.
// Primitivas distintivas:
//   - record para EnvState/Deployment inmutables.
//   - ConcurrentDictionary<string,EnvState> para snapshot por ambiente.

internal static class Program
{
    private const string CaseName = "06 - Pipeline roto y delivery fragil";
    private static readonly string Stack = Environment.GetEnvironmentVariable("APP_STACK") ?? ".NET 8";
    private const int MaxDeployments = 30;

    private sealed record EnvState(string Name, string Version, string Health);
    private sealed record Deployment(string At, string Variant, string Env, string Version, string Scenario, string Result);

    private static readonly ConcurrentDictionary<string, EnvState> Environments = new();
    private static readonly LinkedList<Deployment> Deployments = new();
    private static readonly object DeploymentsLock = new();

    private static long _legacyDeploys, _legacyBroken;
    private static long _controlledDeploys, _controlledRollbacks, _controlledBlocked;

    private static async Task Main()
    {
        Environments["staging"] = new EnvState("staging", "v1.0.0", "healthy");
        Environments["prod"] = new EnvState("prod", "v1.0.0", "healthy");

        var port = int.TryParse(Environment.GetEnvironmentVariable("PORT"), out var p) ? p : 8080;
        var listener = new HttpListener();
        listener.Prefixes.Add($"http://+:{port}/");
        try { listener.Start(); }
        catch (HttpListenerException) { listener = new HttpListener(); listener.Prefixes.Add($"http://*:{port}/"); listener.Start(); }
        Console.WriteLine($"[case06-dotnet] listening on {port}");

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
                    body = $"{{\"case\":\"{CaseName}\",\"stack\":\"{Stack}\",\"routes\":[\"/health\",\"/deploy-legacy?env=prod&version=v1.1.0&scenario=secret_drift\",\"/deploy-controlled?env=prod&version=v1.1.0&scenario=secret_drift\",\"/environments\",\"/deployments\",\"/diagnostics/summary\",\"/reset-lab\"]}}"; break;
                case "/health":
                    body = $"{{\"status\":\"ok\",\"stack\":\"{Stack}\",\"case\":\"{CaseName}\"}}"; break;
                case "/deploy-legacy":
                    body = DeployLegacy(q.GetValueOrDefault("env", "prod"), q.GetValueOrDefault("version", "v1.1.0"), q.GetValueOrDefault("scenario", "clean")); break;
                case "/deploy-controlled":
                    body = DeployControlled(q.GetValueOrDefault("env", "prod"), q.GetValueOrDefault("version", "v1.1.0"), q.GetValueOrDefault("scenario", "clean")); break;
                case "/environments":
                    body = EnvironmentsJson(); break;
                case "/deployments":
                    body = DeploymentsJson(); break;
                case "/diagnostics/summary":
                    body = DiagnosticsJson(); break;
                case "/reset-lab":
                    Environments["staging"] = new EnvState("staging", "v1.0.0", "healthy");
                    Environments["prod"] = new EnvState("prod", "v1.0.0", "healthy");
                    lock (DeploymentsLock) Deployments.Clear();
                    Interlocked.Exchange(ref _legacyDeploys, 0); Interlocked.Exchange(ref _legacyBroken, 0);
                    Interlocked.Exchange(ref _controlledDeploys, 0); Interlocked.Exchange(ref _controlledRollbacks, 0); Interlocked.Exchange(ref _controlledBlocked, 0);
                    body = "{\"status\":\"reset\"}"; break;
                default:
                    status = 404; body = $"{{\"error\":\"not_found\",\"path\":\"{Escape(path)}\"}}"; break;
            }
        }
        catch (Exception e) { status = 500; body = $"{{\"error\":\"internal\",\"detail\":\"{Escape(e.Message)}\"}}"; }
        SendJson(ctx, status, body);
    }

    private static string DeployLegacy(string env, string version, string scenario)
    {
        Interlocked.Increment(ref _legacyDeploys);
        string result, health;
        if (IsBadScenario(scenario)) { health = "degraded"; Interlocked.Increment(ref _legacyBroken); result = "deployed_but_broken"; }
        else { health = "healthy"; result = "deployed"; }
        Environments[env] = new EnvState(env, version, health);
        Record("legacy", env, version, scenario, result);
        return $"{{\"variant\":\"legacy\",\"env\":\"{env}\",\"version\":\"{version}\",\"scenario\":\"{scenario}\",\"result\":\"{result}\",\"health\":\"{health}\",\"note\":\"sin preflight ni rollback; ambiente queda como quede.\"}}";
    }

    private static string DeployControlled(string env, string version, string scenario)
    {
        Interlocked.Increment(ref _controlledDeploys);
        Environments.TryGetValue(env, out var before);
        before ??= new EnvState(env, "unknown", "unknown");
        if (scenario == "missing_artifact" || scenario == "secret_drift_detected")
        {
            Interlocked.Increment(ref _controlledBlocked);
            Record("controlled", env, version, scenario, "blocked_in_preflight");
            return $"{{\"variant\":\"controlled\",\"env\":\"{env}\",\"version\":\"{version}\",\"scenario\":\"{scenario}\",\"result\":\"blocked_in_preflight\",\"current_version\":\"{before.Version}\",\"note\":\"preflight bloqueo antes de tocar el ambiente.\"}}";
        }
        if (IsBadScenario(scenario))
        {
            Interlocked.Increment(ref _controlledRollbacks);
            Record("controlled", env, version, scenario, "rolled_back_to_" + before.Version);
            return $"{{\"variant\":\"controlled\",\"env\":\"{env}\",\"version\":\"{version}\",\"scenario\":\"{scenario}\",\"result\":\"rolled_back\",\"current_version\":\"{before.Version}\",\"note\":\"smoke fallo, rollback automatico al version anterior.\"}}";
        }
        Environments[env] = new EnvState(env, version, "healthy");
        Record("controlled", env, version, scenario, "promoted");
        return $"{{\"variant\":\"controlled\",\"env\":\"{env}\",\"version\":\"{version}\",\"scenario\":\"{scenario}\",\"result\":\"promoted\",\"health\":\"healthy\",\"note\":\"preflight ok + smoke ok → promote.\"}}";
    }

    private static bool IsBadScenario(string scenario) =>
        scenario == "secret_drift" || scenario == "breaking_change" || scenario == "schema_mismatch";

    private static void Record(string variant, string env, string version, string scenario, string result)
    {
        lock (DeploymentsLock)
        {
            Deployments.AddFirst(new Deployment(DateTime.UtcNow.ToString("o"), variant, env, version, scenario, result));
            while (Deployments.Count > MaxDeployments) Deployments.RemoveLast();
        }
    }

    private static string EnvironmentsJson()
    {
        var sb = new StringBuilder(512);
        sb.Append("{\"envs\":[");
        bool first = true;
        foreach (var s in Environments.Values)
        {
            if (!first) sb.Append(',');
            sb.Append("{\"name\":\"").Append(s.Name).Append("\",\"version\":\"").Append(s.Version).Append("\",\"health\":\"").Append(s.Health).Append("\"}");
            first = false;
        }
        sb.Append("]}");
        return sb.ToString();
    }

    private static string DeploymentsJson()
    {
        var sb = new StringBuilder(1024);
        sb.Append("{\"history\":[");
        lock (DeploymentsLock)
        {
            bool first = true;
            foreach (var d in Deployments)
            {
                if (!first) sb.Append(',');
                sb.Append("{\"at\":\"").Append(d.At).Append("\",\"variant\":\"").Append(d.Variant)
                  .Append("\",\"env\":\"").Append(d.Env).Append("\",\"version\":\"").Append(d.Version)
                  .Append("\",\"scenario\":\"").Append(d.Scenario).Append("\",\"result\":\"").Append(d.Result).Append("\"}");
                first = false;
            }
        }
        sb.Append("],\"max_kept\":").Append(MaxDeployments).Append('}');
        return sb.ToString();
    }

    private static string DiagnosticsJson() =>
        $"{{\"stack\":\"{Stack}\",\"case\":\"{CaseName}\"," +
        $"\"legacy\":{{\"deploys\":{Interlocked.Read(ref _legacyDeploys)},\"broken_state_left\":{Interlocked.Read(ref _legacyBroken)},\"behavior\":\"sin preflight, sin rollback\"}}," +
        $"\"controlled\":{{\"deploys\":{Interlocked.Read(ref _controlledDeploys)},\"blocked_in_preflight\":{Interlocked.Read(ref _controlledBlocked)},\"rollbacks\":{Interlocked.Read(ref _controlledRollbacks)},\"behavior\":\"preflight + smoke + rollback automatico\"}}," +
        $"\"environments\":{EnvironmentsJson()}}}";

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
