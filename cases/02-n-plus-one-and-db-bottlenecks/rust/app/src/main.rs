// Caso 02 — N+1 queries y cuellos de botella DB (stack Rust 1.83).
//
// Espejo del Main.java / Program.cs / main.go equivalentes: mismos endpoints,
// mismo shape de JSON, mismo dataset.
//
// Substrato real: SQLite embebido via rusqlite con feature `bundled`.
// `db_hits` cuenta ejecuciones reales contra el motor: 1+N en la ruta legacy,
// 2 en la optimizada.
//
// Lo que este stack aporta frente a los otros:
//
//   Rust no tiene ORM en la biblioteca estandar — ni siquiera tiene acceso a
//   datos. El SQL se escribe a mano, igual que en Go. Pero hay una diferencia
//   con Go que este caso hace visible: `stmt.query_map()` devuelve un iterador
//   de `Result<T>`, y el compilador **no deja ignorar el error**. En Go,
//   olvidarse de `rows.Err()` despues del bucle compila y silencia fallos
//   parciales del cursor; aca `collect::<Result<Vec<_>>>()` obliga a decidir
//   que pasa si una fila falla.

use rusqlite::{params, Connection};
use std::collections::HashMap;
use std::io::{BufRead, BufReader, Write};
use std::net::{TcpListener, TcpStream};
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::Mutex;
use std::thread;
use std::time::Instant;

const CASE_NAME: &str = "02 - N+1 queries y cuellos de botella DB";
const MAX_SAMPLES: usize = 3000;

static LEGACY_REQUESTS: AtomicI64 = AtomicI64::new(0);
static OPTIMIZED_REQUESTS: AtomicI64 = AtomicI64::new(0);
static LEGACY_SAMPLES: Mutex<Vec<f64>> = Mutex::new(Vec::new());
static OPTIMIZED_SAMPLES: Mutex<Vec<f64>> = Mutex::new(Vec::new());

fn stack() -> String {
    std::env::var("APP_STACK").unwrap_or_else(|_| "Rust 1.83".to_string())
}

fn db_path() -> String {
    let dir = std::env::temp_dir().join("pdsl-case02-rust");
    let _ = std::fs::create_dir_all(&dir);
    dir.join("case02.sqlite3").to_string_lossy().to_string()
}

fn open() -> rusqlite::Result<Connection> {
    let conn = Connection::open(db_path())?;
    conn.pragma_update(None, "journal_mode", "WAL")?;
    conn.pragma_update(None, "busy_timeout", 5000)?;
    Ok(conn)
}

fn main() {
    let base = db_path();
    for suffix in ["", "-wal", "-shm"] {
        let _ = std::fs::remove_file(format!("{base}{suffix}"));
    }
    init_schema().expect("init_schema");
    seed_data().expect("seed_data");

    let port: u16 = std::env::var("PORT").ok().and_then(|p| p.parse().ok()).unwrap_or(8080);
    let listener = TcpListener::bind(("0.0.0.0", port)).expect("bind");
    println!("[case02-rust] listening on {port}");
    for stream in listener.incoming().flatten() {
        thread::spawn(move || handle_conn(stream));
    }
}

// ---------- capa HTTP minima ----------

fn handle_conn(mut stream: TcpStream) {
    let mut reader = BufReader::new(match stream.try_clone() {
        Ok(s) => s,
        Err(_) => return,
    });
    let mut request_line = String::new();
    if reader.read_line(&mut request_line).is_err() {
        return;
    }
    loop {
        let mut line = String::new();
        match reader.read_line(&mut line) {
            Ok(0) => break,
            Ok(_) if line.trim().is_empty() => break,
            Ok(_) => continue,
            Err(_) => break,
        }
    }
    let target = request_line.split_whitespace().nth(1).unwrap_or("/");
    let (path, query) = target.split_once('?').unwrap_or((target, ""));
    let params = parse_query(query);
    let (status, body) = route(path, &params);
    let reason = if status == 200 { "OK" } else { "Not Found" };
    let response = format!(
        "HTTP/1.1 {status} {reason}\r\nContent-Type: application/json; charset=utf-8\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{body}",
        body.as_bytes().len()
    );
    let _ = stream.write_all(response.as_bytes());
    let _ = stream.flush();
}

fn parse_query(raw: &str) -> HashMap<String, String> {
    let mut out = HashMap::new();
    for pair in raw.split('&').filter(|p| !p.is_empty()) {
        let (k, v) = pair.split_once('=').unwrap_or((pair, ""));
        out.insert(k.to_string(), v.to_string());
    }
    out
}

