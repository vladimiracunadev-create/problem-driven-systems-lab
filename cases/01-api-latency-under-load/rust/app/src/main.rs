// Caso 01 — API lenta bajo carga (stack Rust 1.83).
//
// Espejo del Main.java / Program.cs / main.go equivalentes: mismos endpoints,
// misma semantica, mismo shape de JSON, mismo dataset.
//
// Substrato real: SQLite embebido via rusqlite con feature `bundled` — compila
// SQLite desde fuente dentro del binario, sin depender de libsqlite3 del
// sistema. Archivo bajo /tmp con journal_mode=WAL: el worker escribe
// customer_summary mientras los handlers leen sin bloquearse, el equivalente
// embebido del MVCC que da PostgreSQL en el stack PHP.
//
// Lo que este stack muestra y ningun otro del lab puede:
//
//   - `std` de Rust NO trae servidor HTTP ni serializador JSON. Java tiene
//     com.sun.net.httpserver, .NET tiene HttpListener, Go tiene net/http,
//     Node y Python los traen de fabrica. Aca el servidor se escribe sobre
//     TcpListener en ~60 lineas. Es la diferencia mas honesta del lenguaje:
//     Rust da control y cero runtime, y a cambio pide construir la capa que
//     los otros regalan.
//
//   - Ownership y Drop reemplazan al try-with-resources, al using y al defer.
//     No hace falta escribir el cierre: cuando la `Connection` sale de scope,
//     su destructor corre. Es el unico stack donde la liberacion es una
//     propiedad del tipo, no una construccion que el autor debe recordar.

use rusqlite::{params, Connection};
use std::collections::HashMap;
use std::io::{BufRead, BufReader, Write};
use std::net::{TcpListener, TcpStream};
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::Mutex;
use std::thread;
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

const CASE_NAME: &str = "01 - API lenta bajo carga";
const WORKER_NAME: &str = "report-refresh-rust";
const SUMMARY_REFRESH_SECS: u64 = 5;
const MAX_SAMPLES: usize = 3000;
const MAX_JOB_RUNS: i64 = 30;

static LEGACY_REQUESTS: AtomicI64 = AtomicI64::new(0);
static OPTIMIZED_REQUESTS: AtomicI64 = AtomicI64::new(0);

static LEGACY_SAMPLES: Mutex<Vec<f64>> = Mutex::new(Vec::new());
static OPTIMIZED_SAMPLES: Mutex<Vec<f64>> = Mutex::new(Vec::new());

fn stack() -> String {
    std::env::var("APP_STACK").unwrap_or_else(|_| "Rust 1.83".to_string())
}

fn db_path() -> String {
    let dir = std::env::temp_dir().join("pdsl-case01-rust");
    let _ = std::fs::create_dir_all(&dir);
    dir.join("case01.sqlite3").to_string_lossy().to_string()
}

/// Conexion nueva por unidad de trabajo. Al salir de scope, Drop la cierra:
/// no hay try-with-resources ni using ni defer que escribir.
fn open() -> rusqlite::Result<Connection> {
    let conn = Connection::open(db_path())?;
    conn.pragma_update(None, "journal_mode", "WAL")?;
    conn.pragma_update(None, "busy_timeout", 5000)?;
    Ok(conn)
}

// ---------- arranque ----------

fn main() {
    // Arranque limpio y determinista: se borra la DB y los sidecars de WAL.
    let base = db_path();
    for suffix in ["", "-wal", "-shm"] {
        let _ = std::fs::remove_file(format!("{base}{suffix}"));
    }

    init_schema().expect("init_schema");
    seed_data().expect("seed_data");
    refresh_summary();

    thread::spawn(|| loop {
        thread::sleep(Duration::from_secs(SUMMARY_REFRESH_SECS));
        refresh_summary();
    });

    let port: u16 = std::env::var("PORT")
        .ok()
        .and_then(|p| p.parse().ok())
        .unwrap_or(8080);
    let listener = TcpListener::bind(("0.0.0.0", port)).expect("bind");
    println!("[case01-rust] listening on {port}");

    for stream in listener.incoming() {
        match stream {
            Ok(s) => {
                thread::spawn(move || handle_conn(s));
            }
            Err(e) => eprintln!("[case01-rust] accept error: {e}"),
        }
    }
}

