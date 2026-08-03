using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Net;
using System.Text;
using System.Threading;
using System.Threading.Tasks;
using Microsoft.Data.Sqlite;

// Caso 01 — API lenta bajo carga (stack .NET 8).
//
// Espejo del Main.java equivalente. Mismos endpoints, misma semantica, JSON con
// el mismo shape.
//
// Substrato real: SQLite embebido via Microsoft.Data.Sqlite 8.0.10, en archivo
// bajo el temp del sistema y con journal_mode=WAL. No hay listas en memoria
// simulando ser una base: `db_hits` cuenta ejecuciones reales contra el motor.
//
// Por que WAL y una conexion por unidad de trabajo: el worker escribe
// customer_summary mientras las rutas leen. Con WAL los lectores no se bloquean
// con el escritor — es el equivalente embebido del MVCC que da PostgreSQL en el
// stack PHP, y es exactamente la propiedad que este caso enseña.
//
// Primitivas .NET idiomaticas:
//   - `using` / IDisposable para SqliteConnection y SqliteCommand (cierre
//     garantizado incluso en el camino de excepcion — sin fugas de conexion).
//   - SqliteCommand con parametros nombrados reales.
//   - Interlocked + lock para metricas.
//   - Task.Delay periodico con CancellationToken para el worker.

internal static class Program
{
    private const string CaseName = "01 - API lenta bajo carga";
    private static readonly string Stack = Environment.GetEnvironmentVariable("APP_STACK") ?? ".NET 8";
    private const int SummaryRefreshSeconds = 5;
    private const int MaxSamples = 3000;
    private const int MaxJobRuns = 30;
    private const string WorkerName = "report-refresh-dotnet";

    private static readonly string StorageDir =
        Path.Combine(Path.GetTempPath(), "pdsl-case01-dotnet");
    private static readonly string DbPath = Path.Combine(StorageDir, "case01.sqlite3");
    private static readonly string ConnString = $"Data Source={DbPath}";

    private static readonly Metrics LegacyMetrics = new();
    private static readonly Metrics OptimizedMetrics = new();

    private static async Task Main()
    {
        Directory.CreateDirectory(StorageDir);
        // Arranque limpio y determinista: se borra la DB y los sidecars de WAL.
        foreach (var f in new[] { DbPath, DbPath + "-wal", DbPath + "-shm" })
        {
            try { if (File.Exists(f)) File.Delete(f); } catch { /* primer boot */ }
        }

        InitSchema();
        SeedData();
        RefreshSummary();

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
        Console.WriteLine($"[case01-dotnet] listening on {port}");

        var cts = new CancellationTokenSource();
        Console.CancelKeyPress += (_, e) => { e.Cancel = true; cts.Cancel(); try { listener.Stop(); } catch {} };

        _ = Task.Run(() => WorkerLoopAsync(cts.Token));

        while (!cts.IsCancellationRequested)
        {
            HttpListenerContext ctx;
            try { ctx = await listener.GetContextAsync(); }
            catch { break; }
            _ = Task.Run(() => Handle(ctx));
        }
    }

    /// <summary>Conexion nueva por unidad de trabajo. WAL permite lector+escritor en paralelo.</summary>
    private static SqliteConnection Open()
    {
        var c = new SqliteConnection(ConnString);
        c.Open();
        using var pragma = c.CreateCommand();
        pragma.CommandText = "PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;";
        pragma.ExecuteNonQuery();
        return c;
    }

    private static async Task WorkerLoopAsync(CancellationToken ct)
    {
        while (!ct.IsCancellationRequested)
        {
            try { await Task.Delay(SummaryRefreshSeconds * 1000, ct); } catch { break; }
            RefreshSummary();
        }
    }

