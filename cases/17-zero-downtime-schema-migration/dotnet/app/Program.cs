using System;
using System.Collections.Concurrent;
using System.Collections.Generic;
using System.Linq;
using System.Net;
using System.Text;
using System.Threading;
using System.Threading.Tasks;

// Caso 17 — Migracion de esquema sin downtime — stack .NET 8.
//
// Blocking: un `ALTER TABLE` toma el lock exclusivo durante toda la migracion.
// Expand-contract: cuatro fases, y el lock se toma y se suelta en cada lote.
//
// Primitiva .NET distintiva:
//   `ReaderWriterLockSlim`, con el timeout como parametro del metodo:
//
//       if (rwLock.TryEnterReadLock(120)) { ... }   // devuelve false, no lanza
//
//   Igual que en el caso 14 con `SemaphoreSlim.WaitAsync`, el deadline es un
//   valor de retorno y no una excepcion. Eso hace que "no pude leer" sea un
//   camino del codigo y no un catch — que es exactamente la distincion que el
//   handler necesita para devolver 503 en vez de 500.
//
//   El detalle que este stack aporta y ningun otro tiene tan a la vista:
//   **`ReaderWriterLockSlim` es `IDisposable`**. Un read-write lock con recursos
//   nativos que hay que liberar, en un runtime con recoleccion de basura. Es un
//   recordatorio de que el GC no administra todo, y conecta directo con el
//   [caso 14](../../14-connection-pool-exhaustion/dotnet/README.md): el `using`
//   no es azucar, es la unica garantia de que algo se suelta.
//
//   Sobre la equidad: `ReaderWriterLockSlim` **no** es justo y no tiene modo
//   justo. La documentacion lo dice: favorece a los lectores. Con trafico de
//   lectura constante, el escritor puede esperar mucho — el problema que Java
//   resuelve con `new ReentrantReadWriteLock(true)` y Python con una bandera.
//
// El tiempo de migracion es un `Task.Delay`: un ALTER TABLE se demora esperando
// I/O del motor, no quemando CPU del proceso de la app.

internal static class Program
{
    private const string CaseName = "17 - Migracion de esquema sin downtime";
    private static readonly string Stack = Environment.GetEnvironmentVariable("APP_STACK") ?? ".NET 8";

    private const int ReadTimeoutMs = 120;

    // IDisposable: un lock con recursos nativos, en un runtime con GC.
    private static readonly ReaderWriterLockSlim RwLock = new(LockRecursionPolicy.NoRecursion);

    private static int _rows;
    private static bool _hasNewColumn, _oldColumnDropped, _readFromNewColumn;
    private static int _backfilled;
    private static string _phase = "idle";
    private static readonly object StateLock = new();

    private sealed class Slot
    {
        public long Runs, ReadersServed, ReadersFailed, BackfillBatches;
        public double LockHeldMs, MaxReadWaitMs;
    }

    private static ConcurrentDictionary<string, Slot> _metrics = Fresh();

    private static ConcurrentDictionary<string, Slot> Fresh()
    {
        var d = new ConcurrentDictionary<string, Slot>();
        d["blocking"] = new Slot();
        d["expand_contract"] = new Slot();
        return d;
    }

    private static void ResetTable(int rows)
    {
        lock (StateLock)
        {
            _rows = rows;
            _hasNewColumn = false;
            _backfilled = 0;
            _oldColumnDropped = false;
            _readFromNewColumn = false;
            _phase = "idle";
        }
    }

    private static void SetPhase(string p) { lock (StateLock) _phase = p; }

    private static double Ms(long ticks) =>
        (System.Diagnostics.Stopwatch.GetTimestamp() - ticks) * 1000.0 / System.Diagnostics.Stopwatch.Frequency;

    private static long Now() => System.Diagnostics.Stopwatch.GetTimestamp();

    private sealed class ReaderResult
    {
        public long Served, Failed;
        public readonly List<double> Waits = new();
    }

