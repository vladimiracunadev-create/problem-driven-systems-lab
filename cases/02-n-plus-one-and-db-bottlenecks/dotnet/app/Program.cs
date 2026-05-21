using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Globalization;
using System.Linq;
using System.Net;
using System.Text;
using System.Threading;
using System.Threading.Tasks;
using Microsoft.Data.Sqlite;

// Caso 02 — N+1 queries y cuellos de botella DB (stack .NET 8).
//
// Substrato real: SQLite embebido vía Microsoft.Data.Sqlite 8.0.10. Conexión
// en proceso, sin contenedor extra, sin puerto extra. Antes este caso
// simulaba N+1 con un Dictionary<int, List<Item>>, lo que es deshonesto:
// N+1 *es* un problema de round-trips contra una base relacional.
//
// Schema (unificado con Node/Java para que las comparaciones sean justas):
//   categories(id, name)                              24 filas
//   customers(id, name, region, category_id)          900 filas
//   orders(id, customer_id, total, created_at)        1500 filas
//   order_items(id, order_id, sku, qty, price)        ~5000 filas

internal static class Program
{
    private const string CaseName = "02 - N+1 queries y cuellos de botella DB";
    private static readonly string Stack = Environment.GetEnvironmentVariable("APP_STACK") ?? ".NET 8";
    private const int MaxSamples = 3000;

    // Conexión global a `:memory:`. Microsoft.Data.Sqlite descarta una DB
    // en memoria al cerrar la última conexión, por eso mantenemos UNA
    // conexión viva durante toda la vida del proceso.
    private static SqliteConnection _db = null!;
    private static long _dbHitsLegacy;
    private static long _dbHitsOptimized;

    private static readonly Metrics LegacyMetrics = new();
    private static readonly Metrics OptimizedMetrics = new();

    private static async Task Main()
    {
        _db = new SqliteConnection("Data Source=:memory:");
        _db.Open();
        InitSchema();
        SeedData();

        var port = int.TryParse(Environment.GetEnvironmentVariable("PORT"), out var p) ? p : 8080;
        var listener = new HttpListener();
        listener.Prefixes.Add($"http://+:{port}/");
        try { listener.Start(); }
        catch (HttpListenerException) { listener = new HttpListener(); listener.Prefixes.Add($"http://*:{port}/"); listener.Start(); }
        Console.WriteLine($"[case02-dotnet] listening on {port}");

        while (true)
        {
            HttpListenerContext ctx;
            try { ctx = await listener.GetContextAsync(); } catch { break; }
            _ = Task.Run(() => Handle(ctx));
        }
    }

    private static void InitSchema()
    {
        using var cmd = _db.CreateCommand();
        cmd.CommandText = @"
            CREATE TABLE categories (id INTEGER PRIMARY KEY, name TEXT NOT NULL);
            CREATE TABLE customers  (id INTEGER PRIMARY KEY, name TEXT NOT NULL, region TEXT NOT NULL, category_id INTEGER NOT NULL);
            CREATE TABLE orders     (id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL, total REAL NOT NULL, created_at INTEGER NOT NULL);
            CREATE TABLE order_items(id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL, sku TEXT NOT NULL, qty INTEGER NOT NULL, price REAL NOT NULL);
            CREATE INDEX idx_items_order_id ON order_items (order_id);
        ";
        cmd.ExecuteNonQuery();
    }

