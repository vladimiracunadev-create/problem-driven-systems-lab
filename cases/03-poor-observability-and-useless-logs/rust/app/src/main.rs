// Caso 03 — Observabilidad deficiente y logs inutiles (stack Rust 1.83).
//
// Legacy: println opaco, sin correlation, sin contexto.
// Observable: log estructurado JSON con correlation_id propagado + /logs.
//
// El contraste que este stack aporta:
//
//   Java propaga el contexto con ThreadLocal, .NET con AsyncLocal, Node con
//   AsyncLocalStorage. Los tres son **almacenamiento ambiente**: la funcion lee
//   un valor que alguien dejo en el hilo. Go lo hace explicito con
//   context.Context como parametro.
//
//   Rust va un paso mas alla que Go: el contexto se pasa como `&RequestCtx`, y
//   el **borrow checker garantiza que la referencia no sobreviva al request**.
//   No es solo que sea explicito — es que guardarse el contexto en una
//   estructura de vida mas larga no compila. En los modelos ambiente, un
//   contexto que sobrevive a su request es una fuga silenciosa de datos de un
//   usuario hacia el siguiente; aca el compilador la rechaza.
//
//   Contrapartida honesta: `std` no trae logger estructurado. Go tiene
//   log/slog en la stdlib desde 1.21; en Rust el ecosistema usa `tracing` o
//   `log`, y para mantener el caso sin dependencias el JSON se construye a
//   mano. Es menos ergonomico y vale decirlo.

use std::collections::HashMap;
use std::io::{BufRead, BufReader, Write};
use std::net::{TcpListener, TcpStream};
use std::sync::atomic::{AtomicI64, AtomicU64, Ordering};
use std::sync::Mutex;
use std::thread;
use std::time::{SystemTime, UNIX_EPOCH};

const CASE_NAME: &str = "03 - Observabilidad deficiente y logs inutiles";
const MAX_LOG_ENTRIES: usize = 200;

static LEGACY_REQUESTS: AtomicI64 = AtomicI64::new(0);
static LEGACY_ERRORS: AtomicI64 = AtomicI64::new(0);
static OBSERVABLE_REQUESTS: AtomicI64 = AtomicI64::new(0);
static OBSERVABLE_ERRORS: AtomicI64 = AtomicI64::new(0);
static CORR_SEQ: AtomicU64 = AtomicU64::new(0);

static RECENT_LOGS: Mutex<Vec<String>> = Mutex::new(Vec::new());

fn stack() -> String {
    std::env::var("APP_STACK").unwrap_or_else(|_| "Rust 1.83".to_string())
}

/// Contexto de request. Se pasa por referencia: el borrow checker impide que
/// una referencia a este valor sobreviva al handler que lo creo.
struct RequestCtx {
    correlation_id: String,
    route: &'static str,
}