// ---------- routing ----------

fn route(path: &str, params: &HashMap<String, String>) -> (u16, String) {
    let limit = bounded(params.get("limit").map(String::as_str), 20, 1, 200);
    let start = Instant::now();

    let (body, tracked): (String, Option<(&Mutex<Vec<f64>>, &AtomicI64)>) = match path {
        "/" | "/index" => (
            format!(
                r#"{{"case":"{CASE_NAME}","stack":"{}","routes":["/health","/orders-legacy?limit=20","/orders-optimized?limit=20","/diagnostics/summary","/metrics","/reset-lab"]}}"#,
                stack()
            ),
            None,
        ),
        "/health" => (
            format!(r#"{{"status":"ok","stack":"{}","case":"{CASE_NAME}"}}"#, stack()),
            None,
        ),
        "/orders-legacy" => match orders_legacy(limit) {
            Ok(b) => (b, Some((&LEGACY_SAMPLES, &LEGACY_REQUESTS))),
            Err(e) => return (200, internal(&e.to_string())),
        },
        "/orders-optimized" => match orders_optimized(limit) {
            Ok(b) => (b, Some((&OPTIMIZED_SAMPLES, &OPTIMIZED_REQUESTS))),
            Err(e) => return (200, internal(&e.to_string())),
        },
        "/diagnostics/summary" => match diagnostics() {
            Ok(b) => (b, None),
            Err(e) => return (200, internal(&e.to_string())),
        },
        "/metrics" => (metrics_json(), None),
        "/reset-lab" => {
            LEGACY_REQUESTS.store(0, Ordering::Relaxed);
            OPTIMIZED_REQUESTS.store(0, Ordering::Relaxed);
            LEGACY_SAMPLES.lock().unwrap().clear();
            OPTIMIZED_SAMPLES.lock().unwrap().clear();
            (format!(r#"{{"status":"reset","stack":"{}"}}"#, stack()), None)
        }
        _ => {
            return (
                404,
                format!(r#"{{"error":"not_found","path":"{}"}}"#, escape(path)),
            )
        }
    };

    if let Some((samples, requests)) = tracked {
        requests.fetch_add(1, Ordering::Relaxed);
        let mut s = samples.lock().unwrap();
        s.push(round2(start.elapsed().as_secs_f64() * 1000.0));
        if s.len() > MAX_SAMPLES {
            let excess = s.len() - MAX_SAMPLES;
            s.drain(0..excess);
        }
    }
    (200, body)
}

// ---------- endpoints ----------

fn select_orders(conn: &Connection, limit: i64) -> rusqlite::Result<Vec<(i64, i64)>> {
    let mut stmt = conn.prepare("SELECT id, customer_id FROM orders ORDER BY id ASC LIMIT ?1")?;
    let mapped = stmt.query_map(params![limit], |r| Ok((r.get(0)?, r.get(1)?)))?;
    // collect::<Result<Vec<_>>> propaga el fallo de cualquier fila. No hay
    // forma de ignorarlo en silencio.
    mapped.collect()
}

/// Legacy: 1 SELECT orders + N SELECT items (uno por order).
fn orders_legacy(limit: i64) -> rusqlite::Result<String> {
    let start = Instant::now();
    let mut db_hits: i64 = 0;
    let conn = open()?;

    let orders = select_orders(&conn, limit)?;
    db_hits += 1;

    let mut out = String::with_capacity(8192);
    out.push_str(r#"{"variant":"legacy","rows":["#);
    for (i, (oid, cid)) in orders.iter().enumerate() {
        let items: Vec<(String, i64)> = {
            let mut stmt =
                conn.prepare("SELECT sku, qty FROM order_items WHERE order_id = ?1 ORDER BY id ASC")?;
            let mapped = stmt.query_map(params![oid], |r| Ok((r.get(0)?, r.get(1)?)))?;
            mapped.collect::<rusqlite::Result<Vec<_>>>()?
        };
        db_hits += 1;
        if i > 0 {
            out.push(',');
        }
        out.push_str(&render_order(*oid, *cid, &items));
    }
    out.push_str(&format!(
        r#"],"db_hits":{db_hits},"elapsed_ms":{},"note":"1 SELECT orders + N SELECT items (uno por order)."}}"#,
        fmt_num(round2(start.elapsed().as_secs_f64() * 1000.0))
    ));
    Ok(out)
}

/// Optimized: 1 SELECT orders + 1 SELECT items con IN(...) batch.
fn orders_optimized(limit: i64) -> rusqlite::Result<String> {
    let start = Instant::now();
    let mut db_hits: i64 = 0;
    let conn = open()?;

    let orders = select_orders(&conn, limit)?;
    db_hits += 1;

    let mut items_by_order: HashMap<i64, Vec<(String, i64)>> = HashMap::new();
    if !orders.is_empty() {
        let ids: Vec<i64> = orders.iter().map(|(id, _)| *id).collect();
        let placeholders = vec!["?"; ids.len()].join(",");
        let sql = format!(
            "SELECT order_id, sku, qty FROM order_items WHERE order_id IN ({placeholders}) ORDER BY id ASC"
        );
        let mut stmt = conn.prepare(&sql)?;
        let mapped = stmt.query_map(rusqlite::params_from_iter(ids.iter()), |r| {
            Ok((r.get::<_, i64>(0)?, r.get::<_, String>(1)?, r.get::<_, i64>(2)?))
        })?;
        for row in mapped {
            let (oid, sku, qty) = row?;
            items_by_order.entry(oid).or_default().push((sku, qty));
        }
        db_hits += 1;
    }

    let mut out = String::with_capacity(8192);
    out.push_str(r#"{"variant":"optimized","rows":["#);
    for (i, (oid, cid)) in orders.iter().enumerate() {
        let empty = Vec::new();
        let items = items_by_order.get(oid).unwrap_or(&empty);
        if i > 0 {
            out.push(',');
        }
        out.push_str(&render_order(*oid, *cid, items));
    }
    out.push_str(&format!(
        r#"],"db_hits":{db_hits},"elapsed_ms":{},"note":"1 SELECT orders + 1 SELECT items con IN(...) batch."}}"#,
        fmt_num(round2(start.elapsed().as_secs_f64() * 1000.0))
    ));
    Ok(out)
}

fn render_order(oid: i64, cid: i64, items: &[(String, i64)]) -> String {
    let rendered: Vec<String> = items
        .iter()
        .map(|(sku, qty)| format!(r#"{{"sku":"{}","qty":{qty}}}"#, escape(sku)))
        .collect();
    format!(
        r#"{{"order_id":{oid},"customer_id":{cid},"item_count":{},"items":[{}]}}"#,
        items.len(),
        rendered.join(",")
    )
}

fn diagnostics() -> rusqlite::Result<String> {
    let conn = open()?;
    let scalar = |sql: &str| -> rusqlite::Result<i64> { conn.query_row(sql, [], |r| r.get(0)) };
    let customers = scalar("SELECT COUNT(*) FROM customers")?;
    let categories = scalar("SELECT COUNT(*) FROM categories")?;
    let orders = scalar("SELECT COUNT(*) FROM orders")?;
    let items = scalar("SELECT COUNT(*) FROM order_items")?;
    let avg_items = if orders == 0 {
        0.0
    } else {
        round2(items as f64 / orders as f64)
    };
    Ok(format!(
        r#"{{"stack":"{}","case":"{CASE_NAME}","customers_total":{customers},"categories_total":{categories},"orders_total":{orders},"items_total":{items},"avg_items_per_order":{},"legacy":{},"optimized":{}}}"#,
        stack(),
        fmt_num(avg_items),
        metrics_snapshot("legacy", &LEGACY_SAMPLES, &LEGACY_REQUESTS),
        metrics_snapshot("optimized", &OPTIMIZED_SAMPLES, &OPTIMIZED_REQUESTS)
    ))
}

fn metrics_json() -> String {
    format!(
        r#"{{"legacy":{},"optimized":{}}}"#,
        metrics_snapshot("legacy", &LEGACY_SAMPLES, &LEGACY_REQUESTS),
        metrics_snapshot("optimized", &OPTIMIZED_SAMPLES, &OPTIMIZED_REQUESTS)
    )
}

fn metrics_snapshot(label: &str, samples: &Mutex<Vec<f64>>, requests: &AtomicI64) -> String {
    let snap = samples.lock().unwrap().clone();
    format!(
        r#"{{"label":"{label}","requests":{},"sample_count":{},"avg_ms":{},"p95_ms":{},"p99_ms":{}}}"#,
        requests.load(Ordering::Relaxed),
        snap.len(),
        fmt_num(avg(&snap)),
        fmt_num(percentile(&snap, 95)),
        fmt_num(percentile(&snap, 99))
    )
}

// ---------- schema y seed ----------

fn init_schema() -> rusqlite::Result<()> {
    let conn = open()?;
    conn.execute_batch(
        "CREATE TABLE categories (id INTEGER PRIMARY KEY, name TEXT NOT NULL);
         CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT NOT NULL, region TEXT NOT NULL, category_id INTEGER NOT NULL);
         CREATE TABLE orders (id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL, total REAL NOT NULL, created_at INTEGER NOT NULL);
         CREATE TABLE order_items (id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL, sku TEXT NOT NULL, qty INTEGER NOT NULL, price REAL NOT NULL);
         CREATE INDEX idx_items_order_id ON order_items (order_id);",
    )
}

/// Mismo LCG y mismos parametros que Java, .NET y Go: dataset identico.
fn seed_data() -> rusqlite::Result<()> {
    let regions = ["LATAM", "NA", "EMEA", "APAC"];
    let mut seed: i64 = 270718;
    let now = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .unwrap_or_default()
        .as_secs() as i64;

    let mut conn = open()?;
    let tx = conn.transaction()?;
    for i in 1..=24i64 {
        tx.execute(
            "INSERT INTO categories VALUES (?1, ?2)",
            params![i, format!("Category {i}")],
        )?;
    }
    for i in 1..=900i64 {
        seed = (seed * 9301 + 49297) % 233280;
        tx.execute(
            "INSERT INTO customers VALUES (?1, ?2, ?3, ?4)",
            params![
                i,
                format!("Customer {i}"),
                regions[(seed % 4) as usize],
                1 + ((i - 1) % 24)
            ],
        )?;
    }

    let mut item_id: i64 = 1;
    for order_id in 1..=1500i64 {
        seed = (seed * 9301 + 49297) % 233280;
        let cid = 1 + (seed % 900);
        seed = (seed * 9301 + 49297) % 233280;
        let created_at = now - (seed % (120 * 86400));
        let items_per_order = 2 + (seed % 4);

        let mut pending: Vec<(i64, i64, String, i64, f64)> = Vec::new();
        let mut total = 0.0f64;
        for _ in 0..items_per_order {
            seed = (seed * 9301 + 49297) % 233280;
            let sku = format!("SKU-{}", 1000 + (seed % 9000));
            let qty = 1 + (seed % 8);
            seed = (seed * 9301 + 49297) % 233280;
            let price = round2(10.0 + (seed % 233280) as f64 / 233280.0 * 220.0);
            total += qty as f64 * price;
            pending.push((item_id, order_id, sku, qty, price));
            item_id += 1;
        }

        tx.execute(
            "INSERT INTO orders VALUES (?1, ?2, ?3, ?4)",
            params![order_id, cid, round2(total), created_at],
        )?;
        for (id, oid, sku, qty, price) in pending {
            tx.execute(
                "INSERT INTO order_items VALUES (?1, ?2, ?3, ?4, ?5)",
                params![id, oid, sku, qty, price],
            )?;
        }
    }
    tx.commit()
}

// ---------- helpers ----------

fn internal(detail: &str) -> String {
    format!(r#"{{"error":"internal","detail":"{}"}}"#, escape(detail))
}

fn bounded(raw: Option<&str>, dflt: i64, min: i64, max: i64) -> i64 {
    raw.and_then(|r| r.parse::<i64>().ok()).unwrap_or(dflt).clamp(min, max)
}

fn round2(v: f64) -> f64 {
    (v * 100.0).round() / 100.0
}

fn fmt_num(v: f64) -> String {
    if (v - v.round()).abs() < f64::EPSILON {
        format!("{}", v.round() as i64)
    } else {
        let s = format!("{v:.2}");
        s.trim_end_matches('0').trim_end_matches('.').to_string()
    }
}

fn avg(values: &[f64]) -> f64 {
    if values.is_empty() {
        return 0.0;
    }
    round2(values.iter().sum::<f64>() / values.len() as f64)
}

fn percentile(values: &[f64], percent: usize) -> f64 {
    if values.is_empty() {
        return 0.0;
    }
    let mut ordered = values.to_vec();
    ordered.sort_by(|a, b| a.partial_cmp(b).unwrap_or(std::cmp::Ordering::Equal));
    let idx = ((percent as f64 / 100.0) * ordered.len() as f64).ceil() as usize;
    let idx = idx.saturating_sub(1).min(ordered.len() - 1);
    round2(ordered[idx])
}

fn escape(v: &str) -> String {
    v.replace('\\', "\\\\").replace('"', "\\\"")
}
