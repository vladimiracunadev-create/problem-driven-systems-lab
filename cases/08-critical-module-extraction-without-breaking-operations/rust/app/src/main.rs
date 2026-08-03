// Caso 08 — Extraccion critica de modulo (cutover) — stack Rust 1.83.
//
// Big-bang: el cambio de contrato rompe consumers sensibles.
// Compatible: un proxy traduce el contrato viejo ↔ nuevo en vuelo, y un bus de
// eventos publica el avance del cutover.
//
// El contraste que este stack aporta:
//
//   El bus es `mpsc::channel` de `std` con una goroutine... perdon, con un
//   THREAD suscriptor. Igual que en Go, la publicacion queda desacoplada del
//   consumo. La diferencia con Go esta en el tipo:
//
//     Go:    ch := make(chan busEvent, 256)   // cualquiera puede enviar y recibir
//     Rust:  let (tx, rx) = mpsc::channel();  // tx se clona, rx es UNICO
//
//   `mpsc` = multi-producer, single-consumer, y el compilador lo impone: el
//   `Receiver` no es `Clone`. Si alguien intentara consumir el bus desde dos
//   threads, no compila. En Go, dos goroutines leyendo el mismo canal se
//   reparten los mensajes en silencio — que a veces es lo que querias y a
//   veces es la razon por la que la mitad de tus eventos de auditoria
//   desaparecieron.
//
//   El contrato viejo y el nuevo son dos `struct` distintos, no un mapa con
//   claves opcionales. El ACL es la funcion que convierte uno en otro, y su
//   firma documenta la traduccion sin comentarios.

use std::collections::HashMap;
use std::io::{BufRead, BufReader, Write};
use std::net::{TcpListener, TcpStream};
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::mpsc::{self, Sender};
use std::sync::{LazyLock, Mutex};
use std::thread;
use std::time::{SystemTime, UNIX_EPOCH};

const CASE_NAME: &str = "08 - Extraccion critica de modulo";
const MAX_EVENTS: usize = 50;

static BIGBANG_CALLS: AtomicI64 = AtomicI64::new(0);
static BIGBANG_BROKEN: AtomicI64 = AtomicI64::new(0);
static COMPATIBLE_CALLS: AtomicI64 = AtomicI64::new(0);
static PROXY_HITS: AtomicI64 = AtomicI64::new(0);
static CONTRACT_TESTS_PASSED: AtomicI64 = AtomicI64::new(0);

/// Contrato viejo: el consumer manda {sku, cost_usd}.
struct PriceRequestOld {
    sku: String,
    cost_usd: f64,
}

/// Contrato nuevo: el modulo extraido espera {sku, price, currency}.
struct PriceRequestNew {
    sku: String,
    price: f64,
    currency: &'static str,
}

/// El ACL: una funcion cuya firma documenta la traduccion.
fn compat_proxy(old: PriceRequestOld) -> PriceRequestNew {
    PriceRequestNew { sku: old.sku, price: old.cost_usd, currency: "USD" }
}

#[derive(Clone)]
struct BusEvent {
    at: String,
    event: String,
}

static RECENT_EVENTS: Mutex<Vec<BusEvent>> = Mutex::new(Vec::new());
static CUTOVER_PROGRESS: LazyLock<Mutex<HashMap<String, bool>>> = LazyLock::new(|| {
    let mut m = HashMap::new();
    m.insert("checkout".to_string(), false);
    m.insert("partners".to_string(), false);
    m.insert("backoffice".to_string(), false);
    Mutex::new(m)
});

/// El Sender se clona libremente; el Receiver es unico y vive en el thread
/// suscriptor. El compilador impide que haya dos consumidores.
static BUS_TX: LazyLock<Mutex<Option<Sender<BusEvent>>>> = LazyLock::new(|| Mutex::new(None));

fn stack() -> String {
    std::env::var("APP_STACK").unwrap_or_else(|_| "Rust 1.83".to_string())
}

fn start_bus() {
    let (tx, rx) = mpsc::channel::<BusEvent>();
    *BUS_TX.lock().unwrap() = Some(tx);
    thread::spawn(move || {
        // rx es unico: este es el unico consumidor posible del bus.
        for evt in rx {
            let mut logs = RECENT_EVENTS.lock().unwrap();
            logs.insert(0, evt);
            logs.truncate(MAX_EVENTS);
        }
    });
}

fn emit(name: &str) {
    if let Some(tx) = BUS_TX.lock().unwrap().as_ref() {
        let _ = tx.send(BusEvent { at: rfc3339_now(), event: name.to_string() });
    }
}