    /// <summary>
    /// Refresca customer_summary con un DELETE + INSERT ... SELECT real. Corre en
    /// su propia conexion; gracias a WAL los lectores siguen respondiendo mientras
    /// esta transaccion escribe.
    /// </summary>
    private static void RefreshSummary()
    {
        var sw = Stopwatch.StartNew();
        try
        {
            using var db = Open();
            using var tx = db.BeginTransaction();

            using (var del = db.CreateCommand())
            {
                del.Transaction = tx;
                del.CommandText = "DELETE FROM customer_summary";
                del.ExecuteNonQuery();
            }

            int refreshed;
            using (var ins = db.CreateCommand())
            {
                ins.Transaction = tx;
                ins.CommandText =
                    "INSERT INTO customer_summary (customer_id, order_count, total_amount, refreshed_at) " +
                    "SELECT customer_id, COUNT(*), ROUND(SUM(amount), 2), strftime('%s','now') " +
                    "FROM orders GROUP BY customer_id";
                refreshed = ins.ExecuteNonQuery();
            }

            sw.Stop();
            var durMs = (long)sw.Elapsed.TotalMilliseconds;

            using (var upd = db.CreateCommand())
            {
                upd.Transaction = tx;
                upd.CommandText =
                    "UPDATE worker_state SET last_status = $s, last_duration_ms = $d, " +
                    "last_message = $m, last_heartbeat = $h WHERE worker_name = $w";
                upd.Parameters.AddWithValue("$s", "ok");
                upd.Parameters.AddWithValue("$d", durMs);
                upd.Parameters.AddWithValue("$m", $"refreshed {refreshed} customer summaries");
                upd.Parameters.AddWithValue("$h", DateTime.UtcNow.ToString("o"));
                upd.Parameters.AddWithValue("$w", WorkerName);
                upd.ExecuteNonQuery();
            }
            using (var run = db.CreateCommand())
            {
                run.Transaction = tx;
                run.CommandText =
                    "INSERT INTO job_runs (at, status, duration_ms, customers_refreshed) " +
                    "VALUES ($a, $s, $d, $c)";
                run.Parameters.AddWithValue("$a", DateTime.UtcNow.ToString("o"));
                run.Parameters.AddWithValue("$s", "ok");
                run.Parameters.AddWithValue("$d", durMs);
                run.Parameters.AddWithValue("$c", refreshed);
                run.ExecuteNonQuery();
            }
            using (var trim = db.CreateCommand())
            {
                trim.Transaction = tx;
                trim.CommandText =
                    $"DELETE FROM job_runs WHERE id NOT IN (SELECT id FROM job_runs ORDER BY id DESC LIMIT {MaxJobRuns})";
                trim.ExecuteNonQuery();
            }

            tx.Commit();
        }
        catch (Exception e)
        {
            Console.Error.WriteLine($"[case01-dotnet] worker error: {e.Message}");
        }
    }

    private static void Handle(HttpListenerContext ctx)
    {
        var sw = Stopwatch.StartNew();
        var path = ctx.Request.Url?.AbsolutePath ?? "/";
        var q = QueryParams(ctx.Request.Url?.Query);
        int status = 200;
        string body;
        Metrics? tracked = null;
        try
        {
            switch (path)
            {
                case "/":
                case "/index":
                    body = IndexJson(); break;
                case "/health":
                    body = $"{{\"status\":\"ok\",\"stack\":\"{Stack}\",\"case\":\"{CaseName}\"}}"; break;
                case "/report-legacy":
                    body = ReportLegacy(Bounded(q.GetValueOrDefault("limit", "20"), 1, 200));
                    tracked = LegacyMetrics; break;
                case "/report-optimized":
                    body = ReportOptimized(Bounded(q.GetValueOrDefault("limit", "20"), 1, 200));
                    tracked = OptimizedMetrics; break;
                case "/batch/status":
                    body = WorkerStateJson(); break;
                case "/job-runs":
                    body = JobRunsJson(); break;
                case "/diagnostics/summary":
                    body = DiagnosticsJson(); break;
                case "/metrics":
                    body = MetricsJson(); break;
                case "/reset-lab":
                    LegacyMetrics.Reset(); OptimizedMetrics.Reset();
                    using (var db = Open())
                    using (var cmd = db.CreateCommand())
                    {
                        cmd.CommandText = "DELETE FROM job_runs";
                        cmd.ExecuteNonQuery();
                    }
                    body = $"{{\"status\":\"reset\",\"stack\":\"{Stack}\"}}"; break;
                default:
                    status = 404; body = $"{{\"error\":\"not_found\",\"path\":\"{Escape(path)}\"}}"; break;
            }
        }
        catch (Exception e) { status = 500; body = $"{{\"error\":\"internal\",\"detail\":\"{Escape(e.Message)}\"}}"; }

        sw.Stop();
        if (tracked != null) tracked.Record(Round2(sw.Elapsed.TotalMilliseconds));
        SendJson(ctx, status, body);
    }

