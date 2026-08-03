// Caso 11 — Reportes pesados que bloquean operacion — stack Rust 1.83.
//
// Legacy: el reporte corre sin acotar en el thread del request.
// Isolated: el reporte pasa por un limitador de concurrencia; como maximo N
// reportes corren a la vez y siempre queda CPU para la operacion.
//
// El contraste que este stack aporta, y por que este caso NO se traduce literal:
//
//   Java y .NET aislan con pools de threads separados. Go no tiene pools, asi
//   que usa un semaforo de concurrencia. Rust esta en el mismo lugar que Go:
//   `std::thread::spawn` crea un thread del SO por conexion, sin pool que
//   dimensionar, y `std` no trae un ExecutorService que copiar.
//
//   Pero hay una diferencia importante con Go que este caso hace visible:
//   **un thread de Rust es un thread del SO** (~8 MB de stack virtual, ~2-8 KB
//   residentes), mientras una goroutine arranca en ~2 KB y el runtime la
//   multiplexa. Go puede tener cien mil goroutines; Rust con `std::thread` no
//   puede tener cien mil threads. El modelo thread-per-connection de este caso
//   es honesto para un lab, y seria la primera cosa a cambiar en produccion —
//   ahi se usa `tokio`, que multiplexa tareas como Go multiplexa goroutines.
//
//   El limitador se implementa con `Mutex<usize>` + `Condvar`: el thread que
//   no consigue slot espera a ser despertado en vez de hacer busy-wait. Es la
//   primitiva que `std` da para esto, y es mas explicita que el canal de Go.

use std::collections::HashMap;
use std::io::{BufRead, BufReader, Write};
use std::net::{TcpListener, TcpStream};
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::{Condvar, Mutex};
use std::thread;
use std::time::{Duration, Instant};

const CASE_NAME: &str = "11 - Reportes pesados que bloquean operacion";
const REPORTING_SLOTS: usize = 2;
const DEGRADED_THRESHOLD_MS: i64 = 100;

static LEGACY_REPORTS: AtomicI64 = AtomicI64::new(0);
static ISOLATED_REPORTS: AtomicI64 = AtomicI64::new(0);
static ORDER_WRITES: AtomicI64 = AtomicI64::new(0);
static ORDER_WRITES_DEGRADED: AtomicI64 = AtomicI64::new(0);
static IN_FLIGHT: AtomicI64 = AtomicI64::new(0);
static REPORTING_WAITING: AtomicI64 = AtomicI64::new(0);

/// Limitador de concurrencia: Mutex con el conteo de slots libres + Condvar
/// para dormir al que espera en vez de girar en vacio.
static SLOTS_USED: Mutex<usize> = Mutex::new(0);
static SLOT_FREED: Condvar = Condvar::new();

fn acquire_slot() {
    let mut used = SLOTS_USED.lock().unwrap();
    while *used >= REPORTING_SLOTS {
        used = SLOT_FREED.wait(used).unwrap();
    }
    *used += 1;
}

fn release_slot() {
    let mut used = SLOTS_USED.lock().unwrap();
    *used = used.saturating_sub(1);
    SLOT_FREED.notify_one();
}

fn slots_used() -> usize {
    *SLOTS_USED.lock().unwrap()
}

fn stack() -> String {
    std::env::var("APP_STACK").unwrap_or_else(|_| "Rust 1.83".to_string())
}

fn main() {
    let port: u16 = std::env::var("PORT").ok().and_then(|p| p.parse().ok()).unwrap_or(8080);
    let listener = TcpListener::bind(("0.0.0.0", port)).expect("bind");
    println!(
        "[case11-rust] listening on {port} (cpus={})",
        thread::available_parallelism().map(|n| n.get()).unwrap_or(1)
    );
    for stream in listener.incoming().flatten() {
        thread::spawn(move || handle_conn(stream));
    }
}

// ---------- capa HTTP minima ----------