    /// Trafico normal que corre mientras la migracion pasa.
    private static ReaderResult Reader(Barrier gate, long stopAtTicks)
    {
        gate.SignalAndWait();
        var res = new ReaderResult();
        while (Now() < stopAtTicks)
        {
            var t0 = Now();
            // El deadline es un valor de retorno, no una excepcion: "no pude
            // leer" es un camino del codigo, no un catch.
            var got = RwLock.TryEnterReadLock(ReadTimeoutMs);
            res.Waits.Add(Ms(t0));
            if (got)
            {
                try { lock (StateLock) { _ = _rows; } }
                finally { RwLock.ExitReadLock(); }
                res.Served++;
            }
            else
            {
                res.Failed++;
            }
            Thread.Sleep(2);
        }
        return res;
    }

    // ------------------------------------------------------------------
    // Variante blocking
    // ------------------------------------------------------------------

    private static (double held, long batches) MigrateBlocking(int rows, int msPer1k)
    {
        ResetTable(rows);
        SetPhase("expand");
        var durationMs = rows / 1000.0 * msPer1k;

        var t0 = Now();
        // El lock exclusivo se toma UNA vez y se suelta al final.
        RwLock.EnterWriteLock();
        try
        {
            Thread.Sleep((int)durationMs);
            lock (StateLock)
            {
                _hasNewColumn = true;
                _backfilled = rows;
                _oldColumnDropped = true;
                _readFromNewColumn = true;
            }
        }
        finally { RwLock.ExitWriteLock(); }
        var held = Ms(t0);
        SetPhase("done");
        return (held, 1);
    }

    // ------------------------------------------------------------------
    // Variante expand-contract
    // ------------------------------------------------------------------

    private static (double held, long batches) MigrateExpandContract(int rows, int msPer1k, int batchSize, int pauseMs)
    {
        ResetTable(rows);
        var totalMs = rows / 1000.0 * msPer1k;
        double held = 0;
        long batches = 0;

        // 1. EXPAND — columna nullable: metadata, instantaneo.
        SetPhase("expand");
        var t0 = Now();
        RwLock.EnterWriteLock();
        try { lock (StateLock) _hasNewColumn = true; }
        finally { RwLock.ExitWriteLock(); }
        held += Ms(t0);

        // 2. BACKFILL — por lotes, soltando el lock entre cada uno.
        SetPhase("backfill");
        var done = 0;
        var perBatchMs = totalMs * (batchSize / (double)Math.Max(1, rows));
        while (done < rows)
        {
            var chunk = Math.Min(batchSize, rows - done);
            t0 = Now();
            RwLock.EnterWriteLock();
            try
            {
                Thread.Sleep((int)Math.Max(1, perBatchMs));
                lock (StateLock) _backfilled += chunk;
            }
            finally { RwLock.ExitWriteLock(); }
            held += Ms(t0);
            done += chunk;
            batches++;
            // La pausa entre lotes es lo que le devuelve el motor a la app.
            if (pauseMs > 0) Thread.Sleep(pauseMs);
        }

        // 3. SWITCH — feature flag. No toca datos: reversible en un segundo.
        SetPhase("switch");
        lock (StateLock) _readFromNewColumn = true;

        // 4. CONTRACT — recien ahora se borra la vieja.
        SetPhase("contract");
        t0 = Now();
        RwLock.EnterWriteLock();
        try { lock (StateLock) _oldColumnDropped = true; }
        finally { RwLock.ExitWriteLock(); }
        held += Ms(t0);
        SetPhase("done");
        return (held, batches);
    }

    // ------------------------------------------------------------------
    // Orquestacion
    // ------------------------------------------------------------------