    private static string IndexJson() =>
        "{" +
        "\"lab\":\"Problem-Driven Systems Lab\"," +
        $"\"case\":\"{CaseName}\"," +
        $"\"stack\":\"{Stack}\"," +
        "\"substrate\":\"SQLite embebido via Microsoft.Data.Sqlite (WAL, archivo en temp)\"," +
        "\"native_primitives\":[\"using/IDisposable (SqliteConnection, SqliteCommand)\",\"SqliteCommand con parametros (SQL real)\",\"Interlocked (counters)\",\"Task.Delay loop (worker)\"]," +
        "\"routes\":{" +
        "\"/health\":\"liveness check\"," +
        "\"/report-legacy?limit=20\":\"filtro no sargable (LOWER sobre la columna) + N+1 real\"," +
        "\"/report-optimized?limit=20\":\"rango sargable + batch IN(...) + lectura de customer_summary\"," +
        "\"/batch/status\":\"estado del worker\"," +
        "\"/job-runs\":\"historial de corridas del worker\"," +
        "\"/diagnostics/summary\":\"contraste legacy vs optimized\"," +
        "\"/metrics\":\"avg/p95/p99 por ruta\"," +
        "\"/reset-lab\":\"reinicia contadores e historico\"}}";

    /// <summary>
    /// Legacy: filtro no sargable — LOWER(region) sobre la columna impide usar
    /// idx_orders_region, el motor recorre la tabla entera. Despues, N+1 real:
    /// una query dependiente por cada fila devuelta.
    /// </summary>
    private static string ReportLegacy(int limit)
    {
        long dbHits = 0;
        var sw = Stopwatch.StartNew();
        var sb = new StringBuilder(8192);
        sb.Append("{\"variant\":\"legacy\",\"rows\":[");

        using (var db = Open())
        {
            var rows = new List<(int Id, int CustomerId, string Region, double Amount)>();
            using (var cmd = db.CreateCommand())
            {
                cmd.CommandText =
                    "SELECT id, customer_id, region, amount FROM orders " +
                    "WHERE LOWER(region) LIKE 'n%' ORDER BY id LIMIT $limit";
                cmd.Parameters.AddWithValue("$limit", limit);
                using var rd = cmd.ExecuteReader();
                while (rd.Read())
                    rows.Add((rd.GetInt32(0), rd.GetInt32(1), rd.GetString(2), rd.GetDouble(3)));
            }
            dbHits++;

            for (int i = 0; i < rows.Count; i++)
            {
                string name = "", tier = "";
                using (var cmd = db.CreateCommand())
                {
                    cmd.CommandText = "SELECT name, tier FROM customers WHERE id = $id";
                    cmd.Parameters.AddWithValue("$id", rows[i].CustomerId);
                    using var rd = cmd.ExecuteReader();
                    if (rd.Read()) { name = rd.GetString(0); tier = rd.GetString(1); }
                }
                dbHits++;
                if (i > 0) sb.Append(',');
                sb.Append("{\"order_id\":").Append(rows[i].Id)
                  .Append(",\"customer\":\"").Append(Escape(name)).Append('"')
                  .Append(",\"tier\":\"").Append(Escape(tier)).Append('"')
                  .Append(",\"region\":\"").Append(Escape(rows[i].Region)).Append('"')
                  .Append(",\"amount\":").Append(F(rows[i].Amount)).Append('}');
            }
        }

        sw.Stop();
        sb.Append("],\"db_hits\":").Append(dbHits)
          .Append(",\"elapsed_ms\":").Append(F(Round2(sw.Elapsed.TotalMilliseconds)))
          .Append(",\"note\":\"LOWER(region) invalida el indice + N+1 real: 1 + N queries contra SQLite.\"}");
        return sb.ToString();
    }

