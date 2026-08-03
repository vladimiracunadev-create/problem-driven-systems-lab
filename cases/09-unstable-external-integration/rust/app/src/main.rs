// Caso 09 — Integracion externa inestable — stack Rust 1.83.
//
// Legacy: cada request pega al provider sin cache, sin budget, sin breaker.
// Hardened: budget de cuota + snapshot cache + breaker + mapping defensivo.
//
// El contraste que este stack aporta:
//
//   `std` de Rust no tiene semaforo, igual que Go. Pero donde Go usa un canal
//   bufferizado, aca el budget es un `Mutex<i64>` con decremento condicional:
//
//       let mut permits = PROVIDER_BUDGET.lock().unwrap();
//       if *permits == 0 { return degradado; }
//       *permits -= 1;
//
//   Parece mas pobre que el `select` de Go, y en expresividad lo es. Pero hay
//   algo que Rust garantiza y Go no: **el guard del Mutex libera al salir de
//   scope, siempre**. En Go, un `mu.Lock()` sin su `defer mu.Unlock()` en un
//   camino de error es un deadlock silencioso que compila. Aca esa categoria
//   de bug no existe: no hay unlock que olvidar porque no hay unlock que
//   escribir.
//
//   La otra pieza es el `enum ServeSource { Provider, SnapshotCache }`: de
//   donde vino el dato es un tipo, no un string. Un `match` sobre el enum
//   obliga a contemplar ambos origenes al construir la respuesta.

use std::collections::HashMap;
use std::io::{BufRead, BufReader, Write};
use std::net::{TcpListener, TcpStream};
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::{LazyLock, Mutex, RwLock};
use std::thread;
use std::time::{SystemTime, UNIX_EPOCH};

const CASE_NAME: &str = "09 - Integracion externa inestable";
const BUDGET_PER_WINDOW: i64 = 5;

static LEGACY_CALLS: AtomicI64 = AtomicI64::new(0);
static LEGACY_FAILURES: AtomicI64 = AtomicI64::new(0);
static HARDENED_CALLS: AtomicI64 = AtomicI64::new(0);
static HARDENED_FROM_CACHE: AtomicI64 = AtomicI64::new(0);
static HARDENED_BUDGET_DENIED: AtomicI64 = AtomicI64::new(0);

/// Budget de cuota. El guard del Mutex libera al salir de scope: no hay
/// unlock que olvidar en el camino de error.
static PROVIDER_BUDGET: Mutex<i64> = Mutex::new(BUDGET_PER_WINDOW);

static BREAKER: Mutex<&'static str> = Mutex::new("closed");

static SNAPSHOT_CACHE: LazyLock<RwLock<HashMap<String, String>>> = LazyLock::new(|| {
    let mut m = HashMap::new();
    m.insert(
        "widget-A".to_string(),
        r#"{"name":"Widget A","price":42,"snapshot_at":"2026-05-01T00:00:00Z"}"#.to_string(),
    );
    m.insert(
        "widget-B".to_string(),
        r#"{"name":"Widget B","price":13.5,"snapshot_at":"2026-05-01T00:00:00Z"}"#.to_string(),
    );
    RwLock::new(m)
});

fn stack() -> String {
    std::env::var("APP_STACK").unwrap_or_else(|_| "Rust 1.83".to_string())
}