fn main() {
    let port: u16 = std::env::var("PORT").ok().and_then(|p| p.parse().ok()).unwrap_or(8080);
    let listener = TcpListener::bind(("0.0.0.0", port)).expect("bind");
    println!("[case03-rust] listening on {port}");
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
    let total_raw = params.get("total").map(String::as_str).unwrap_or("100");

    match path {
        "/" | "/index" => (
            200,
            format!(
                r#"{{"case":"{CASE_NAME}","stack":"{}","routes":["/health","/checkout-legacy?total=100","/checkout-observable?total=100","/logs","/metrics","/diagnostics/summary","/reset-lab"]}}"#,
                stack()
            ),
        ),
        "/health" => (
            200,
            format!(r#"{{"status":"ok","stack":"{}","case":"{CASE_NAME}"}}"#, stack()),
        ),
        "/checkout-legacy" => {
            let body = checkout_legacy(total_raw);
            LEGACY_REQUESTS.fetch_add(1, Ordering::Relaxed);
            (200, body)
        }
        "/checkout-observable" => {
            let body = checkout_observable(total_raw);
            OBSERVABLE_REQUESTS.fetch_add(1, Ordering::Relaxed);
            (200, body)
        }
        "/logs" => {
            let logs = RECENT_LOGS.lock().unwrap();
            (
                200,
                format!(
                    r#"{{"entries":[{}],"max_kept":{MAX_LOG_ENTRIES}}}"#,
                    logs.join(",")
                ),
            )
        }
        "/metrics" | "/diagnostics/summary" => (200, diagnostics()),
        "/reset-lab" => {
            LEGACY_REQUESTS.store(0, Ordering::Relaxed);
            LEGACY_ERRORS.store(0, Ordering::Relaxed);
            OBSERVABLE_REQUESTS.store(0, Ordering::Relaxed);
            OBSERVABLE_ERRORS.store(0, Ordering::Relaxed);
            RECENT_LOGS.lock().unwrap().clear();
            (200, r#"{"status":"reset"}"#.to_string())
        }
        _ => (
            404,
            format!(r#"{{"error":"not_found","path":"{}"}}"#, escape(path)),
        ),
    }
}

// ---------- endpoints ----------

/// Legacy: log opaco. La funcion no recibe contexto — y esa es la señal: no
/// tiene forma de correlacionar nada aunque quisiera.
fn checkout_legacy(total_raw: &str) -> String {
    let total = total_raw.parse::<f64>().unwrap_or(100.0);
    println!("[INFO] processing checkout");
    if total > 500.0 {
        println!("[ERROR] checkout failed");
        LEGACY_ERRORS.fetch_add(1, Ordering::Relaxed);
        return r#"{"variant":"legacy","status":"error","note":"log dice 'checkout failed' sin id, sin total, sin causa."}"#.to_string();
    }
    println!("[INFO] checkout ok");
    r#"{"variant":"legacy","status":"ok","note":"log dice 'checkout ok' sin contexto util."}"#
        .to_string()
}

/// Observable: el contexto se crea en el handler y se presta a cada llamada
/// que loguea. Ninguna referencia puede sobrevivir a esta funcion.
fn checkout_observable(total_raw: &str) -> String {
    let ctx = RequestCtx {
        correlation_id: new_correlation_id(),
        route: "checkout-observable",
    };
    let total = total_raw.parse::<f64>().unwrap_or(100.0);

    structured_log(&ctx, "info", "checkout_start", &[("total", &fmt_num(total))]);

    if total > 500.0 {
        structured_log(
            &ctx,
            "error",
            "checkout_failed",
            &[
                ("total", &fmt_num(total)),
                ("reason", "exceeds_limit"),
                ("limit", "500"),
            ],
        );
        OBSERVABLE_ERRORS.fetch_add(1, Ordering::Relaxed);
        return format!(
            r#"{{"variant":"observable","status":"error","correlation_id":"{}","reason":"exceeds_limit","limit":500,"total":{}}}"#,
            ctx.correlation_id,
            fmt_num(total)
        );
    }
    structured_log(&ctx, "info", "checkout_ok", &[("total", &fmt_num(total))]);
    format!(
        r#"{{"variant":"observable","status":"ok","correlation_id":"{}","total":{},"note":"correlation_id propagado por referencia; el borrow checker impide que sobreviva al request."}}"#,
        ctx.correlation_id,
        fmt_num(total)
    )
}

/// Recibe `&RequestCtx`: la firma obliga a tener un contexto vivo para loguear.
fn structured_log(ctx: &RequestCtx, level: &str, event: &str, fields: &[(&str, &str)]) {
    let mut line = format!(
        r#"{{"ts":"{}","level":"{level}","event":"{event}","correlation_id":"{}","route":"{}""#,
        rfc3339_now(),
        ctx.correlation_id,
        ctx.route
    );
    for (k, v) in fields {
        // Numerico sin comillas si parsea como numero; string si no.
        if v.parse::<f64>().is_ok() {
            line.push_str(&format!(r#","{k}":{v}"#));
        } else {
            line.push_str(&format!(r#","{k}":"{}""#, escape(v)));
        }
    }
    line.push('}');

    println!("{line}");
    let mut logs = RECENT_LOGS.lock().unwrap();
    logs.insert(0, line);
    logs.truncate(MAX_LOG_ENTRIES);
}

fn diagnostics() -> String {
    format!(
        r#"{{"stack":"{}","case":"{CASE_NAME}","legacy":{{"requests":{},"errors":{},"observability":"println sin correlation, sin contexto"}},"observable":{{"requests":{},"errors":{},"observability":"log estructurado JSON con correlation_id prestado por referencia, /logs endpoint"}}}}"#,
        stack(),
        LEGACY_REQUESTS.load(Ordering::Relaxed),
        LEGACY_ERRORS.load(Ordering::Relaxed),
        OBSERVABLE_REQUESTS.load(Ordering::Relaxed),
        OBSERVABLE_ERRORS.load(Ordering::Relaxed)
    )
}

// ---------- helpers ----------

/// ID de correlacion sin dependencias: nanos del reloj + secuencia monotona.
fn new_correlation_id() -> String {
    let nanos = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap_or_default()
        .as_nanos();
    let seq = CORR_SEQ.fetch_add(1, Ordering::Relaxed);
    format!("{nanos:032x}{seq:08x}")
}

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

fn fmt_num(v: f64) -> String {
    if (v - v.round()).abs() < f64::EPSILON {
        format!("{}", v.round() as i64)
    } else {
        let s = format!("{v:.2}");
        s.trim_end_matches('0').trim_end_matches('.').to_string()
    }
}

fn escape(v: &str) -> String {
    v.replace('\\', "\\\\").replace('"', "\\\"")
}
