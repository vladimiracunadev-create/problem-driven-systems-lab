using System;
using System.Collections.Concurrent;
using System.Collections.Generic;
using System.Diagnostics;
using System.Globalization;
using System.Linq;
using System.Net;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using System.Threading;
using System.Threading.Tasks;

// Caso 18 — Arranque en frio y retraso del autoescalado — stack .NET 8.
//
// Frio: el autoescalador levanta instancias cuando el trafico ya subio. El
// proceso queda vivo al instante y /health responde 200 — pero la instancia no
// sirve nada hasta terminar de inicializar. El balanceador que mira liveness en
// vez de readiness manda trafico a ese hueco. Ahi nacen los 503.
//
// Templado: pool tibio ya inicializado y ya ejercitado, y balanceador que
// enruta por /ready.
//
// Que es real y que esta modelado:
//
//   La curva de calentamiento se MIDE, no se simula. El trabajo por peticion es
//   un lazo entero puro, identico en los siete stacks, sin delay de ninguna
//   clase. `p99_first_100_ms` contra `p99_after_1000_ms` es lo que RyuJIT hace
//   de verdad con el mismo codigo repetido.
//
//   La parte de I/O de la inicializacion (abrir el pool, DNS, TLS) es un
//   `Task.Delay` de io_ms: esperar a la red no quema CPU, y fijarlo hace
//   comparables a los siete stacks. La parte de CPU —construir la tabla— es
//   trabajo real.
//
// Primitiva .NET distintiva:
//
//   .NET tiene el mismo problema que Java —compilacion en capas: Tier 0 rapido
//   y sin optimizar, Tier 1 optimizado despues de ~30 llamados, con OSR para
//   los lazos largos— pero es el UNICO stack del laboratorio que trae la
//   respuesta EN LA CAJA:
//
//       <PublishReadyToRun>true</PublishReadyToRun>   precompila a nativo
//       <TieredPGO>true</TieredPGO>                   el perfil sobrevive
//       <PublishAot>true</PublishAot>                 AOT nativo, curva eliminada
//
//   Son tres lineas del .csproj, sin cambiar de distribucion ni de toolchain.
//   Java tiene mas herramientas y mas potentes —AppCDS, GraalVM native-image—
//   pero ninguna viene de fabrica. Esa diferencia, entre "existe" y "esta
//   puesto", es la que decide el orden entre los dos en este caso.
//
//   Lo que NO salva de esto: `Lazy<T>`, `SemaphoreSlim`, `ReaderWriterLockSlim`.
//   El costo no esta en la sincronizacion — esta en que el codigo todavia no
//   esta compilado.

internal static class Program
{
    private const string CaseName = "18 - Arranque en frio y retraso del autoescalado";
    private static readonly string Stack = Environment.GetEnvironmentVariable("APP_STACK") ?? ".NET 8";

    private const int WorkIters = 250_000;        // calibrado para ~0.3 ms caliente
    private const int InitTableRows = 2_000_000;  // parte de CPU de la init: trabajo real

    private static readonly Stopwatch Clock = Stopwatch.StartNew();
    private static double NowMs() => Clock.Elapsed.TotalMilliseconds;

    /// <summary>
    /// Trabajo por peticion: lazo entero puro, sin delay, sin I/O.
    /// Identico en los siete stacks. Lo que cambia es lo que el runtime hace con
    /// el mismo codigo repetido mil veces — que es lo que este caso mide.
    /// </summary>
    private static uint Work(int iters)
    {
        uint h = 2166136261u;
        for (int i = 0; i < iters; i++)
        {
            h = (h ^ (uint)i) * 16777619u;
        }
        return h;
    }

    /// <summary>Una instancia del servicio: viva apenas arranca, lista mucho despues.</summary>
    private sealed class Instance
    {
        public string Id = "";
        public bool Live = true;      // el proceso arranco: /health responde 200 YA
        public volatile bool Ready;   // todavia no: falta inicializar
        public double LiveAt;
        public double ReadyAt = -1;
        public long Served;
        public uint[]? Table;
    }

    private sealed class Slot
    {
        public long Runs, Served, Rejected, ColdStarts;
        public double MaxReadyAtMs;
    }

    private static readonly object FleetLock = new();
    private static List<Instance> _fleet = new();
    private static List<Instance> _warmPool = new();
    private static Dictionary<string, Slot> _metrics = NewMetrics();

    private static Dictionary<string, Slot> NewMetrics() =>
        new() { ["cold"] = new Slot(), ["warmed"] = new Slot() };

