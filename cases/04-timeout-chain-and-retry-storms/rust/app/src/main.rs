// Caso 04 — Timeout chain y retry storms (stack Rust 1.83).
//
// Legacy: 5 reintentos secuenciales sin backoff, sin timeout, sin breaker.
// Resilient: deadline + circuit breaker + fallback cacheado.
//
// El contraste honesto que este stack aporta:
//
//   Aca el deadline se implementa con `mpsc::channel` + `recv_timeout`: se
//   lanza el trabajo en un thread y el llamador espera con limite. Funciona y
//   devuelve el control a tiempo.
//
//   Pero hay que decirlo claro: **el thread lanzado sigue vivo hasta terminar**.
//   `recv_timeout` corta la ESPERA, no el TRABAJO. Es exactamente la misma
//   limitacion que tiene `CompletableFuture.orTimeout()` en Java, y es peor
//   que lo que logra Go, donde `context.WithTimeout` propaga la cancelacion al
//   callee y este abandona de verdad.
//
//   La razon es estructural: `std` de Rust no tiene runtime asincronico ni
//   cancelacion cooperativa. Eso vive en `tokio`, donde `tokio::time::timeout`
//   sobre un future SI cancela el trabajo pendiente. Mantener el caso sin
//   dependencias tiene este costo, y ocultarlo seria deshonesto.
//
//   Lo que Rust si aporta aca es `Mutex<BreakerState>` sin posibilidad de
//   olvidarse el unlock: el guard libera al salir de scope. Un `lock()`
//   olvidado no existe como categoria de bug.

use std::collections::HashMap;
use std::io::{BufRead, BufReader, Write};
use std::net::{TcpListener, TcpStream};
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::{mpsc, Mutex};
use std::thread;
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

const CASE_NAME: &str = "04 - Timeout chain y retry storms";
const BREAKER_COOLDOWN_MS: i64 = 5000;
const BREAKER_FAIL_THRESHOLD: i64 = 3;
const PROVIDER_LATENCY_MS: u64 = 800;
const RESILIENT_DEADLINE_MS: u64 = 300;
const LEGACY_MAX_ATTEMPTS: i64 = 5;

static LEGACY_RETRIES: AtomicI64 = AtomicI64::new(0);
static LEGACY_FAILURES: AtomicI64 = AtomicI64::new(0);
static RESILIENT_CALLS: AtomicI64 = AtomicI64::new(0);
static RESILIENT_FALLBACKS: AtomicI64 = AtomicI64::new(0);
static RESILIENT_SHORT_CIRCUITS: AtomicI64 = AtomicI64::new(0);
static LAST_FALLBACK_PRICE: AtomicI64 = AtomicI64::new(0);
static RNG_STATE: AtomicI64 = AtomicI64::new(20420);

struct BreakerState {
    state: &'static str,
    fail_count: i64,
    opened_at: i64,
}

static BREAKER: Mutex<BreakerState> = Mutex::new(BreakerState {
    state: "closed",
    fail_count: 0,
    opened_at: 0,
});

fn stack() -> String {
    std::env::var("APP_STACK").unwrap_or_else(|_| "Rust 1.83".to_string())
}

