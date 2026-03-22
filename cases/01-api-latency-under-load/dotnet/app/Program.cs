using System.Diagnostics;
using System.Text.Json;

var metrics = new MetricsStore(3000);
var builder = WebApplication.CreateBuilder(args);
var app = builder.Build();

string PayloadOfKb(int kb) => new string('x', Math.Max(0, kb) * 1024);
long CpuWork(int iterations)
{
    long value = 0;
    for (var i = 0; i < iterations; i++)
    {
        value += i % 13;
    }
    return value;
}

IResult BuildResponse(string path, Func<object> factory, Stopwatch sw, int statusCode = 200, bool record = true)
{
    var body = factory();
    sw.Stop();
    var elapsedMs = Math.Round(sw.Elapsed.TotalMilliseconds, 2);
    if (record)
    {
        metrics.Record(path, statusCode, elapsedMs);
    }

    var merged = JsonSerializer.Deserialize<Dictionary<string, object?>>(JsonSerializer.Serialize(body))!;
    merged["elapsed_ms"] = elapsedMs;
    merged["pid"] = Environment.ProcessId;
    merged["timestamp_utc"] = DateTimeOffset.UtcNow;
    return Results.Json(merged, statusCode: statusCode);
}

app.MapGet("/", () =>
{
    var sw = Stopwatch.StartNew();
    return BuildResponse("/", () => new
    {
        lab = "Problem-Driven Systems Lab",
        @case = "01 - API lenta bajo carga",
        stack = ".NET 8",
        goal = "Simular endpoints rápidos y lentos para estudiar latencia, percentiles y comportamiento bajo carga.",
        recommended_flow = new[]
        {
            "Levantar un solo stack primero para entender el caso.",
            "Usar compose.compare.yml solo cuando quieras comparar comportamientos.",
            "Medir con /metrics antes y después de generar carga."
        },
        routes = new Dictionary<string, string>
        {
            ["/"] = "Resumen del caso y rutas disponibles.",
            ["/health"] = "Chequeo simple.",
            ["/fast"] = "Respuesta rápida y liviana.",
            ["/slow?delay_ms=200&payload_kb=4"] = "Simula latencia I/O y payload mayor.",
            ["/cpu?iterations=3500000"] = "Simula trabajo CPU-bound.",
            ["/mixed?delay_ms=120&iterations=1500000&payload_kb=8"] = "Combina espera, CPU y payload.",
            ["/metrics"] = "Métricas acumuladas en memoria.",
            ["/reset-metrics"] = "Reinicia contadores del caso."
        }
    }, sw);
});

app.MapGet("/health", () =>
{
    var sw = Stopwatch.StartNew();
    return BuildResponse("/health", () => new { status = "ok", stack = ".NET 8", @case = "01 - API lenta bajo carga" }, sw);
});

app.MapGet("/fast", () =>
{
    var sw = Stopwatch.StartNew();
    return BuildResponse("/fast", () => new { endpoint = "fast", message = "Respuesta ligera diseñada para contrastar con rutas lentas." }, sw);
});

app.MapGet("/slow", async (int? delay_ms, int? payload_kb) =>
{
    var sw = Stopwatch.StartNew();
    var delayMs = Math.Clamp(delay_ms ?? 250, 0, 60000);
    var payloadKb = Math.Clamp(payload_kb ?? 8, 0, 256);
    await Task.Delay(delayMs);
    return BuildResponse("/slow", () => new
    {
        endpoint = "slow",
        delay_ms = delayMs,
        payload_kb = payloadKb,
        message = "Esta ruta simula espera de red, I/O o dependencia externa.",
        payload = PayloadOfKb(payloadKb)
    }, sw);
});

app.MapGet("/cpu", (int? iterations) =>
{
    var sw = Stopwatch.StartNew();
    var value = Math.Clamp(iterations ?? 3500000, 1, 20000000);
    return BuildResponse("/cpu", () => new
    {
        endpoint = "cpu",
        iterations = value,
        checksum = CpuWork(value),
        message = "Esta ruta simula presión de CPU en una ruta crítica."
    }, sw);
});

app.MapGet("/mixed", async (int? delay_ms, int? iterations, int? payload_kb) =>
{
    var sw = Stopwatch.StartNew();
    var delayMs = Math.Clamp(delay_ms ?? 120, 0, 60000);
    var iter = Math.Clamp(iterations ?? 1500000, 1, 20000000);
    var payloadKb = Math.Clamp(payload_kb ?? 12, 0, 256);
    await Task.Delay(delayMs);
    return BuildResponse("/mixed", () => new
    {
        endpoint = "mixed",
        delay_ms = delayMs,
        iterations = iter,
        checksum = CpuWork(iter),
        payload_kb = payloadKb,
        message = "Mezcla espera, trabajo CPU y payload para emular una ruta más realista.",
        payload = PayloadOfKb(payloadKb)
    }, sw);
});

app.MapGet("/metrics", () =>
{
    var sw = Stopwatch.StartNew();
    return BuildResponse("/metrics", () => metrics.Snapshot(".NET 8"), sw, record: false);
});

app.MapGet("/reset-metrics", () =>
{
    var sw = Stopwatch.StartNew();
    metrics.Reset();
    return BuildResponse("/reset-metrics", () => new { status = "reset", message = "Métricas reiniciadas para el stack .NET 8." }, sw, record: false);
});

app.Run();

internal sealed class MetricsStore
{
    private readonly int _maxSamples;
    private readonly List<double> _samples = new();
    private readonly object _lock = new();
    private int _requests;
    private string? _lastPath;
    private int _lastStatus = 200;
    private DateTimeOffset? _lastUpdated;

    public MetricsStore(int maxSamples)
    {
        _maxSamples = maxSamples;
    }

    public void Record(string path, int status, double elapsedMs)
    {
        lock (_lock)
        {
            _requests++;
            _samples.Add(elapsedMs);
            if (_samples.Count > _maxSamples)
            {
                _samples.RemoveAt(0);
            }
            _lastPath = path;
            _lastStatus = status;
            _lastUpdated = DateTimeOffset.UtcNow;
        }
    }

    public void Reset()
    {
        lock (_lock)
        {
            _requests = 0;
            _samples.Clear();
            _lastPath = null;
            _lastStatus = 200;
            _lastUpdated = DateTimeOffset.UtcNow;
        }
    }

    public object Snapshot(string stack)
    {
        lock (_lock)
        {
            var ordered = _samples.OrderBy(x => x).ToList();
            var avg = _samples.Count > 0 ? Math.Round(_samples.Average(), 2) : 0.0;
            double Percentile(int percent)
            {
                if (ordered.Count == 0) return 0.0;
                var index = Math.Clamp((int)Math.Ceiling((percent / 100.0) * ordered.Count) - 1, 0, ordered.Count - 1);
                return Math.Round(ordered[index], 2);
            }

            return new
            {
                stack,
                @case = "01 - API lenta bajo carga",
                requests_tracked = _requests,
                sample_count = _samples.Count,
                avg_ms = avg,
                p95_ms = Percentile(95),
                p99_ms = Percentile(99),
                last_path = _lastPath,
                last_status = _lastStatus,
                last_updated = _lastUpdated,
                note = "Métrica simple, en proceso único, pensada para laboratorio. No reemplaza observabilidad real."
            };
        }
    }
}