// ---------- capa HTTP minima sobre TcpListener ----------

fn handle_conn(mut stream: TcpStream) {
    let mut reader = BufReader::new(match stream.try_clone() {
        Ok(s) => s,
        Err(_) => return,
    });
    let mut request_line = String::new();
    if reader.read_line(&mut request_line).is_err() {
        return;
    }
    // Drenar cabeceras hasta la linea en blanco.
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
    let (path, query) = match target.split_once('?') {
        Some((p, q)) => (p, q),
        None => (target, ""),
    };
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
        out.insert(url_decode(k), url_decode(v));
    }
    out
}

fn url_decode(s: &str) -> String {
    let bytes = s.as_bytes();
    let mut out = Vec::with_capacity(bytes.len());
    let mut i = 0;
    while i < bytes.len() {
        match bytes[i] {
            b'%' if i + 2 < bytes.len() => {
                if let Ok(b) = u8::from_str_radix(&s[i + 1..i + 3], 16) {
                    out.push(b);
                    i += 3;
                    continue;
                }
                out.push(bytes[i]);
                i += 1;
            }
            b'+' => {
                out.push(b' ');
                i += 1;
            }
            b => {
                out.push(b);
                i += 1;
            }
        }
    }
    String::from_utf8_lossy(&out).to_string()
}

// ---------- routing ----------