    private static string RunMigration(string variant, int rows, int readers, int msPer1k, int batchSize, int pauseMs)
    {
        var budgetMs = rows / 1000.0 * msPer1k + rows / (double)Math.Max(1, batchSize) * pauseMs + 400;
        var stopAt = Now() + (long)(budgetMs / 1000.0 * System.Diagnostics.Stopwatch.Frequency);
        using var gate = new Barrier(readers + 1);

        var tasks = Enumerable.Range(0, readers)
            .Select(_ => Task.Factory.StartNew(() => Reader(gate, stopAt), TaskCreationOptions.LongRunning))
            .ToArray();

        var started = Now();
        gate.SignalAndWait();
        var (held, batches) = variant == "blocking"
            ? MigrateBlocking(rows, msPer1k)
            : MigrateExpandContract(rows, msPer1k, batchSize, pauseMs);
        var migrationMs = Ms(started);

        Task.WaitAll(tasks.Cast<Task>().ToArray());
        var wallMs = Ms(started);

        long served = 0, failed = 0;
        var waits = new List<double>();
        foreach (var t in tasks)
        {
            served += t.Result.Served;
            failed += t.Result.Failed;
            waits.AddRange(t.Result.Waits);
        }
        waits.Sort();
        var maxWait = waits.Count > 0 ? waits[^1] : 0;

        var s = _metrics[variant];
        Interlocked.Increment(ref s.Runs);
        Interlocked.Add(ref s.ReadersServed, served);
        Interlocked.Add(ref s.ReadersFailed, failed);
        Interlocked.Add(ref s.BackfillBatches, batches);
        s.LockHeldMs += held;
        if (maxWait > s.MaxReadWaitMs) s.MaxReadWaitMs = maxWait;

        int backfilled, rowsTotal;
        string phase;
        lock (StateLock) { backfilled = _backfilled; rowsTotal = _rows; phase = _phase; }

        var note = variant == "blocking"
            ? "Un solo lock exclusivo tomado durante toda la migracion: los lectores esperan lo que dure, y los que tienen timeout fallan. Es el ALTER TABLE que devuelve 503 durante veinte minutos."
            : "Expand, backfill por lotes con pausa, switch por feature flag y contract. El lock se toma y se suelta en cada lote, asi que ningun lector espera mas que un lote.";

        return "{\"variant\":\"" + variant + "\",\"rows_total\":" + rowsTotal
             + ",\"readers\":" + readers
             + ",\"phase\":\"" + phase + "\""
             + ",\"lock_held_ms\":" + Num(held)
             + ",\"longest_single_lock_ms\":" + Num(variant == "blocking" ? held : held / Math.Max(1, batches))
             + ",\"readers_served\":" + served
             + ",\"readers_failed\":" + failed
             + ",\"availability_pct\":" + Num(served * 100.0 / Math.Max(1, served + failed))
             + ",\"p99_read_wait_ms\":" + Num(Percentile(waits, 99))
             + ",\"max_read_wait_ms\":" + Num(maxWait)
             + ",\"read_timeout_ms\":" + ReadTimeoutMs
             + ",\"backfill_batches\":" + batches
             + ",\"backfill_progress_pct\":" + Num(backfilled * 100.0 / Math.Max(1, rowsTotal))
             + ",\"migration_ms\":" + Num(migrationMs)
             + ",\"wall_ms\":" + Num(wallMs)
             + ",\"note\":\"" + note + "\"}";
    }

    private static double Percentile(List<double> sorted, int pct)
    {
        if (sorted.Count == 0) return 0;
        var idx = (int)Math.Ceiling(pct / 100.0 * sorted.Count) - 1;
        return sorted[Math.Max(0, Math.Min(sorted.Count - 1, idx))];
    }

    private static string Num(double v) =>
        Math.Round(v, 2).ToString(System.Globalization.CultureInfo.InvariantCulture);

    // ------------------------------------------------------------------
    // Rutas
    // ------------------------------------------------------------------