    /// <summary>
    /// Optimized: el mismo filtro reescrito como rango sargable (usa
    /// idx_orders_region), dos batches IN(...) y lectura de customer_summary que
    /// el worker mantiene. Queries constantes, no 1+N.
    /// </summary>
    private static string ReportOptimized(int limit)
    {
        long dbHits = 0;
        var sw = Stopwatch.StartNew();
        var sb = new StringBuilder(8192);
        sb.Append("{\"variant\":\"optimized\",\"rows\":[");
        int summarySize;

        using (var db = Open())
        {
            var rows = new List<(int Id, int CustomerId, string Region, double Amount)>();
            using (var cmd = db.CreateCommand())
            {
                // Rango sargable: region >= 'n' AND region < 'o' usa el indice.
                cmd.CommandText =
                    "SELECT id, customer_id, region, amount FROM orders " +
                    "WHERE region >= 'n' AND region < 'o' ORDER BY id LIMIT $limit";
                cmd.Parameters.AddWithValue("$limit", limit);
                using var rd = cmd.ExecuteReader();
                while (rd.Read())
                    rows.Add((rd.GetInt32(0), rd.GetInt32(1), rd.GetString(2), rd.GetDouble(3)));
            }
            dbHits++;

            var customerBatch = new Dictionary<int, (string Name, string Tier)>();
            var summaryBatch = new Dictionary<int, (long Count, double Total)>();
            if (rows.Count > 0)
            {
                var names = rows.Select((_, i) => $"$c{i}").ToArray();
                var placeholders = string.Join(",", names);

                using (var cmd = db.CreateCommand())
                {
                    cmd.CommandText = $"SELECT id, name, tier FROM customers WHERE id IN ({placeholders})";
                    for (int i = 0; i < rows.Count; i++) cmd.Parameters.AddWithValue(names[i], rows[i].CustomerId);
                    using var rd = cmd.ExecuteReader();
                    while (rd.Read()) customerBatch[rd.GetInt32(0)] = (rd.GetString(1), rd.GetString(2));
                }
                dbHits++;

                using (var cmd = db.CreateCommand())
                {
                    cmd.CommandText =
                        $"SELECT customer_id, order_count, total_amount FROM customer_summary WHERE customer_id IN ({placeholders})";
                    for (int i = 0; i < rows.Count; i++) cmd.Parameters.AddWithValue(names[i], rows[i].CustomerId);
                    using var rd = cmd.ExecuteReader();
                    while (rd.Read()) summaryBatch[rd.GetInt32(0)] = (rd.GetInt64(1), rd.GetDouble(2));
                }
                dbHits++;
            }

            for (int i = 0; i < rows.Count; i++)
            {
                customerBatch.TryGetValue(rows[i].CustomerId, out var c);
                summaryBatch.TryGetValue(rows[i].CustomerId, out var s);
                if (i > 0) sb.Append(',');
                sb.Append("{\"order_id\":").Append(rows[i].Id)
                  .Append(",\"customer\":\"").Append(Escape(c.Name ?? "")).Append('"')
                  .Append(",\"tier\":\"").Append(Escape(c.Tier ?? "")).Append('"')
                  .Append(",\"region\":\"").Append(Escape(rows[i].Region)).Append('"')
                  .Append(",\"amount\":").Append(F(rows[i].Amount))
                  .Append(",\"lifetime_orders\":").Append(s.Count)
                  .Append(",\"lifetime_amount\":").Append(F(s.Total))
                  .Append('}');
            }

            summarySize = CountRows(db, "customer_summary");
            dbHits++;
        }

        sw.Stop();
        sb.Append("],\"db_hits\":").Append(dbHits)
          .Append(",\"elapsed_ms\":").Append(F(Round2(sw.Elapsed.TotalMilliseconds)))
          .Append(",\"summary_cache_size\":").Append(summarySize)
          .Append(",\"note\":\"Rango sargable + 2 batches IN(...) + customer_summary mantenida por el worker.\"}");
        return sb.ToString();
    }

    private static string DiagnosticsJson()
    {
        int summarySize;
        using (var db = Open()) summarySize = CountRows(db, "customer_summary");
        return "{" +
            $"\"stack\":\"{Stack}\"," +
            $"\"case\":\"{CaseName}\"," +
            "\"substrate\":\"SQLite embebido (Microsoft.Data.Sqlite, WAL)\"," +
            $"\"legacy\":{LegacyMetrics.ToJson("legacy")}," +
            $"\"optimized\":{OptimizedMetrics.ToJson("optimized")}," +
            $"\"summary_cache_size\":{summarySize}," +
            $"\"worker\":{WorkerStateJson()}}}";
    }