    private static double Round(double v, int d) => Math.Round(v, d, MidpointRounding.AwayFromZero);

    private static double Percentile(IReadOnlyList<double> values, double pct)
    {
        if (values.Count == 0) return 0;
        var sv = values.ToArray();
        Array.Sort(sv);
        var idx = (int)Math.Ceiling(pct / 100 * sv.Length) - 1;
        idx = Math.Max(0, Math.Min(sv.Length - 1, idx));
        return Round(sv[idx], 3);
    }

    private static async Task BootAsync(Instance inst, int ioMs)
    {
        // Parte de CPU: construir la tabla de configuracion. Trabajo de verdad,
        // y donde el Tier 0 compila por primera vez cada metodo que toca.
        var table = new uint[256];
        uint h = 2166136261u;
        for (int i = 0; i < InitTableRows; i++)
        {
            h = (h ^ (uint)i) * 16777619u;
            table[h & 0xFF] = h;
        }
        // Parte de I/O: abrir el pool, resolver DNS, negociar TLS.
        await Task.Delay(ioMs).ConfigureAwait(false);
        inst.Table = table;
        inst.ReadyAt = NowMs();
        inst.Ready = true;
    }

    private static double GapMs(Instance i) => Round((i.ReadyAt < 0 ? NowMs() : i.ReadyAt) - i.LiveAt, 2);

    // -----------------------------------------------------------------------
    // El pool tibio: instancias ya inicializadas Y ya ejercitadas
    // -----------------------------------------------------------------------

    private static async Task<JsonObject> BuildWarmPoolAsync(int instances, int ioMs, int prime, int iters)
    {
        var t0 = NowMs();
        var pool = Enumerable.Range(0, instances)
            .Select(i => new Instance { Id = $"warm-{i}", LiveAt = NowMs() })
            .ToList();
        await Task.WhenAll(pool.Select(p => BootAsync(p, ioMs))).ConfigureAwait(false);
        var initMs = NowMs() - t0;

        // Ejercitar: cruzar el umbral de Tier 1. Esta mitad es la que aplana la
        // curva en los runtimes con JIT, y .NET es uno de ellos.
        uint sink = 0;
        for (int i = 0; i < prime; i++) sink ^= Work(iters);
        if (sink == 42u) Console.Write("");   // impide que el JIT elimine el lazo
        foreach (var p in pool) Interlocked.Add(ref p.Served, prime / Math.Max(1, instances));

        lock (FleetLock) { _warmPool = pool; }

        return new JsonObject
        {
            ["warm_pool_size"] = pool.Count,
            ["init_ms"] = Round(initMs, 2),
            ["prime_requests"] = prime,
            ["warmup_duration_ms"] = Round(NowMs() - t0, 2),
        };
    }

    // -----------------------------------------------------------------------
    // El balanceador: la diferencia entre mirar /health y mirar /ready
    // -----------------------------------------------------------------------

    private static Instance? Pick(List<Instance> pool, bool byReadiness, int counter)
    {
        var n = pool.Count;
        for (int k = 0; k < n; k++)
        {
            var inst = pool[((counter + k) % n + n) % n];
            if (byReadiness ? inst.Ready : inst.Live) return inst;
        }
        return null;
    }