    private static string MigrationStateJson()
    {
        lock (StateLock)
        {
            return "{\"phase\":\"" + _phase + "\""
                 + ",\"phases\":[\"idle\",\"expand\",\"backfill\",\"switch\",\"contract\",\"done\"]"
                 + ",\"rows_total\":" + _rows
                 + ",\"has_new_column\":" + (_hasNewColumn ? "true" : "false")
                 + ",\"backfilled\":" + _backfilled
                 + ",\"backfill_progress_pct\":" + Num(_backfilled * 100.0 / Math.Max(1, _rows))
                 + ",\"old_column_dropped\":" + (_oldColumnDropped ? "true" : "false")
                 + ",\"read_from_new_column\":" + (_readFromNewColumn ? "true" : "false")
                 + ",\"read_timeout_ms\":" + ReadTimeoutMs
                 + ",\"fair_lock\":false"
                 + ",\"note\":\"ReaderWriterLockSlim favorece a los lectores y no tiene modo justo: con trafico de lectura constante el escritor puede esperar mucho.\"}";
        }
    }

    private static string BackfillStepJson(int batchSize, int msPer1k)
    {
        int rows, done;
        bool hasCol;
        lock (StateLock) { rows = _rows; done = _backfilled; hasCol = _hasNewColumn; }
        if (!hasCol) return "{\"status\":\"skipped\",\"reason\":\"la columna nueva todavia no existe: falta la fase expand\"}";
        if (done >= rows) return "{\"status\":\"complete\",\"backfilled\":" + done + ",\"rows_total\":" + rows + "}";

        var chunk = Math.Min(batchSize, rows - done);
        var t0 = Now();
        RwLock.EnterWriteLock();
        try
        {
            Thread.Sleep((int)Math.Max(1, rows / 1000.0 * msPer1k * (chunk / (double)Math.Max(1, rows))));
            lock (StateLock) { _backfilled += chunk; done = _backfilled; }
        }
        finally { RwLock.ExitWriteLock(); }

        return "{\"status\":\"batch_done\",\"batch_size\":" + chunk
             + ",\"lock_held_ms\":" + Num(Ms(t0))
             + ",\"backfilled\":" + done + ",\"rows_total\":" + rows
             + ",\"backfill_progress_pct\":" + Num(done * 100.0 / Math.Max(1, rows)) + "}";
    }

    private static string VariantJson(string name)
    {
        var s = _metrics[name];
        return "\"" + name + "\":{\"runs\":" + Interlocked.Read(ref s.Runs)
             + ",\"lock_held_ms\":" + Num(s.LockHeldMs)
             + ",\"readers_served\":" + Interlocked.Read(ref s.ReadersServed)
             + ",\"readers_failed\":" + Interlocked.Read(ref s.ReadersFailed)
             + ",\"max_read_wait_ms\":" + Num(s.MaxReadWaitMs)
             + ",\"backfill_batches\":" + Interlocked.Read(ref s.BackfillBatches) + "}";
    }

    private static string DiagnosticsJson() =>
        "{\"stack\":\"" + Stack + "\",\"case\":\"" + CaseName + "\",\"variants\":{"
        + VariantJson("blocking") + "," + VariantJson("expand_contract") + "}"
        + ",\"migration\":" + MigrationStateJson()
        + ",\"interpretation\":{"
        + "\"blocking\":\"readers_failed > 0 y max_read_wait_ms = la duracion entera de la migracion: la app estuvo caida todo ese tiempo aunque el proceso siguiera vivo.\","
        + "\"expand_contract\":\"readers_failed = 0 y max_read_wait_ms = lo que dura UN lote. El trabajo total es el mismo; lo que cambia es como se reparte.\","
        + "\"dotnet_note\":\"TryEnterReadLock(ms) devuelve false en vez de lanzar, asi que 'no pude leer' es un camino del codigo y no un catch. Y ReaderWriterLockSlim es IDisposable: un lock con recursos nativos en un runtime con GC.\"}}";

    private static async Task Main()
    {
        ResetTable(20000);
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
        Console.WriteLine($"[case17-dotnet] listening on {port}");

        var cts = new CancellationTokenSource();
        Console.CancelKeyPress += (_, e) => { e.Cancel = true; cts.Cancel(); try { listener.Stop(); } catch { } };

        while (!cts.IsCancellationRequested)
        {
            HttpListenerContext ctx;
            try { ctx = await listener.GetContextAsync().ConfigureAwait(false); }
            catch { break; }
            _ = Task.Run(() => Handle(ctx));
        }
    }