    private static string MetricsJson() =>
        $"{{\"legacy\":{LegacyMetrics.ToJson("legacy")},\"optimized\":{OptimizedMetrics.ToJson("optimized")}}}";

    private static string WorkerStateJson()
    {
        using var db = Open();
        using var cmd = db.CreateCommand();
        cmd.CommandText =
            "SELECT last_status, last_duration_ms, last_message, last_heartbeat " +
            "FROM worker_state WHERE worker_name = $w";
        cmd.Parameters.AddWithValue("$w", WorkerName);
        using var rd = cmd.ExecuteReader();
        if (!rd.Read())
        {
            return $"{{\"worker_name\":\"{WorkerName}\",\"last_status\":\"unknown\"," +
                   "\"last_duration_ms\":-1,\"last_message\":\"\",\"last_heartbeat\":\"\"}";
        }
        return "{" +
            $"\"worker_name\":\"{WorkerName}\"," +
            $"\"last_status\":\"{Escape(rd.GetString(0))}\"," +
            $"\"last_duration_ms\":{rd.GetInt64(1)}," +
            $"\"last_message\":\"{Escape(rd.IsDBNull(2) ? "" : rd.GetString(2))}\"," +
            $"\"last_heartbeat\":\"{Escape(rd.IsDBNull(3) ? "" : rd.GetString(3))}\"}}";
    }

    private static string JobRunsJson()
    {
        var sb = new StringBuilder(1024);
        sb.Append("{\"runs\":[");
        using (var db = Open())
        using (var cmd = db.CreateCommand())
        {
            cmd.CommandText =
                "SELECT at, status, duration_ms, customers_refreshed FROM job_runs " +
                "ORDER BY id DESC LIMIT $limit";
            cmd.Parameters.AddWithValue("$limit", MaxJobRuns);
            using var rd = cmd.ExecuteReader();
            bool first = true;
            while (rd.Read())
            {
                if (!first) sb.Append(',');
                sb.Append("{\"at\":\"").Append(Escape(rd.GetString(0)))
                  .Append("\",\"status\":\"").Append(Escape(rd.GetString(1)))
                  .Append("\",\"duration_ms\":").Append(rd.GetInt64(2))
                  .Append(",\"customers_refreshed\":").Append(rd.GetInt32(3))
                  .Append('}');
                first = false;
            }
        }
        sb.Append("],\"max_runs_kept\":").Append(MaxJobRuns).Append('}');
        return sb.ToString();
    }

    // ---------- schema y seed ----------