    private static async Task<JsonObject> RunScenarioAsync(string variant, int requests, int instances,
        int clients, int ioMs, int paceMs, int iters, int prime)
    {
        JsonObject? warmInfo = null;
        bool byReadiness;
        int coldStarts;
        Task? boots = null;
        List<Instance> local;

        if (variant == "cold")
        {
            // El autoescalador reacciona tarde: las instancias arrancan CON el
            // trafico encima, no antes.
            local = Enumerable.Range(0, instances)
                .Select(i => new Instance { Id = $"cold-{i}", LiveAt = NowMs() })
                .ToList();
            boots = Task.WhenAll(local.Select(p => BootAsync(p, ioMs)));
            byReadiness = false;   // el balanceador ingenuo mira /health
            coldStarts = instances;
        }
        else
        {
            bool havePool;
            lock (FleetLock) { havePool = _warmPool.Count >= instances; }
            if (!havePool) warmInfo = await BuildWarmPoolAsync(instances, ioMs, prime, iters).ConfigureAwait(false);
            lock (FleetLock) { local = _warmPool.Take(instances).ToList(); }
            byReadiness = true;    // el balanceador correcto mira /ready
            coldStarts = 0;
        }

        lock (FleetLock) { _fleet = local; }

        var ordered = new ConcurrentQueue<double>();
        long served = 0, rejected = 0;
        var t0 = NowMs();

        var workers = Enumerable.Range(0, clients).Select(idx => Task.Run(async () =>
        {
            var mine = requests / clients + (idx < requests % clients ? 1 : 0);
            for (int k = 0; k < mine; k++)
            {
                var inst = Pick(local, byReadiness, idx + k);
                var st = NowMs();
                if (inst is null || !inst.Ready)
                {
                    // El proceso esta vivo, el healthcheck da verde, y la
                    // peticion se cae igual. Nada dispara una alerta.
                    Interlocked.Increment(ref rejected);
                }
                else
                {
                    Work(iters);
                    Interlocked.Increment(ref inst.Served);
                    ordered.Enqueue(NowMs() - st);
                    Interlocked.Increment(ref served);
                }
                if (paceMs > 0) await Task.Delay(paceMs).ConfigureAwait(false);
            }
        })).ToArray();

        await Task.WhenAll(workers).ConfigureAwait(false);
        if (boots is not null) await boots.ConfigureAwait(false);
        var wall = NowMs() - t0;

        var snapshot = ordered.ToList();
        var first100 = snapshot.Take(100).ToList();
        var after1000 = snapshot.Count > 1000
            ? snapshot.Skip(1000).ToList()
            : snapshot.Count > 100 ? snapshot.Skip(snapshot.Count - 100).ToList() : snapshot;

        var p99First = Percentile(first100, 99);
        var p99After = Percentile(after1000, 99);
        var readyAt = local.Count > 0 ? local.Max(GapMs) : 0;

        int warmSize;
        lock (FleetLock)
        {
            var s = _metrics[variant];
            s.Runs++;
            s.Served += served;
            s.Rejected += rejected;
            s.ColdStarts += coldStarts;
            s.MaxReadyAtMs = Math.Max(s.MaxReadyAtMs, readyAt);
            warmSize = _warmPool.Count;
        }

        var payload = new JsonObject
        {
            ["variant"] = variant,
            ["instances"] = instances,
            ["requests"] = requests,
            ["clients"] = clients,
            ["lb_routes_by"] = byReadiness ? "readiness (/ready)" : "liveness (/health)",
            ["cold_start_count"] = coldStarts,
            ["warm_pool_size"] = warmSize,
            ["ready_at_ms"] = Round(readyAt, 2),
            ["health_vs_ready_gap_ms"] = coldStarts > 0 ? Round(readyAt, 2) : 0.0,
            ["first_response_ms"] = snapshot.Count > 0 ? Round(snapshot[0], 3) : 0.0,
            ["p99_first_100_ms"] = p99First,
            ["p99_after_1000_ms"] = p99After,
            ["warmup_speedup_x"] = p99After > 0 ? Round(p99First / p99After, 2) : 1.0,
            ["p50_ms"] = Percentile(snapshot, 50),
            ["served"] = served,
            ["rejected_cold_start"] = rejected,
            ["availability_pct"] = Round(served * 100.0 / Math.Max(1, served + rejected), 2),
            ["work_iters"] = iters,
            ["io_ms"] = ioMs,
            ["pace_ms"] = paceMs,
            ["wall_ms"] = Round(wall, 2),
        };
        if (warmInfo is not null) payload["warm_pool_built_now"] = warmInfo;
        payload["note"] = variant == "cold"
            ? "El proceso esta vivo desde el milisegundo cero y /health lo confirma, pero la instancia no sirve "
              + "nada hasta terminar de inicializar. El balanceador que enruta por liveness manda trafico a ese "
              + "hueco: los 503 salen de una instancia que ninguna alerta considera caida."
            : "El pool ya estaba inicializado y ya ejercitado, y el balanceador enruta por readiness. Ninguna "
              + "peticion cae en una instancia a medio levantar: 0 rechazos y la latencia parte donde la otra "
              + "variante recien termina.";
        payload["dotnet_note"] = ".NET compila en capas igual que Java (Tier 0 rapido, Tier 1 optimizado, OSR para "
            + "los lazos largos), pero es el unico stack que trae la respuesta en la caja: PublishReadyToRun, "
            + "TieredPGO y PublishAot son tres lineas del .csproj, sin cambiar de distribucion.";
        return payload;
    }