    private static void Handle(HttpListenerContext ctx)
    {
        var path = ctx.Request.Url?.AbsolutePath ?? "/";
        var q = QueryParams(ctx.Request.Url?.Query);
        var rows = Clamp(ParseInt(q.GetValueOrDefault("rows"), 20000), 1000, 500000);
        var readers = Clamp(ParseInt(q.GetValueOrDefault("readers"), 8), 1, 64);
        var msPer1k = Clamp(ParseInt(q.GetValueOrDefault("ms_per_1k"), 20), 1, 200);
        var batch = Clamp(ParseInt(q.GetValueOrDefault("batch"), 2000), 100, 100000);
        var pauseMs = Clamp(ParseInt(q.GetValueOrDefault("pause_ms"), 5), 0, 200);

        var status = 200;
        string body;
        try
        {
            switch (path)
            {
                case "/":
                case "/index":
                    body = "{\"case\":\"" + CaseName + "\",\"stack\":\"" + Stack
                         + "\",\"dotnet_specific\":\"ReaderWriterLockSlim con TryEnterReadLock(ms): el deadline es un valor de retorno, no una excepcion. Y el lock es IDisposable — recursos nativos en un runtime con GC.\""
                         + ",\"routes\":[\"/health\",\"/migrate-blocking?rows=20000&readers=8\",\"/migrate-expand-contract?rows=20000&readers=8&batch=2000&pause_ms=5\",\"/migration/state\",\"/backfill?batch=2000\",\"/diagnostics/summary\",\"/reset-lab\"]}";
                    break;
                case "/health":
                    body = $"{{\"status\":\"ok\",\"stack\":\"{Stack}\",\"case\":\"{CaseName}\"}}";
                    break;
                case "/migrate-blocking":
                    body = RunMigration("blocking", rows, readers, msPer1k, batch, pauseMs);
                    break;
                case "/migrate-expand-contract":
                    body = RunMigration("expand_contract", rows, readers, msPer1k, batch, pauseMs);
                    break;
                case "/migration/state":
                    body = MigrationStateJson();
                    break;
                case "/backfill":
                    body = BackfillStepJson(batch, msPer1k);
                    break;
                case "/diagnostics/summary":
                    body = DiagnosticsJson();
                    break;
                case "/reset-lab":
                    ResetTable(rows);
                    _metrics = Fresh();
                    body = "{\"status\":\"reset\",\"message\":\"Tabla, fase y metricas reiniciadas.\"}";
                    break;
                default:
                    status = 404;
                    body = $"{{\"error\":\"Ruta no encontrada\",\"path\":\"{Escape(path)}\"}}";
                    break;
            }
        }
        catch (Exception e)
        {
            status = 500;
            body = $"{{\"error\":\"internal\",\"detail\":\"{Escape(e.Message)}\"}}";
        }

        SendJson(ctx, status, body);
    }

    private static int ParseInt(string? raw, int fallback) => int.TryParse(raw, out var v) ? v : fallback;

    private static int Clamp(int v, int lo, int hi) => Math.Max(lo, Math.Min(hi, v));

    private static string Escape(string? v) =>
        v == null ? "" : v.Replace("\\", "\\\\").Replace("\"", "\\\"");

    private static Dictionary<string, string> QueryParams(string? raw)
    {
        var d = new Dictionary<string, string>();
        if (string.IsNullOrEmpty(raw)) return d;
        if (raw.StartsWith('?')) raw = raw[1..];
        foreach (var pair in raw.Split('&', StringSplitOptions.RemoveEmptyEntries))
        {
            var parts = pair.Split('=', 2);
            d[WebUtility.UrlDecode(parts[0]) ?? ""] =
                parts.Length > 1 ? WebUtility.UrlDecode(parts[1]) ?? "" : "";
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