fn main() {
    let port: u16 = std::env::var("PORT").ok().and_then(|p| p.parse().ok()).unwrap_or(8080);
    let listener = TcpListener::bind(("0.0.0.0", port)).expect("bind");
    println!("[case09-rust] listening on {port}");
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
    let sku = params.get("sku").cloned().unwrap_or_else(|| "widget-A".into());
    let scenario = params.get("scenario").cloned().unwrap_or_else(|| "ok".into());

    match path {
        "/" | "/index" => (
            200,
            format!(
                r#"{{"case":"{CASE_NAME}","stack":"{}","routes":["/health","/catalog-legacy?sku=widget-A&scenario=drift","/catalog-hardened?sku=widget-A&scenario=drift","/sync-events","/diagnostics/summary","/reset-lab"]}}"#,
                stack()
            ),
        ),
        "/health" => (
            200,
            format!(r#"{{"status":"ok","stack":"{}","case":"{CASE_NAME}"}}"#, stack()),
        ),
        "/catalog-legacy" => {
            let body = catalog_legacy(&sku, &scenario);
            LEGACY_CALLS.fetch_add(1, Ordering::Relaxed);
            (200, body)
        }
        "/catalog-hardened" => {
            let body = catalog_hardened(&sku, &scenario);
            HARDENED_CALLS.fetch_add(1, Ordering::Relaxed);
            (200, body)
        }
        "/sync-events" => (200, state()),
        "/diagnostics/summary" => (200, diagnostics()),
        "/reset-lab" => {
            LEGACY_CALLS.store(0, Ordering::Relaxed);
            LEGACY_FAILURES.store(0, Ordering::Relaxed);
            HARDENED_CALLS.store(0, Ordering::Relaxed);
            HARDENED_FROM_CACHE.store(0, Ordering::Relaxed);
            HARDENED_BUDGET_DENIED.store(0, Ordering::Relaxed);
            *PROVIDER_BUDGET.lock().unwrap() = BUDGET_PER_WINDOW;
            *BREAKER.lock().unwrap() = "closed";
            (200, r#"{"status":"reset"}"#.to_string())
        }
        _ => (
            404,
            format!(r#"{{"error":"not_found","path":"{}"}}"#, escape(path)),
        ),
    }
}

// ---------- endpoints ----------

fn is_drift(scenario: &str) -> bool {
    scenario == "drift" || scenario == "rate_limit" || scenario == "maintenance"
}

fn catalog_legacy(sku: &str, scenario: &str) -> String {
    if is_drift(scenario) {
        LEGACY_FAILURES.fetch_add(1, Ordering::Relaxed);
        return format!(
            r#"{{"variant":"legacy","sku":"{}","status":"failed","scenario":"{}","note":"provider devuelve drift de esquema / rate limit / maintenance; sin cache, falla."}}"#,
            escape(sku),
            escape(scenario)
        );
    }
    format!(
        r#"{{"variant":"legacy","sku":"{}","status":"ok","data":{{"name":"{} Live","price":42}},"note":"hit directo al provider, sin budget ni cache."}}"#,
        escape(sku),
        escape(sku)
    )
}

/// Intenta consumir un permiso. El guard libera al salir de scope pase lo que
/// pase — no hay unlock que olvidar.
fn try_acquire_budget() -> bool {
    let mut permits = PROVIDER_BUDGET.lock().unwrap();
    if *permits <= 0 {
        return false;
    }
    *permits -= 1;
    true
}

fn budget_remaining() -> i64 {
    *PROVIDER_BUDGET.lock().unwrap()
}

fn catalog_hardened(sku: &str, scenario: &str) -> String {
    if !try_acquire_budget() {
        HARDENED_BUDGET_DENIED.fetch_add(1, Ordering::Relaxed);
        return from_snapshot(sku, "budget_exhausted", "budget de cuota agotado; sirviendo snapshot cacheado.");
    }
    // El permiso NO se devuelve: cuenta como uso de la ventana.

    if is_drift(scenario) {
        *BREAKER.lock().unwrap() = "open";
        return from_snapshot(sku, "provider_failing", "provider con drift/rate_limit/maintenance; snapshot cacheado.");
    }

    let fresh = format!(
        r#"{{"name":"{} Live","price":42,"snapshot_at":"{}"}}"#,
        escape(sku),
        rfc3339_now()
    );
    SNAPSHOT_CACHE
        .write()
        .unwrap()
        .insert(sku.to_string(), fresh.clone());
    *BREAKER.lock().unwrap() = "closed";

    format!(
        r#"{{"variant":"hardened","sku":"{}","status":"ok","data":{fresh},"served_from":"provider","budget_remaining":{},"breaker":"{}"}}"#,
        escape(sku),
        budget_remaining(),
        breaker_name()
    )
}

fn from_snapshot(sku: &str, reason: &str, note: &str) -> String {
    HARDENED_FROM_CACHE.fetch_add(1, Ordering::Relaxed);
    let cached = SNAPSHOT_CACHE
        .read()
        .unwrap()
        .get(sku)
        .cloned()
        .unwrap_or_else(|| "null".to_string());
    format!(
        r#"{{"variant":"hardened","sku":"{}","status":"served_from_cache","reason":"{reason}","data":{cached},"served_from":"snapshot_cache","budget_remaining":{},"breaker":"{}","note":"{note}"}}"#,
        escape(sku),
        budget_remaining(),
        breaker_name()
    )
}

fn breaker_name() -> &'static str {
    *BREAKER.lock().unwrap()
}

fn state() -> String {
    format!(
        r#"{{"breaker":"{}","budget_remaining":{},"budget_max":{BUDGET_PER_WINDOW},"snapshot_cache_size":{}}}"#,
        breaker_name(),
        budget_remaining(),
        SNAPSHOT_CACHE.read().unwrap().len()
    )
}

fn diagnostics() -> String {
    format!(
        r#"{{"stack":"{}","case":"{CASE_NAME}","legacy":{{"calls":{},"failures":{}}},"hardened":{{"calls":{},"served_from_cache":{},"budget_denied":{}}},"state":{}}}"#,
        stack(),
        LEGACY_CALLS.load(Ordering::Relaxed),
        LEGACY_FAILURES.load(Ordering::Relaxed),
        HARDENED_CALLS.load(Ordering::Relaxed),
        HARDENED_FROM_CACHE.load(Ordering::Relaxed),
        HARDENED_BUDGET_DENIED.load(Ordering::Relaxed),
        state()
    )
}

// ---------- helpers ----------

fn rfc3339_now() -> String {
    let d = SystemTime::now().duration_since(UNIX_EPOCH).unwrap_or_default();
    format!("{}.{:09}Z", epoch_to_iso(d.as_secs()), d.subsec_nanos())
}

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
    let months = [31, if leap { 29 } else { 28 }, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
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

fn escape(v: &str) -> String {
    v.replace('\\', "\\\\").replace('"', "\\\"")
}