    private static void SeedData()
    {
        string[] regions = { "LATAM", "NA", "EMEA", "APAC" };
        long now = DateTimeOffset.UtcNow.ToUnixTimeSeconds();
        long seed = 270718L;

        using var tx = _db.BeginTransaction();

        using (var c = _db.CreateCommand())
        {
            c.Transaction = tx;
            c.CommandText = "INSERT INTO categories VALUES ($id, $name)";
            var pid = c.CreateParameter(); pid.ParameterName = "$id"; c.Parameters.Add(pid);
            var pname = c.CreateParameter(); pname.ParameterName = "$name"; c.Parameters.Add(pname);
            for (int i = 1; i <= 24; i++)
            {
                pid.Value = i; pname.Value = $"Category {i}";
                c.ExecuteNonQuery();
            }
        }

        using (var c = _db.CreateCommand())
        {
            c.Transaction = tx;
            c.CommandText = "INSERT INTO customers VALUES ($id, $name, $region, $cat)";
            var pid = c.CreateParameter(); pid.ParameterName = "$id"; c.Parameters.Add(pid);
            var pname = c.CreateParameter(); pname.ParameterName = "$name"; c.Parameters.Add(pname);
            var preg = c.CreateParameter(); preg.ParameterName = "$region"; c.Parameters.Add(preg);
            var pcat = c.CreateParameter(); pcat.ParameterName = "$cat"; c.Parameters.Add(pcat);
            for (int i = 1; i <= 900; i++)
            {
                seed = (seed * 9301 + 49297) % 233280;
                pid.Value = i; pname.Value = $"Customer {i}";
                preg.Value = regions[(int)(seed % regions.Length)];
                pcat.Value = 1 + ((i - 1) % 24);
                c.ExecuteNonQuery();
            }
        }

        using var ordCmd = _db.CreateCommand();
        ordCmd.Transaction = tx;
        ordCmd.CommandText = "INSERT INTO orders VALUES ($id, $cid, $total, $created)";
        var oId = ordCmd.CreateParameter(); oId.ParameterName = "$id"; ordCmd.Parameters.Add(oId);
        var oCid = ordCmd.CreateParameter(); oCid.ParameterName = "$cid"; ordCmd.Parameters.Add(oCid);
        var oTot = ordCmd.CreateParameter(); oTot.ParameterName = "$total"; ordCmd.Parameters.Add(oTot);
        var oCre = ordCmd.CreateParameter(); oCre.ParameterName = "$created"; ordCmd.Parameters.Add(oCre);

        using var itCmd = _db.CreateCommand();
        itCmd.Transaction = tx;
        itCmd.CommandText = "INSERT INTO order_items VALUES ($id, $oid, $sku, $qty, $price)";
        var iId = itCmd.CreateParameter(); iId.ParameterName = "$id"; itCmd.Parameters.Add(iId);
        var iOid = itCmd.CreateParameter(); iOid.ParameterName = "$oid"; itCmd.Parameters.Add(iOid);
        var iSku = itCmd.CreateParameter(); iSku.ParameterName = "$sku"; itCmd.Parameters.Add(iSku);
        var iQty = itCmd.CreateParameter(); iQty.ParameterName = "$qty"; itCmd.Parameters.Add(iQty);
        var iPri = itCmd.CreateParameter(); iPri.ParameterName = "$price"; itCmd.Parameters.Add(iPri);

        int itemId = 1;
        for (int orderId = 1; orderId <= 1500; orderId++)
        {
            seed = (seed * 9301 + 49297) % 233280;
            int cid = 1 + (int)(seed % 900);
            seed = (seed * 9301 + 49297) % 233280;
            long createdAt = now - (seed % (120L * 86400L));
            int itemsPerOrder = 2 + (int)(seed % 4); // 2..5

            double total = 0.0;
            var pending = new List<(int Id, string Sku, int Qty, double Price)>(itemsPerOrder);
            for (int k = 0; k < itemsPerOrder; k++)
            {
                seed = (seed * 9301 + 49297) % 233280;
                string sku = "SKU-" + (1000 + (int)(seed % 9000));
                int qty = 1 + (int)(seed % 8);
                seed = (seed * 9301 + 49297) % 233280;
                double price = Math.Round(10.0 + (seed % 233280) / 233280.0 * 220.0, 2);
                total += qty * price;
                pending.Add((itemId++, sku, qty, price));
            }

            oId.Value = orderId; oCid.Value = cid;
            oTot.Value = Math.Round(total, 2); oCre.Value = createdAt;
            ordCmd.ExecuteNonQuery();
            foreach (var it in pending)
            {
                iId.Value = it.Id; iOid.Value = orderId;
                iSku.Value = it.Sku; iQty.Value = it.Qty; iPri.Value = it.Price;
                itCmd.ExecuteNonQuery();
            }
        }

        tx.Commit();
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
                    body = $"{{\"case\":\"{CaseName}\",\"stack\":\"{Stack}\",\"routes\":[\"/health\",\"/orders-legacy?limit=20\",\"/orders-optimized?limit=20\",\"/diagnostics/summary\",\"/metrics\",\"/reset-lab\"]}}"; break;
                case "/health":
                    body = $"{{\"status\":\"ok\",\"stack\":\"{Stack}\",\"case\":\"{CaseName}\"}}"; break;
                case "/orders-legacy":
                    body = OrdersLegacy(Bounded(q.GetValueOrDefault("limit", "20"), 1, 200));
                    tracked = LegacyMetrics; break;
                case "/orders-optimized":
                    body = OrdersOptimized(Bounded(q.GetValueOrDefault("limit", "20"), 1, 200));
                    tracked = OptimizedMetrics; break;
                case "/diagnostics/summary":
                    body = DiagnosticsSummary(); break;
                case "/metrics":
                    body = $"{{\"legacy\":{LegacyMetrics.ToJson("legacy")},\"optimized\":{OptimizedMetrics.ToJson("optimized")}}}"; break;
                case "/reset-lab":
                    LegacyMetrics.Reset(); OptimizedMetrics.Reset();
                    Interlocked.Exchange(ref _dbHitsLegacy, 0);
                    Interlocked.Exchange(ref _dbHitsOptimized, 0);
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

    private sealed record OrderRow(int Id, int CustomerId);
    private sealed record ItemRow(string Sku, int Qty);

    /// <summary>Legacy: 1 SELECT orders + N SELECT items (uno por order) — N+1 real.</summary>
    private static string OrdersLegacy(int limit)
    {
        var sw = Stopwatch.StartNew();
        long dbHits = 0;
        var orders = new List<OrderRow>();

        // 1: SELECT orders
        using (var cmd = _db.CreateCommand())
        {
            cmd.CommandText = "SELECT id, customer_id FROM orders ORDER BY id ASC LIMIT $limit";
            cmd.Parameters.AddWithValue("$limit", limit);
            using var rd = cmd.ExecuteReader();
            Interlocked.Increment(ref _dbHitsLegacy); dbHits++;
            while (rd.Read()) orders.Add(new OrderRow(rd.GetInt32(0), rd.GetInt32(1)));
        }

        var sb = new StringBuilder(8192);
        sb.Append("{\"variant\":\"legacy\",\"rows\":[");
        // N: por cada order, un ExecuteReader separado — el anti-patrón
        // que un ORM produce al iterar sin Include/eager-load.
        using (var cmd = _db.CreateCommand())
        {
            cmd.CommandText = "SELECT sku, qty FROM order_items WHERE order_id = $oid ORDER BY id ASC";
            var p = cmd.CreateParameter(); p.ParameterName = "$oid"; cmd.Parameters.Add(p);
            for (int i = 0; i < orders.Count; i++)
            {
                var o = orders[i];
                p.Value = o.Id;
                var items = new List<ItemRow>();
                using (var rd = cmd.ExecuteReader())
                {
                    Interlocked.Increment(ref _dbHitsLegacy); dbHits++;
                    while (rd.Read()) items.Add(new ItemRow(rd.GetString(0), rd.GetInt32(1)));
                }
                if (i > 0) sb.Append(',');
                sb.Append("{\"order_id\":").Append(o.Id)
                  .Append(",\"customer_id\":").Append(o.CustomerId)
                  .Append(",\"item_count\":").Append(items.Count)
                  .Append(",\"items\":[");
                for (int j = 0; j < items.Count; j++)
                {
                    if (j > 0) sb.Append(',');
                    sb.Append("{\"sku\":\"").Append(Escape(items[j].Sku)).Append("\",\"qty\":").Append(items[j].Qty).Append('}');
                }
                sb.Append("]}");
            }
        }
        sw.Stop();
        sb.Append("],\"db_hits\":").Append(dbHits)
          .Append(",\"elapsed_ms\":").Append(F(Round2(sw.Elapsed.TotalMilliseconds)))
          .Append(",\"note\":\"1 SELECT orders + N SELECT items (uno por order).\"}");
        return sb.ToString();
    }

    /// <summary>Optimized: 1 SELECT orders + 1 SELECT items WHERE order_id IN (...) batch.</summary>
    private static string OrdersOptimized(int limit)
    {
        var sw = Stopwatch.StartNew();
        long dbHits = 0;
        var orders = new List<OrderRow>();

        using (var cmd = _db.CreateCommand())
        {
            cmd.CommandText = "SELECT id, customer_id FROM orders ORDER BY id ASC LIMIT $limit";
            cmd.Parameters.AddWithValue("$limit", limit);
            using var rd = cmd.ExecuteReader();
            Interlocked.Increment(ref _dbHitsOptimized); dbHits++;
            while (rd.Read()) orders.Add(new OrderRow(rd.GetInt32(0), rd.GetInt32(1)));
        }

        var itemsByOrder = new Dictionary<int, List<ItemRow>>();
        if (orders.Count > 0)
        {
            // Placeholders dinámicos para IN(?, ?, ...). Cada parámetro es
            // distinto ($p0, $p1, ...) para que SqliteCommand los enlace.
            var phs = new StringBuilder();
            for (int i = 0; i < orders.Count; i++)
            {
                if (i > 0) phs.Append(',');
                phs.Append('$').Append('p').Append(i);
            }
            using var cmd = _db.CreateCommand();
            cmd.CommandText = $"SELECT order_id, sku, qty FROM order_items WHERE order_id IN ({phs}) ORDER BY id ASC";
            for (int i = 0; i < orders.Count; i++)
                cmd.Parameters.AddWithValue("$p" + i, orders[i].Id);
            using var rd = cmd.ExecuteReader();
            Interlocked.Increment(ref _dbHitsOptimized); dbHits++;
            while (rd.Read())
            {
                int oid = rd.GetInt32(0);
                if (!itemsByOrder.TryGetValue(oid, out var list))
                {
                    list = new List<ItemRow>();
                    itemsByOrder[oid] = list;
                }
                list.Add(new ItemRow(rd.GetString(1), rd.GetInt32(2)));
            }
        }

        var sb = new StringBuilder(8192);
        sb.Append("{\"variant\":\"optimized\",\"rows\":[");
        for (int i = 0; i < orders.Count; i++)
        {
            var o = orders[i];
            var items = itemsByOrder.TryGetValue(o.Id, out var l) ? l : new List<ItemRow>();
            if (i > 0) sb.Append(',');
            sb.Append("{\"order_id\":").Append(o.Id)
              .Append(",\"customer_id\":").Append(o.CustomerId)
              .Append(",\"item_count\":").Append(items.Count)
              .Append(",\"items\":[");
            for (int j = 0; j < items.Count; j++)
            {
                if (j > 0) sb.Append(',');
                sb.Append("{\"sku\":\"").Append(Escape(items[j].Sku)).Append("\",\"qty\":").Append(items[j].Qty).Append('}');
            }
            sb.Append("]}");
        }
        sw.Stop();
        sb.Append("],\"db_hits\":").Append(dbHits)
          .Append(",\"elapsed_ms\":").Append(F(Round2(sw.Elapsed.TotalMilliseconds)))
          .Append(",\"note\":\"1 SELECT orders + 1 SELECT items con IN(...) batch.\"}");
        return sb.ToString();
    }

    private static string DiagnosticsSummary()
    {
        int customers = ScalarInt("SELECT COUNT(*) FROM customers");
        int categories = ScalarInt("SELECT COUNT(*) FROM categories");
        int ordersCount = ScalarInt("SELECT COUNT(*) FROM orders");
        int itemsCount = ScalarInt("SELECT COUNT(*) FROM order_items");
        double avg = ordersCount == 0 ? 0.0 : Round2(itemsCount / (double)ordersCount);
        return $"{{\"stack\":\"{Stack}\",\"case\":\"{CaseName}\"," +
               $"\"customers_total\":{customers},\"categories_total\":{categories}," +
               $"\"orders_total\":{ordersCount},\"items_total\":{itemsCount}," +
               $"\"avg_items_per_order\":{F(avg)}," +
               $"\"legacy\":{LegacyMetrics.ToJson("legacy")}," +
               $"\"optimized\":{OptimizedMetrics.ToJson("optimized")}}}";
    }

    private static int ScalarInt(string sql)
    {
        using var cmd = _db.CreateCommand();
        cmd.CommandText = sql;
        var o = cmd.ExecuteScalar();
        return o == null ? 0 : Convert.ToInt32(o);
    }

    private sealed class Metrics
    {
        private long _requests;
        private readonly List<double> _samples = new();
        private readonly object _lock = new();
        public void Record(double elapsedMs)
        {
            Interlocked.Increment(ref _requests);
            lock (_lock) { _samples.Add(elapsedMs); while (_samples.Count > MaxSamples) _samples.RemoveAt(0); }
        }
        public void Reset() { Interlocked.Exchange(ref _requests, 0); lock (_lock) _samples.Clear(); }
        public string ToJson(string label)
        {
            List<double> snap; long req = Interlocked.Read(ref _requests);
            lock (_lock) snap = new List<double>(_samples);
            return $"{{\"label\":\"{label}\",\"requests\":{req},\"sample_count\":{snap.Count}," +
                   $"\"avg_ms\":{F(Avg(snap))},\"p95_ms\":{F(Percentile(snap, 95))},\"p99_ms\":{F(Percentile(snap, 99))}}}";
        }
    }

    private static double Avg(List<double> v) { if (v.Count == 0) return 0.0; double s = 0; foreach (var x in v) s += x; return Round2(s / v.Count); }
    private static double Percentile(List<double> v, int percent)
    {
        if (v.Count == 0) return 0.0;
        var ordered = v.OrderBy(x => x).ToList();
        int idx = Math.Max(0, Math.Min(ordered.Count - 1, (int)Math.Ceiling((percent / 100.0) * ordered.Count) - 1));
        return Round2(ordered[idx]);
    }
    private static double Round2(double v) => Math.Round(v, 2);
    private static string F(double v) => v.ToString("0.##", CultureInfo.InvariantCulture);
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