fn main() {
    let port: u16 = std::env::var("PORT").ok().and_then(|p| p.parse().ok()).unwrap_or(8080);
    let listener = TcpListener::bind(("0.0.0.0", port)).expect("bind");
    println!("[case04-rust] listening on {port}");
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
    let fail = params.get("fail").map(String::as_str) == Some("on");

    match path {
        "/" | "/index" => (
            200,
            format!(
                r#"{{"case":"{CASE_NAME}","stack":"{}","routes":["/health","/quote-legacy?fail=on","/quote-resilient?fail=on","/dependency/state","/diagnostics/summary","/reset-lab"]}}"#,
                stack()
            ),
        ),
        "/health" => (
            200,
            format!(r#"{{"status":"ok","stack":"{}","case":"{CASE_NAME}"}}"#, stack()),
        ),
        "/quote-legacy" => (200, quote_legacy(fail)),
        "/quote-resilient" => {
            let body = quote_resilient(fail);
            RESILIENT_CALLS.fetch_add(1, Ordering::Relaxed);
            (200, body)
        }
        "/dependency/state" => (200, breaker_json()),
        "/diagnostics/summary" => (200, diagnostics()),
        "/reset-lab" => {
            LEGACY_RETRIES.store(0, Ordering::Relaxed);
            LEGACY_FAILURES.store(0, Ordering::Relaxed);
            RESILIENT_CALLS.store(0, Ordering::Relaxed);
            RESILIENT_FALLBACKS.store(0, Ordering::Relaxed);
            RESILIENT_SHORT_CIRCUITS.store(0, Ordering::Relaxed);
            let mut b = BREAKER.lock().unwrap();
            *b = BreakerState { state: "closed", fail_count: 0, opened_at: 0 };
            (200, r#"{"status":"reset"}"#.to_string())
        }
        _ => (
            404,
            format!(r#"{{"error":"not_found","path":"{}"}}"#, escape(path)),
        ),
    }
}

// ---------- proveedor simulado ----------

fn next_quote() -> i64 {
    // LCG simple: evita depender de `rand`.
    let prev = RNG_STATE.load(Ordering::Relaxed);
    let next = (prev * 9301 + 49297) % 233280;
    RNG_STATE.store(next, Ordering::Relaxed);
    100 + (next % 900)
}

/// Llamada bloqueante al proveedor. No recibe señal de cancelacion: `std` no
/// tiene una. El unico control posible es del lado del llamador.
fn call_provider_blocking(fail: bool) -> Result<i64, &'static str> {
    thread::sleep(Duration::from_millis(PROVIDER_LATENCY_MS));
    if fail {
        return Err("provider_unavailable");
    }
    Ok(next_quote())
}

/// Deadline del lado del llamador: `recv_timeout` corta la espera. El thread
/// lanzado sigue vivo hasta terminar — misma limitacion que orTimeout() en Java.
fn call_with_deadline(fail: bool, deadline_ms: u64) -> Result<i64, &'static str> {
    let (tx, rx) = mpsc::channel();
    thread::spawn(move || {
        let _ = tx.send(call_provider_blocking(fail));
    });
    match rx.recv_timeout(Duration::from_millis(deadline_ms)) {
        Ok(inner) => inner,
        Err(_) => Err("timeout"),
    }
}

// ---------- endpoints ----------

/// Legacy: 5 reintentos, sin backoff, sin deadline, sin breaker. Cada intento
/// espera los 800 ms completos: ~4 s de recurso ocupado antes de rendirse.
fn quote_legacy(fail: bool) -> String {
    let start = Instant::now();
    for attempt in 1..=LEGACY_MAX_ATTEMPTS {
        LEGACY_RETRIES.fetch_add(1, Ordering::Relaxed);
        if let Ok(quote) = call_provider_blocking(fail) {
            return format!(
                r#"{{"variant":"legacy","status":"ok","attempts":{attempt},"quote":{quote},"elapsed_ms":{}}}"#,
                fmt_num(elapsed_ms(start))
            );
        }
    }
    LEGACY_FAILURES.fetch_add(1, Ordering::Relaxed);
    format!(
        r#"{{"variant":"legacy","status":"failed","attempts":{LEGACY_MAX_ATTEMPTS},"elapsed_ms":{},"note":"5 reintentos sin backoff agotaron al proveedor; sin circuit breaker."}}"#,
        fmt_num(elapsed_ms(start))
    )
}

/// Resilient: si el breaker esta abierto y en cooldown, corta sin tocar al
/// proveedor. Si no, un solo intento con deadline de 300 ms.
fn quote_resilient(fail: bool) -> String {
    let start = Instant::now();

    {
        let b = BREAKER.lock().unwrap();
        if b.state == "open" && (now_ms() - b.opened_at) < BREAKER_COOLDOWN_MS {
            drop(b);
            RESILIENT_SHORT_CIRCUITS.fetch_add(1, Ordering::Relaxed);
            return format!(
                r#"{{"variant":"resilient","status":"short_circuited","breaker":"open","fallback_quote":{},"elapsed_ms":{},"note":"breaker abierto, devuelve fallback sin tocar al proveedor."}}"#,
                LAST_FALLBACK_PRICE.load(Ordering::Relaxed),
                fmt_num(elapsed_ms(start))
            );
        }
    }

    match call_with_deadline(fail, RESILIENT_DEADLINE_MS) {
        Ok(quote) => {
            on_success();
            LAST_FALLBACK_PRICE.store(quote, Ordering::Relaxed);
            format!(
                r#"{{"variant":"resilient","status":"ok","quote":{quote},"breaker":"{}","elapsed_ms":{}}}"#,
                breaker_state_name(),
                fmt_num(elapsed_ms(start))
            )
        }
        Err(cause) => {
            on_failure();
            RESILIENT_FALLBACKS.fetch_add(1, Ordering::Relaxed);
            let cause = if cause == "timeout" { "timeout" } else { "provider_error" };
            format!(
                r#"{{"variant":"resilient","status":"fallback","breaker":"{}","fallback_quote":{},"elapsed_ms":{},"cause":"{cause}"}}"#,
                breaker_state_name(),
                LAST_FALLBACK_PRICE.load(Ordering::Relaxed),
                fmt_num(elapsed_ms(start))
            )
        }
    }
}

fn on_success() {
    let mut b = BREAKER.lock().unwrap();
    *b = BreakerState { state: "closed", fail_count: 0, opened_at: 0 };
}

fn on_failure() {
    let mut b = BREAKER.lock().unwrap();
    b.fail_count += 1;
    if b.fail_count >= BREAKER_FAIL_THRESHOLD {
        b.state = "open";
        b.opened_at = now_ms();
    }
}

fn breaker_state_name() -> &'static str {
    BREAKER.lock().unwrap().state
}