    private static void InitSchema()
    {
        using var db = Open();
        using var cmd = db.CreateCommand();
        cmd.CommandText = @"
            CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT NOT NULL, tier TEXT NOT NULL);
            CREATE TABLE orders (id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL, region TEXT NOT NULL, amount REAL NOT NULL);
            CREATE TABLE customer_summary (customer_id INTEGER PRIMARY KEY, order_count INTEGER NOT NULL, total_amount REAL NOT NULL, refreshed_at INTEGER NOT NULL);
            CREATE TABLE worker_state (worker_name TEXT PRIMARY KEY, last_status TEXT NOT NULL, last_duration_ms INTEGER NOT NULL, last_message TEXT, last_heartbeat TEXT);
            CREATE TABLE job_runs (id INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT NOT NULL, status TEXT NOT NULL, duration_ms INTEGER NOT NULL, customers_refreshed INTEGER NOT NULL);
            -- El indice que la ruta legacy desperdicia al envolver la columna en LOWER().
            CREATE INDEX idx_orders_region ON orders (region, id);
            CREATE INDEX idx_orders_customer ON orders (customer_id);";
        cmd.ExecuteNonQuery();
    }

    private static void SeedData()
    {
        long seed = 102030L;
        string[] regions = { "north", "south", "east", "west" };
        string[] tiers = { "bronze", "silver", "gold" };

        using var db = Open();
        using var tx = db.BeginTransaction();

        using (var cmd = db.CreateCommand())
        {
            cmd.Transaction = tx;
            cmd.CommandText = "INSERT INTO customers VALUES ($id, $name, $tier)";
            var pid = cmd.Parameters.Add("$id", Microsoft.Data.Sqlite.SqliteType.Integer);
            var pname = cmd.Parameters.Add("$name", Microsoft.Data.Sqlite.SqliteType.Text);
            var ptier = cmd.Parameters.Add("$tier", Microsoft.Data.Sqlite.SqliteType.Text);
            for (int i = 1; i <= 1600; i++)
            {
                seed = (seed * 9301 + 49297) % 233280;
                pid.Value = i;
                pname.Value = $"Customer {i}";
                ptier.Value = tiers[(int)(seed % tiers.Length)];
                cmd.ExecuteNonQuery();
            }
        }

        using (var cmd = db.CreateCommand())
        {
            cmd.Transaction = tx;
            cmd.CommandText = "INSERT INTO orders VALUES ($id, $cid, $region, $amount)";
            var pid = cmd.Parameters.Add("$id", Microsoft.Data.Sqlite.SqliteType.Integer);
            var pcid = cmd.Parameters.Add("$cid", Microsoft.Data.Sqlite.SqliteType.Integer);
            var pregion = cmd.Parameters.Add("$region", Microsoft.Data.Sqlite.SqliteType.Text);
            var pamount = cmd.Parameters.Add("$amount", Microsoft.Data.Sqlite.SqliteType.Real);
            for (int i = 1; i <= 4800; i++)
            {
                seed = (seed * 9301 + 49297) % 233280;
                pid.Value = i;
                pcid.Value = 1 + (int)(seed % 1600);
                pregion.Value = regions[(int)((seed / 7) % regions.Length)];
                pamount.Value = Round2(20.0 + (seed % 1000));
                cmd.ExecuteNonQuery();
            }
        }

        using (var cmd = db.CreateCommand())
        {
            cmd.Transaction = tx;
            cmd.CommandText = "INSERT INTO worker_state VALUES ($w, $s, $d, $m, $h)";
            cmd.Parameters.AddWithValue("$w", WorkerName);
            cmd.Parameters.AddWithValue("$s", "init");
            cmd.Parameters.AddWithValue("$d", -1L);
            cmd.Parameters.AddWithValue("$m", "worker not started yet");
            cmd.Parameters.AddWithValue("$h", "");
            cmd.ExecuteNonQuery();
        }

        tx.Commit();
    }

    private static int CountRows(SqliteConnection db, string table)
    {
        using var cmd = db.CreateCommand();
        cmd.CommandText = $"SELECT COUNT(*) FROM {table}";
        return Convert.ToInt32(cmd.ExecuteScalar());
    }

    // ---------- tipos ----------

    private sealed class Metrics
    {
        private long _requests;
        private readonly List<double> _samples = new();
        private readonly object _lock = new();
        public void Record(double elapsedMs)
        {
            Interlocked.Increment(ref _requests);
            lock (_lock)
            {
                _samples.Add(elapsedMs);
                while (_samples.Count > MaxSamples) _samples.RemoveAt(0);
            }
        }
        public void Reset()
        {
            Interlocked.Exchange(ref _requests, 0);
            lock (_lock) _samples.Clear();
        }
        public string ToJson(string label)
        {
            List<double> snap;
            long req = Interlocked.Read(ref _requests);
            lock (_lock) snap = new List<double>(_samples);
            return $"{{\"label\":\"{label}\"," +
                   $"\"requests\":{req}," +
                   $"\"sample_count\":{snap.Count}," +
                   $"\"avg_ms\":{F(Avg(snap))}," +
                   $"\"p95_ms\":{F(Percentile(snap, 95))}," +
                   $"\"p99_ms\":{F(Percentile(snap, 99))}}}";
        }
    }

    // ---------- helpers ----------

    private static double Avg(List<double> v) { if (v.Count == 0) return 0.0; double s = 0; foreach (var x in v) s += x; return Round2(s / v.Count); }
    private static double Percentile(List<double> v, int percent)
    {
        if (v.Count == 0) return 0.0;
        var ordered = v.OrderBy(x => x).ToList();
        int idx = Math.Max(0, Math.Min(ordered.Count - 1, (int)Math.Ceiling((percent / 100.0) * ordered.Count) - 1));
        return Round2(ordered[idx]);
    }
    private static double Round2(double v) => Math.Round(v, 2);
    private static string F(double v) => v.ToString("0.##", System.Globalization.CultureInfo.InvariantCulture);

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