fn handle_conn(mut stream: TcpStream) {
    IN_FLIGHT.fetch_add(1, Ordering::Relaxed);
    let mut reader = BufReader::new(match stream.try_clone() {
        Ok(s) => s,
        Err(_) => {
            IN_FLIGHT.fetch_sub(1, Ordering::Relaxed);
            return;
        }
    });
    let mut request_line = String::new();
    if reader.read_line(&mut request_line).is_err() {
        IN_FLIGHT.fetch_sub(1, Ordering::Relaxed);
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
    IN_FLIGHT.fetch_sub(1, Ordering::Relaxed);
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
    let rows = bounded(params.get("rows").map(String::as_str), 200000, 1000, 5000000);

    match path {
        "/" | "/index" => (
            200,
            format!(
                r#"{{"case":"{CASE_NAME}","stack":"{}","routes":["/health","/report-legacy?rows=200000","/report-isolated?rows=200000","/order-write","/activity","/diagnostics/summary","/reset-lab"]}}"#,
                stack()
            ),
        ),
        "/health" => (
            200,
            format!(r#"{{"status":"ok","stack":"{}","case":"{CASE_NAME}"}}"#, stack()),
        ),
        "/report-legacy" => {
            let body = report_legacy(rows);
            LEGACY_REPORTS.fetch_add(1, Ordering::Relaxed);
            (200, body)
        }
        "/report-isolated" => {
            let body = report_isolated(rows);
            ISOLATED_REPORTS.fetch_add(1, Ordering::Relaxed);
            (200, body)
        }
        "/order-write" => {
            let body = order_write();
            ORDER_WRITES.fetch_add(1, Ordering::Relaxed);
            (200, body)
        }
        "/activity" => (200, activity()),
        "/diagnostics/summary" => (200, diagnostics()),
        "/reset-lab" => {
            LEGACY_REPORTS.store(0, Ordering::Relaxed);
            ISOLATED_REPORTS.store(0, Ordering::Relaxed);
            ORDER_WRITES.store(0, Ordering::Relaxed);
            ORDER_WRITES_DEGRADED.store(0, Ordering::Relaxed);
            (200, r#"{"status":"reset"}"#.to_string())
        }
        _ => (
            404,
            format!(r#"{{"error":"not_found","path":"{}"}}"#, escape(path)),
        ),
    }
}

// ---------- trabajo pesado ----------

fn crunch(rows: i64) -> i64 {
    let mut checksum: i64 = 0;
    for i in 0..rows {
        checksum += (i * 13) % 7;
        if (i & 0xFFFF) == 0 {
            thread::yield_now();
        }
    }
    checksum
}

/// Legacy: corre sin acotar en el thread del request.
fn report_legacy(rows: i64) -> String {
    let start = Instant::now();
    let checksum = crunch(rows);
    format!(
        r#"{{"variant":"legacy","rows":{rows},"checksum":{checksum},"elapsed_ms":{},"ran_on_pool":"request-thread (sin acotar)","main_pool_active":{},"main_pool_queue":{},"cpus":{},"note":"corre sin limite de concurrencia; mas reportes = menos CPU para /order-write."}}"#,
        start.elapsed().as_millis(),
        IN_FLIGHT.load(Ordering::Relaxed),
        REPORTING_WAITING.load(Ordering::Relaxed),
        cpus()
    )
}

/// Isolated: adquiere un slot; el que no consigue duerme en la Condvar.
fn report_isolated(rows: i64) -> String {
    let start = Instant::now();
    REPORTING_WAITING.fetch_add(1, Ordering::Relaxed);
    acquire_slot();
    REPORTING_WAITING.fetch_sub(1, Ordering::Relaxed);

    let checksum = crunch(rows);
    release_slot();

    format!(
        r#"{{"variant":"isolated","rows":{rows},"checksum":{checksum},"elapsed_ms":{},"ran_on_pool":"reporting-limiter (max {REPORTING_SLOTS} concurrentes)","main_pool_active":{},"main_pool_queue":{},"cpus":{},"note":"acotado por Mutex+Condvar; /order-write conserva CPU disponible."}}"#,
        start.elapsed().as_millis(),
        IN_FLIGHT.load(Ordering::Relaxed),
        REPORTING_WAITING.load(Ordering::Relaxed),
        cpus()
    )
}

fn order_write() -> String {
    let active_before = IN_FLIGHT.load(Ordering::Relaxed);
    let start = Instant::now();
    thread::sleep(Duration::from_millis(20));
    let elapsed_ms = start.elapsed().as_millis() as i64;

    let degraded = elapsed_ms > DEGRADED_THRESHOLD_MS;
    if degraded {
        ORDER_WRITES_DEGRADED.fetch_add(1, Ordering::Relaxed);
    }
    let note = if degraded {
        "la latencia subio por saturacion de CPU del proceso"
    } else {
        "operacion responde con latencia normal"
    };
    format!(
        r#"{{"variant":"order-write","elapsed_ms":{elapsed_ms},"degraded":{degraded},"main_pool_active_at_entry":{active_before},"note":"{note}"}}"#
    )
}

fn activity() -> String {
    format!(
        r#"{{"main_pool_active":{},"main_pool_queue":{},"main_pool_max":{},"reporting_slots":{REPORTING_SLOTS},"reporting_slots_used":{},"order_writes":{},"order_writes_degraded":{}}}"#,
        IN_FLIGHT.load(Ordering::Relaxed),
        REPORTING_WAITING.load(Ordering::Relaxed),
        cpus(),
        slots_used(),
        ORDER_WRITES.load(Ordering::Relaxed),
        ORDER_WRITES_DEGRADED.load(Ordering::Relaxed)
    )
}

fn diagnostics() -> String {
    format!(
        r#"{{"stack":"{}","case":"{CASE_NAME}","legacy":{{"reports":{},"behavior":"reporte sin acotar en el thread del request; /order-write pierde CPU"}},"isolated":{{"reports":{},"behavior":"Mutex+Condvar acota reportes simultaneos; /order-write intacto"}},"activity":{}}}"#,
        stack(),
        LEGACY_REPORTS.load(Ordering::Relaxed),
        ISOLATED_REPORTS.load(Ordering::Relaxed),
        activity()
    )
}

// ---------- helpers ----------

fn cpus() -> usize {
    thread::available_parallelism().map(|n| n.get()).unwrap_or(1)
}

fn bounded(raw: Option<&str>, dflt: i64, min: i64, max: i64) -> i64 {
    raw.and_then(|r| r.parse::<i64>().ok()).unwrap_or(dflt).clamp(min, max)
}

fn escape(v: &str) -> String {
    v.replace('\\', "\\\\").replace('"', "\\\"")
}