    private static JsonObject ReadyState()
    {
        List<Instance> local;
        int warmSize;
        lock (FleetLock) { local = new List<Instance>(_fleet); warmSize = _warmPool.Count; }

        var items = new JsonArray();
        var allReady = local.Count > 0;
        foreach (var i in local)
        {
            if (!i.Ready) allReady = false;
            items.Add(new JsonObject
            {
                ["id"] = i.Id,
                ["live"] = i.Live,
                ["ready"] = i.Ready,
                ["ready_at_ms"] = GapMs(i),
                ["requests_served"] = Interlocked.Read(ref i.Served),
            });
        }
        return new JsonObject
        {
            ["ready"] = allReady,
            ["instances"] = items,
            ["warm_pool_size"] = warmSize,
            ["note"] = "`/health` responde 200 apenas el proceso arranca. `/ready` responde 200 recien cuando la "
                     + "instancia puede servir. Si el balanceador mira la primera en vez de la segunda, el hueco "
                     + "entre las dos es tiempo de caida que nadie registra como caida.",
        };
    }

    private static JsonObject Diagnostics()
    {
        var variants = new JsonObject();
        lock (FleetLock)
        {
            foreach (var name in new[] { "cold", "warmed" })
            {
                var s = _metrics[name];
                variants[name] = new JsonObject
                {
                    ["runs"] = s.Runs,
                    ["served"] = s.Served,
                    ["rejected_cold_start"] = s.Rejected,
                    ["cold_starts"] = s.ColdStarts,
                    ["max_ready_at_ms"] = Round(s.MaxReadyAtMs, 2),
                };
            }
        }
        return new JsonObject
        {
            ["stack"] = Stack,
            ["case"] = CaseName,
            ["variants"] = variants,
            ["fleet"] = ReadyState(),
            ["fidelity"] = new JsonObject
            {
                ["medido"] = "La curva de calentamiento. El trabajo por peticion es un lazo entero puro sin delay, "
                           + "identico en los 7 stacks; p99_first_100_ms vs p99_after_1000_ms es lo que RyuJIT "
                           + "hace de verdad.",
                ["modelado"] = "La parte de I/O de la inicializacion (abrir pool, DNS, TLS) es un Task.Delay de "
                             + "io_ms: esperar a la red no quema CPU, y fijarlo hace comparables a los 7 stacks.",
                ["real"] = "La parte de CPU de la inicializacion recorre 2.000.000 de iteraciones. Eso si es trabajo.",
            },
            ["interpretation"] = new JsonObject
            {
                ["cold"] = "rejected_cold_start > 0 con el proceso vivo todo el tiempo. health_vs_ready_gap_ms es "
                         + "la ventana exacta en la que el balanceador mando trafico a una instancia que no podia "
                         + "servirlo.",
                ["warmed"] = "rejected_cold_start = 0. El pool ya estaba, y el balanceador enruta por readiness.",
                ["dotnet_note"] = "La diferencia con Java no esta en tener herramientas: esta en que las de .NET "
                                + "vienen de fabrica. ReadyToRun se activa con una linea del .csproj; AppCDS y "
                                + "GraalVM piden cambiar el pipeline de build.",
            },
        };
    }

    // -----------------------------------------------------------------------
    // HTTP
    // -----------------------------------------------------------------------

    private static int Clamp(int v, int lo, int hi) => Math.Max(lo, Math.Min(hi, v));

    private static int ParseInt(Dictionary<string, string> q, string key, int fallback) =>
        q.TryGetValue(key, out var raw) && int.TryParse(raw, out var v) ? v : fallback;

    private static Dictionary<string, string> QueryParams(string? raw)
    {
        var d = new Dictionary<string, string>();
        if (string.IsNullOrEmpty(raw)) return d;
        if (raw.StartsWith('?')) raw = raw[1..];
        foreach (var pair in raw.Split('&', StringSplitOptions.RemoveEmptyEntries))
        {
            var parts = pair.Split('=', 2);
            d[WebUtility.UrlDecode(parts[0]) ?? ""] = parts.Length > 1 ? WebUtility.UrlDecode(parts[1]) ?? "" : "";
        }
        return d;
    }

    private static void SendJson(HttpListenerContext ctx, int status, JsonObject payload)
    {
        try
        {
            payload["timestamp_utc"] = DateTime.UtcNow.ToString("yyyy-MM-ddTHH:mm:ssZ", CultureInfo.InvariantCulture);
            payload["pid"] = Environment.ProcessId;
            var bytes = Encoding.UTF8.GetBytes(
                payload.ToJsonString(new JsonSerializerOptions { WriteIndented = true }));
            ctx.Response.StatusCode = status;
            ctx.Response.ContentType = "application/json; charset=utf-8";
            ctx.Response.ContentLength64 = bytes.Length;
            ctx.Response.OutputStream.Write(bytes, 0, bytes.Length);
        }
        catch { }
        finally { try { ctx.Response.OutputStream.Close(); } catch { } }
    }