fn main() {
    start_bus();
    let port: u16 = std::env::var("PORT").ok().and_then(|p| p.parse().ok()).unwrap_or(8080);
    let listener = TcpListener::bind(("0.0.0.0", port)).expect("bind");
    println!("[case08-rust] listening on {port}");
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
    let consumer = params.get("consumer").cloned().unwrap_or_else(|| "checkout".into());
    let sku = params.get("sku").cloned().unwrap_or_else(|| "ABC".into());
    let cost_usd = params
        .get("cost_usd")
        .and_then(|v| v.parse::<f64>().ok())
        .unwrap_or(100.0);

    match path {
        "/" | "/index" => (
            200,
            format!(
                r#"{{"case":"{CASE_NAME}","stack":"{}","routes":["/health","/pricing-bigbang?consumer=checkout&sku=ABC&cost_usd=100","/pricing-compatible?consumer=checkout&sku=ABC&cost_usd=100","/flows","/diagnostics/summary","/reset-lab"]}}"#,
                stack()
            ),
        ),
        "/health" => (
            200,
            format!(r#"{{"status":"ok","stack":"{}","case":"{CASE_NAME}"}}"#, stack()),
        ),
        "/pricing-bigbang" => {
            let body = pricing_bigbang(&consumer, &sku);
            BIGBANG_CALLS.fetch_add(1, Ordering::Relaxed);
            (200, body)
        }
        "/pricing-compatible" => {
            let body = pricing_compatible(&consumer, &sku, cost_usd);
            COMPATIBLE_CALLS.fetch_add(1, Ordering::Relaxed);
            (200, body)
        }
        "/flows" => (200, flows()),
        "/diagnostics/summary" => (200, diagnostics()),
        "/reset-lab" => {
            BIGBANG_CALLS.store(0, Ordering::Relaxed);
            BIGBANG_BROKEN.store(0, Ordering::Relaxed);
            COMPATIBLE_CALLS.store(0, Ordering::Relaxed);
            PROXY_HITS.store(0, Ordering::Relaxed);
            CONTRACT_TESTS_PASSED.store(0, Ordering::Relaxed);
            RECENT_EVENTS.lock().unwrap().clear();
            for v in CUTOVER_PROGRESS.lock().unwrap().values_mut() {
                *v = false;
            }
            (200, r#"{"status":"reset"}"#.to_string())
        }
        _ => (
            404,
            format!(r#"{{"error":"not_found","path":"{}"}}"#, escape(path)),
        ),
    }
}

// ---------- endpoints ----------

fn pricing_bigbang(consumer: &str, sku: &str) -> String {
    BIGBANG_BROKEN.fetch_add(1, Ordering::Relaxed);
    emit(&format!("bigbang_broke:{consumer}"));
    format!(
        r#"{{"variant":"bigbang","consumer":"{}","sku":"{}","status":"contract_violation","reason":"new module expects {{price, currency}}; consumer sent {{sku, cost_usd}}","note":"cutover sin compat layer rompe consumers sensibles."}}"#,
        escape(consumer),
        escape(sku)
    )
}

fn pricing_compatible(consumer: &str, sku: &str, cost_usd: f64) -> String {
    let translated = compat_proxy(PriceRequestOld { sku: sku.to_string(), cost_usd });
    PROXY_HITS.fetch_add(1, Ordering::Relaxed);
    CONTRACT_TESTS_PASSED.fetch_add(1, Ordering::Relaxed);

    let mut done = false;
    let mut newly_done = false;
    {
        let mut progress = CUTOVER_PROGRESS.lock().unwrap();
        if let Some(flag) = progress.get_mut(consumer) {
            if !*flag {
                *flag = true;
                newly_done = true;
            }
            done = *flag;
        }
    }
    if newly_done {
        emit(&format!("cutover_done:{consumer}"));
    }

    format!(
        r#"{{"variant":"compatible","consumer":"{}","sku":"{}","price":{},"currency":"{}","compatibility_proxy_hit":true,"cutover_done":{done},"note":"proxy traduce {{cost_usd}}→{{price,currency}}; consumer no rompe."}}"#,
        escape(consumer),
        escape(&translated.sku),
        fmt_num(translated.price),
        translated.currency
    )
}

fn flows() -> String {
    let progress = CUTOVER_PROGRESS.lock().unwrap();
    let mut keys: Vec<&String> = progress.keys().collect();
    keys.sort();
    let rendered: Vec<String> = keys
        .iter()
        .map(|k| format!(r#""{k}":{}"#, progress.get(*k).copied().unwrap_or(false)))
        .collect();
    drop(progress);

    let events = RECENT_EVENTS.lock().unwrap();
    let evts: Vec<String> = events
        .iter()
        .map(|e| format!(r#"{{"at":"{}","event":"{}"}}"#, escape(&e.at), escape(&e.event)))
        .collect();

    format!(
        r#"{{"cutover_progress":{{{}}},"recent_events":[{}]}}"#,
        rendered.join(","),
        evts.join(",")
    )
}

fn diagnostics() -> String {
    format!(
        r#"{{"stack":"{}","case":"{CASE_NAME}","bigbang":{{"calls":{},"broken_consumers":{},"behavior":"cambio de contrato sin compat layer"}},"compatible":{{"calls":{},"proxy_hits":{},"contract_tests_passed":{},"behavior":"proxy de compatibilidad + bus mpsc con consumidor unico"}},"flows":{}}}"#,
        stack(),
        BIGBANG_CALLS.load(Ordering::Relaxed),
        BIGBANG_BROKEN.load(Ordering::Relaxed),
        COMPATIBLE_CALLS.load(Ordering::Relaxed),
        PROXY_HITS.load(Ordering::Relaxed),
        CONTRACT_TESTS_PASSED.load(Ordering::Relaxed),
        flows()
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