fn breaker_json() -> String {
    let b = BREAKER.lock().unwrap();
    let cooldown_left = if b.opened_at == 0 {
        0
    } else {
        (BREAKER_COOLDOWN_MS - (now_ms() - b.opened_at)).max(0)
    };
    format!(
        r#"{{"state":"{}","fail_count":{},"opened_at":{},"cooldown_left_ms":{cooldown_left},"threshold":{BREAKER_FAIL_THRESHOLD},"cooldown_ms":{BREAKER_COOLDOWN_MS}}}"#,
        b.state, b.fail_count, b.opened_at
    )
}

fn diagnostics() -> String {
    format!(
        r#"{{"stack":"{}","case":"{CASE_NAME}","legacy":{{"retries_total":{},"failures":{},"note":"reintentos lineales sin breaker producen retry storm"}},"resilient":{{"calls":{},"fallbacks":{},"short_circuits":{},"breaker":{}}}}}"#,
        stack(),
        LEGACY_RETRIES.load(Ordering::Relaxed),
        LEGACY_FAILURES.load(Ordering::Relaxed),
        RESILIENT_CALLS.load(Ordering::Relaxed),
        RESILIENT_FALLBACKS.load(Ordering::Relaxed),
        RESILIENT_SHORT_CIRCUITS.load(Ordering::Relaxed),
        breaker_json()
    )
}

// ---------- helpers ----------

fn now_ms() -> i64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap_or_default()
        .as_millis() as i64
}

fn elapsed_ms(start: Instant) -> f64 {
    round2(start.elapsed().as_secs_f64() * 1000.0)
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

fn escape(v: &str) -> String {
    v.replace('\\', "\\\\").replace('"', "\\\"")
}