    private static async Task HandleAsync(HttpListenerContext ctx)
    {
        var uri = ctx.Request.Url?.AbsolutePath ?? "/";
        var q = QueryParams(ctx.Request.Url?.Query);

        var requests = Clamp(ParseInt(q, "requests", 2400), 100, 20000);
        var instances = Clamp(ParseInt(q, "instances", 3), 1, 32);
        var clients = Clamp(ParseInt(q, "clients", 8), 1, 64);
        var ioMs = Clamp(ParseInt(q, "io_ms", 150), 0, 5000);
        var paceMs = Clamp(ParseInt(q, "pace_ms", 1), 0, 100);
        var iters = Clamp(ParseInt(q, "work_iters", WorkIters), 100, 5_000_000);
        var prime = Clamp(ParseInt(q, "prime", 1500), 0, 100_000);

        var status = 200;
        JsonObject payload;

        switch (uri)
        {
            case "/":
            case "/index":
                payload = new JsonObject
                {
                    ["lab"] = "Problem-Driven Systems Lab",
                    ["case"] = CaseName,
                    ["stack"] = Stack,
                    ["goal"] = "Mostrar que el hueco entre 'el proceso esta vivo' y 'la instancia puede servir' es "
                             + "tiempo de caida real que ningun healthcheck registra como caida.",
                    ["dotnet_specific"] = "Compilacion en capas como Java, pero con la respuesta en la caja: "
                                        + "PublishReadyToRun, TieredPGO y PublishAot son tres lineas del .csproj.",
                    ["routes"] = new JsonObject
                    {
                        ["/health"] = "Liveness: responde 200 apenas el proceso arranca.",
                        ["/ready"] = "Readiness: responde 200 recien cuando la instancia puede servir.",
                        ["/boot-cold?requests=2400&instances=3"] = "Instancias frias con el trafico ya encima.",
                        ["/boot-warmed?requests=2400&instances=3"] = "Pool tibio y balanceador que mira readiness.",
                        ["/warmup?instances=3&prime=1500"] = "Construye el pool tibio antes del trafico.",
                        ["/diagnostics/summary"] = "Comparativa entre variantes.",
                        ["/reset-lab"] = "Vacia la flota, el pool tibio y las metricas.",
                    },
                };
                break;
            case "/health":
                payload = new JsonObject
                {
                    ["status"] = "ok",
                    ["stack"] = Stack,
                    ["case"] = CaseName,
                    ["note"] = "Liveness. Esto responde 200 aunque la instancia no pueda servir una sola peticion.",
                };
                break;
            case "/ready":
                payload = ReadyState();
                break;
            case "/boot-cold":
                payload = await RunScenarioAsync("cold", requests, instances, clients, ioMs, paceMs, iters, prime)
                    .ConfigureAwait(false);
                break;
            case "/boot-warmed":
                payload = await RunScenarioAsync("warmed", requests, instances, clients, ioMs, paceMs, iters, prime)
                    .ConfigureAwait(false);
                break;
            case "/warmup":
                payload = await BuildWarmPoolAsync(instances, ioMs, prime, iters).ConfigureAwait(false);
                payload["status"] = "warm";
                payload["note"] = "Inicializar deja la instancia lista. Ejercitarla deja al runtime listo. Las dos "
                                + "mitades hacen falta, y solo la segunda depende del lenguaje.";
                break;
            case "/diagnostics/summary":
                payload = Diagnostics();
                break;
            case "/reset-lab":
                lock (FleetLock)
                {
                    _fleet = new List<Instance>();
                    _warmPool = new List<Instance>();
                    _metrics = NewMetrics();
                }
                payload = new JsonObject
                {
                    ["status"] = "reset",
                    ["message"] = "Flota, pool tibio y metricas reiniciados.",
                };
                break;
            default:
                status = 404;
                payload = new JsonObject { ["error"] = "Ruta no encontrada", ["path"] = uri };
                break;
        }

        SendJson(ctx, status, payload);
    }

    private static async Task Main()
    {
        var port = Environment.GetEnvironmentVariable("PORT") ?? "8080";
        var listener = new HttpListener();
        listener.Prefixes.Add($"http://*:{port}/");
        listener.Start();
        Console.WriteLine($"Servidor .NET escuchando en {port}");

        while (true)
        {
            var ctx = await listener.GetContextAsync().ConfigureAwait(false);
            _ = Task.Run(() => HandleAsync(ctx));
        }
    }
}