fn route(path: &str, params: &HashMap<String, String>) -> (u16, String) {
    let limit = bounded(params.get("limit").map(String::as_str), 20, 1, 200);
    let start = Instant::now();

    let result: Result<(String, Option<&Mutex<Vec<f64>>>), String> = match path {
        "/" | "/index" => Ok((index_json(), None)),
        "/health" => Ok((
            format!(
                r#"{{"status":"ok","stack":"{}","case":"{CASE_NAME}"}}"#,
                stack()
            ),
            None,
        )),
        "/report-legacy" => report_legacy(limit)
            .map(|b| (b, Some(&LEGACY_SAMPLES)))
            .map_err(|e| e.to_string()),
        "/report-optimized" => report_optimized(limit)
            .map(|b| (b, Some(&OPTIMIZED_SAMPLES)))
            .map_err(|e| e.to_string()),
        "/batch/status" => worker_state_json().map(|b| (b, None)).map_err(|e| e.to_string()),
        "/job-runs" => job_runs_json().map(|b| (b, None)).map_err(|e| e.to_string()),
        "/diagnostics/summary" => diagnostics_json().map(|b| (b, None)).map_err(|e| e.to_string()),
        "/metrics" => Ok((metrics_json(), None)),
        "/reset-lab" => {
            LEGACY_REQUESTS.store(0, Ordering::Relaxed);
            OPTIMIZED_REQUESTS.store(0, Ordering::Relaxed);
            LEGACY_SAMPLES.lock().unwrap().clear();
            OPTIMIZED_SAMPLES.lock().unwrap().clear();
            let _ = open().and_then(|c| c.execute("DELETE FROM job_runs", []));
            Ok((
                format!(r#"{{"status":"reset","stack":"{}"}}"#, stack()),
                None,
            ))
        }
        _ => {
            return (
                404,
                format!(r#"{{"error":"not_found","path":"{}"}}"#, escape(path)),
            )
        }
    };

    match result {
        Ok((body, tracked)) => {
            let elapsed = round2(start.elapsed().as_secs_f64() * 1000.0);
            if let Some(samples) = tracked {
                if path == "/report-legacy" {
                    LEGACY_REQUESTS.fetch_add(1, Ordering::Relaxed);
                } else {
                    OPTIMIZED_REQUESTS.fetch_add(1, Ordering::Relaxed);
                }
                let mut s = samples.lock().unwrap();
                s.push(elapsed);
                if s.len() > MAX_SAMPLES {
                    let excess = s.len() - MAX_SAMPLES;
                    s.drain(0..excess);
                }
            }
            (200, body)
        }
        Err(detail) => (
            200,
            format!(r#"{{"error":"internal","detail":"{}"}}"#, escape(&detail)),
        ),
    }
}

fn index_json() -> String {
    format!(
        r#"{{"lab":"Problem-Driven Systems Lab","case":"{CASE_NAME}","stack":"{}","substrate":"SQLite embebido via rusqlite bundled (WAL, archivo en /tmp)","native_primitives":["Ownership + Drop (cierre sin defer/using/try-with-resources)","rusqlite bundled (SQLite compilado dentro del binario)","std::thread (worker y conexiones)","AtomicI64 + Mutex (metricas)"],"routes":{{"/health":"liveness check","/report-legacy?limit=20":"filtro no sargable (LOWER sobre la columna) + N+1 real","/report-optimized?limit=20":"rango sargable + batch IN(...) + lectura de customer_summary","/batch/status":"estado del worker","/job-runs":"historial de corridas del worker","/diagnostics/summary":"contraste legacy vs optimized","/metrics":"avg/p95/p99 por ruta","/reset-lab":"reinicia contadores e historico"}}}}"#,
        stack()
    )
}

// ---------- endpoints ----------

/// Legacy: filtro no sargable — LOWER(region) envuelve la columna e impide usar
/// idx_orders_region. Despues, N+1 real: una query dependiente por fila.
fn report_legacy(limit: i64) -> rusqlite::Result<String> {
    let start = Instant::now();
    let mut db_hits: i64 = 0;
    let conn = open()?;

    let rows: Vec<(i64, i64, String, f64)> = {
        let mut stmt = conn.prepare(
            "SELECT id, customer_id, region, amount FROM orders \
             WHERE LOWER(region) LIKE 'n%' ORDER BY id LIMIT ?1",
        )?;
        let mapped = stmt.query_map(params![limit], |r| {
            Ok((r.get(0)?, r.get(1)?, r.get(2)?, r.get(3)?))
        })?;
        mapped.collect::<rusqlite::Result<Vec<_>>>()?
    };
    db_hits += 1;

    let mut out = String::with_capacity(8192);
    out.push_str(r#"{"variant":"legacy","rows":["#);
    for (i, (id, cid, region, amount)) in rows.iter().enumerate() {
        let (name, tier): (String, String) = conn
            .query_row(
                "SELECT name, tier FROM customers WHERE id = ?1",
                params![cid],
                |r| Ok((r.get(0)?, r.get(1)?)),
            )
            .unwrap_or_else(|_| (String::new(), String::new()));
        db_hits += 1;
        if i > 0 {
            out.push(',');
        }
        out.push_str(&format!(
            r#"{{"order_id":{id},"customer":"{}","tier":"{}","region":"{}","amount":{}}}"#,
            escape(&name),
            escape(&tier),
            escape(region),
            fmt_num(*amount)
        ));
    }
    out.push_str(&format!(
        r#"],"db_hits":{db_hits},"elapsed_ms":{},"note":"LOWER(region) invalida el indice + N+1 real: 1 + N queries contra SQLite."}}"#,
        fmt_num(round2(start.elapsed().as_secs_f64() * 1000.0))
    ));
    Ok(out)
}

/// Optimized: el mismo filtro reescrito como rango sargable + dos batches
/// IN(...) + lectura de customer_summary que el worker mantiene.
fn report_optimized(limit: i64) -> rusqlite::Result<String> {
    let start = Instant::now();
    let mut db_hits: i64 = 0;
    let conn = open()?;

    let rows: Vec<(i64, i64, String, f64)> = {
        let mut stmt = conn.prepare(
            "SELECT id, customer_id, region, amount FROM orders \
             WHERE region >= 'n' AND region < 'o' ORDER BY id LIMIT ?1",
        )?;
        let mapped = stmt.query_map(params![limit], |r| {
            Ok((r.get(0)?, r.get(1)?, r.get(2)?, r.get(3)?))
        })?;
        mapped.collect::<rusqlite::Result<Vec<_>>>()?
    };
    db_hits += 1;

    let mut customers: HashMap<i64, (String, String)> = HashMap::new();
    let mut summaries: HashMap<i64, (i64, f64)> = HashMap::new();

    if !rows.is_empty() {
        let ids: Vec<i64> = rows.iter().map(|r| r.1).collect();
        let placeholders = vec!["?"; ids.len()].join(",");

        {
            let sql = format!("SELECT id, name, tier FROM customers WHERE id IN ({placeholders})");
            let mut stmt = conn.prepare(&sql)?;
            let mapped = stmt.query_map(rusqlite::params_from_iter(ids.iter()), |r| {
                Ok((r.get::<_, i64>(0)?, r.get::<_, String>(1)?, r.get::<_, String>(2)?))
            })?;
            for row in mapped {
                let (id, name, tier) = row?;
                customers.insert(id, (name, tier));
            }
        }
        db_hits += 1;

        {
            let sql = format!(
                "SELECT customer_id, order_count, total_amount FROM customer_summary WHERE customer_id IN ({placeholders})"
            );
            let mut stmt = conn.prepare(&sql)?;
            let mapped = stmt.query_map(rusqlite::params_from_iter(ids.iter()), |r| {
                Ok((r.get::<_, i64>(0)?, r.get::<_, i64>(1)?, r.get::<_, f64>(2)?))
            })?;
            for row in mapped {
                let (cid, count, total) = row?;
                summaries.insert(cid, (count, total));
            }
        }
        db_hits += 1;
    }

    let mut out = String::with_capacity(8192);
    out.push_str(r#"{"variant":"optimized","rows":["#);
    for (i, (id, cid, region, amount)) in rows.iter().enumerate() {
        let empty = (String::new(), String::new());
        let (name, tier) = customers.get(cid).unwrap_or(&empty);
        let (count, total) = summaries.get(cid).copied().unwrap_or((0, 0.0));
        if i > 0 {
            out.push(',');
        }
        out.push_str(&format!(
            r#"{{"order_id":{id},"customer":"{}","tier":"{}","region":"{}","amount":{},"lifetime_orders":{count},"lifetime_amount":{}}}"#,
            escape(name),
            escape(tier),
            escape(region),
            fmt_num(*amount),
            fmt_num(total)
        ));
    }

    let summary_size: i64 = conn.query_row("SELECT COUNT(*) FROM customer_summary", [], |r| r.get(0))?;
    db_hits += 1;

    out.push_str(&format!(
        r#"],"db_hits":{db_hits},"elapsed_ms":{},"summary_cache_size":{summary_size},"note":"Rango sargable + 2 batches IN(...) + customer_summary mantenida por el worker."}}"#,
        fmt_num(round2(start.elapsed().as_secs_f64() * 1000.0))
    ));
    Ok(out)
}

fn worker_state_json() -> rusqlite::Result<String> {
    let conn = open()?;
    let row = conn.query_row(
        "SELECT last_status, last_duration_ms, COALESCE(last_message,''), COALESCE(last_heartbeat,'') \
         FROM worker_state WHERE worker_name = ?1",
        params![WORKER_NAME],
        |r| {
            Ok((
                r.get::<_, String>(0)?,
                r.get::<_, i64>(1)?,
                r.get::<_, String>(2)?,
                r.get::<_, String>(3)?,
            ))
        },
    );
    Ok(match row {
        Ok((status, dur, msg, hb)) => format!(
            r#"{{"worker_name":"{WORKER_NAME}","last_status":"{}","last_duration_ms":{dur},"last_message":"{}","last_heartbeat":"{}"}}"#,
            escape(&status),
            escape(&msg),
            escape(&hb)
        ),
        Err(_) => format!(
            r#"{{"worker_name":"{WORKER_NAME}","last_status":"unknown","last_duration_ms":-1,"last_message":"","last_heartbeat":""}}"#
        ),
    })
}

fn job_runs_json() -> rusqlite::Result<String> {
    let conn = open()?;
    let mut stmt = conn.prepare(
        "SELECT at, status, duration_ms, customers_refreshed FROM job_runs ORDER BY id DESC LIMIT ?1",
    )?;
    let mapped = stmt.query_map(params![MAX_JOB_RUNS], |r| {
        Ok((
            r.get::<_, String>(0)?,
            r.get::<_, String>(1)?,
            r.get::<_, i64>(2)?,
            r.get::<_, i64>(3)?,
        ))
    })?;
    let mut out = String::from(r#"{"runs":["#);
    for (i, row) in mapped.enumerate() {
        let (at, status, dur, refreshed) = row?;
        if i > 0 {
            out.push(',');
        }
        out.push_str(&format!(
            r#"{{"at":"{}","status":"{}","duration_ms":{dur},"customers_refreshed":{refreshed}}}"#,
            escape(&at),
            escape(&status)
        ));
    }
    out.push_str(&format!(r#"],"max_runs_kept":{MAX_JOB_RUNS}}}"#));
    Ok(out)
}

fn diagnostics_json() -> rusqlite::Result<String> {
    let conn = open()?;
    let summary_size: i64 = conn.query_row("SELECT COUNT(*) FROM customer_summary", [], |r| r.get(0))?;
    Ok(format!(
        r#"{{"stack":"{}","case":"{CASE_NAME}","substrate":"SQLite embebido (rusqlite bundled, WAL)","legacy":{},"optimized":{},"summary_cache_size":{summary_size},"worker":{}}}"#,
        stack(),
        metrics_snapshot("legacy", &LEGACY_SAMPLES, &LEGACY_REQUESTS),
        metrics_snapshot("optimized", &OPTIMIZED_SAMPLES, &OPTIMIZED_REQUESTS),
        worker_state_json()?
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

// ---------- worker ----------

/// DELETE + INSERT ... SELECT reales dentro de una transaccion. Gracias a WAL
/// los lectores siguen respondiendo mientras esta transaccion escribe.
fn refresh_summary() {
    let start = Instant::now();
    let mut conn = match open() {
        Ok(c) => c,
        Err(e) => {
            eprintln!("[case01-rust] worker error (open): {e}");
            return;
        }
    };
    let tx = match conn.transaction() {
        Ok(t) => t,
        Err(e) => {
            eprintln!("[case01-rust] worker error (tx): {e}");
            return;
        }
    };

    let refreshed = (|| -> rusqlite::Result<i64> {
        tx.execute("DELETE FROM customer_summary", [])?;
        let n = tx.execute(
            "INSERT INTO customer_summary (customer_id, order_count, total_amount, refreshed_at) \
             SELECT customer_id, COUNT(*), ROUND(SUM(amount), 2), strftime('%s','now') \
             FROM orders GROUP BY customer_id",
            [],
        )?;
        Ok(n as i64)
    })();

    let refreshed = match refreshed {
        Ok(n) => n,
        Err(e) => {
            eprintln!("[case01-rust] worker error (refresh): {e}");
            return;
        }
    };

    let dur_ms = start.elapsed().as_millis() as i64;
    let now = rfc3339_now();
    let _ = tx.execute(
        "UPDATE worker_state SET last_status = ?1, last_duration_ms = ?2, last_message = ?3, last_heartbeat = ?4 \
         WHERE worker_name = ?5",
        params![
            "ok",
            dur_ms,
            format!("refreshed {refreshed} customer summaries"),
            now,
            WORKER_NAME
        ],
    );
    let _ = tx.execute(
        "INSERT INTO job_runs (at, status, duration_ms, customers_refreshed) VALUES (?1, ?2, ?3, ?4)",
        params![now, "ok", dur_ms, refreshed],
    );
    let _ = tx.execute(
        "DELETE FROM job_runs WHERE id NOT IN (SELECT id FROM job_runs ORDER BY id DESC LIMIT ?1)",
        params![MAX_JOB_RUNS],
    );
    if let Err(e) = tx.commit() {
        eprintln!("[case01-rust] worker error (commit): {e}");
    }
}

// ---------- schema y seed ----------

fn init_schema() -> rusqlite::Result<()> {
    let conn = open()?;
    conn.execute_batch(
        "CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT NOT NULL, tier TEXT NOT NULL);
         CREATE TABLE orders (id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL, region TEXT NOT NULL, amount REAL NOT NULL);
         CREATE TABLE customer_summary (customer_id INTEGER PRIMARY KEY, order_count INTEGER NOT NULL, total_amount REAL NOT NULL, refreshed_at INTEGER NOT NULL);
         CREATE TABLE worker_state (worker_name TEXT PRIMARY KEY, last_status TEXT NOT NULL, last_duration_ms INTEGER NOT NULL, last_message TEXT, last_heartbeat TEXT);
         CREATE TABLE job_runs (id INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT NOT NULL, status TEXT NOT NULL, duration_ms INTEGER NOT NULL, customers_refreshed INTEGER NOT NULL);
         -- El indice que la ruta legacy desperdicia al envolver la columna en LOWER().
         CREATE INDEX idx_orders_region ON orders (region, id);
         CREATE INDEX idx_orders_customer ON orders (customer_id);",
    )
}

/// Mismo LCG y mismos parametros que Java, .NET y Go: dataset identico.
fn seed_data() -> rusqlite::Result<()> {
    let regions = ["north", "south", "east", "west"];
    let tiers = ["bronze", "silver", "gold"];
    let mut seed: i64 = 102030;

    let mut conn = open()?;
    let tx = conn.transaction()?;
    for i in 1..=1600i64 {
        seed = (seed * 9301 + 49297) % 233280;
        tx.execute(
            "INSERT INTO customers VALUES (?1, ?2, ?3)",
            params![i, format!("Customer {i}"), tiers[(seed % 3) as usize]],
        )?;
    }
    for i in 1..=4800i64 {
        seed = (seed * 9301 + 49297) % 233280;
        let cid = 1 + (seed % 1600);
        let region = regions[((seed / 7) % 4) as usize];
        let amount = round2(20.0 + (seed % 1000) as f64);
        tx.execute(
            "INSERT INTO orders VALUES (?1, ?2, ?3, ?4)",
            params![i, cid, region, amount],
        )?;
    }
    tx.execute(
        "INSERT INTO worker_state VALUES (?1, ?2, ?3, ?4, ?5)",
        params![WORKER_NAME, "init", -1i64, "worker not started yet", ""],
    )?;
    tx.commit()
}

// ---------- helpers ----------

fn rfc3339_now() -> String {
    let d = SystemTime::now().duration_since(UNIX_EPOCH).unwrap_or_default();
    format!("{}.{:09}Z", epoch_to_iso(d.as_secs()), d.subsec_nanos())
}

/// Conversion epoch → ISO-8601 sin dependencias (std no trae formato de fecha).
fn epoch_to_iso(secs: u64) -> String {
    let days = secs / 86400;
    let rem = secs % 86400;
    let (h, m, s) = (rem / 3600, (rem % 3600) / 60, rem % 60);

    let mut year = 1970u64;
    let mut d = days;
    loop {
        let leap = (year % 4 == 0 && year % 100 != 0) || year % 400 == 0;
        let len = if leap { 366 } else { 365 };
        if d < len {
            break;
        }
        d -= len;
        year += 1;
    }
    let leap = (year % 4 == 0 && year % 100 != 0) || year % 400 == 0;
    let months = [
        31,
        if leap { 29 } else { 28 },
        31, 30, 31, 30, 31, 31, 30, 31, 30, 31,
    ];
    let mut month = 1;
    for len in months {
        if d < len {
            break;
        }
        d -= len;
        month += 1;
    }
    format!("{year:04}-{month:02}-{:02}T{h:02}:{m:02}:{s:02}", d + 1)
}

fn bounded(raw: Option<&str>, dflt: i64, min: i64, max: i64) -> i64 {
    let n = raw.and_then(|r| r.parse::<i64>().ok()).unwrap_or(dflt);
    n.clamp(min, max)
}

fn round2(v: f64) -> f64 {
    (v * 100.0).round() / 100.0
}

/// Formato numerico estable: enteros sin `.0`, decimales con hasta 2 cifras.
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
