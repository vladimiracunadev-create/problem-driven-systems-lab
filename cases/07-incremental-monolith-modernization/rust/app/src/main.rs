// Caso 07 — Modernizacion incremental de monolito (strangler) — stack Rust 1.83.
//
// Legacy: el cambio toca el shared_schema acoplado, blast radius alto.
// Strangler: una tabla de routing por consumer decide si la operacion va al
// modulo nuevo o cae al monolito con ACL.
//
// El contraste que este stack aporta:
//
//   La tabla de routing guarda `Box<dyn Fn(&Request) -> Response + Send + Sync>`.
//   Esa firma dice mas de lo que parece, y es informacion que ningun otro stack
//   del lab expresa en el tipo:
//
//     - `dyn Fn`  → despacho dinamico: el handler se elige en runtime.
//     - `Send + Sync` → el compilador **verifica** que el handler es seguro de
//       compartir entre threads. Si alguien registra un closure que captura
//       algo no thread-safe (un `Rc`, un `RefCell`), no compila.
//
//   En Java un `Function<Request,Response>` guardado en un `ConcurrentHashMap`
//   puede capturar estado mutable no sincronizado sin que nadie avise; el mapa
//   es concurrente, el closure no. En un strangler eso importa: los handlers
//   nuevos se registran mientras hay trafico, y son justo el codigo menos
//   probado del sistema.
//
//   `RwLock` y no `Mutex` porque la tabla se lee en cada request y se escribe
//   solo al registrar una migracion.

use std::collections::HashMap;
use std::io::{BufRead, BufReader, Write};
use std::net::{TcpListener, TcpStream};
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::{LazyLock, RwLock};
use std::thread;

const CASE_NAME: &str = "07 - Modernizacion incremental de monolito";

static LEGACY_CALLS: AtomicI64 = AtomicI64::new(0);
static STRANGLER_CALLS: AtomicI64 = AtomicI64::new(0);
static ROUTED_TO_NEW_MODULE: AtomicI64 = AtomicI64::new(0);

struct Request {
    consumer: String,
    op: String,
}

struct Response {
    routed_to: &'static str,
    blast_radius_score: i32,
    risk_score: i32,
}

/// El handler debe ser Send + Sync: el compilador lo verifica al registrar.
type Handler = Box<dyn Fn(&Request) -> Response + Send + Sync>;

static ROUTING_TABLE: LazyLock<RwLock<HashMap<String, Handler>>> = LazyLock::new(|| {
    let mut table: HashMap<String, Handler> = HashMap::new();
    // Routing inicial: billing ya migrado. Registrar otra migracion es esta
    // unica linea — y si el closure no fuera thread-safe, no compilaria.
    table.insert(
        "billing:change".to_string(),
        Box::new(|_req: &Request| Response {
            routed_to: "new-billing-svc",
            blast_radius_score: 1,
            risk_score: 1,
        }),
    );
    RwLock::new(table)
});

static MIGRATION_PROGRESS: LazyLock<Vec<(&'static str, i32)>> = LazyLock::new(|| {
    vec![("billing", 100), ("orders", 0), ("inventory", 0), ("reporting", 0)]
});

fn stack() -> String {
    std::env::var("APP_STACK").unwrap_or_else(|_| "Rust 1.83".to_string())
}

fn main() {
    let port: u16 = std::env::var("PORT").ok().and_then(|p| p.parse().ok()).unwrap_or(8080);
    let listener = TcpListener::bind(("0.0.0.0", port)).expect("bind");
    println!("[case07-rust] listening on {port}");
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
    let consumer = params.get("consumer").cloned().unwrap_or_else(|| "billing".into());
    let op = params.get("op").cloned().unwrap_or_else(|| "change".into());

    match path {
        "/" | "/index" => (
            200,
            format!(
                r#"{{"case":"{CASE_NAME}","stack":"{}","routes":["/health","/change-legacy?consumer=billing&op=change","/change-strangler?consumer=billing&op=change","/flows","/diagnostics/summary","/reset-lab"]}}"#,
                stack()
            ),
        ),
        "/health" => (
            200,
            format!(r#"{{"status":"ok","stack":"{}","case":"{CASE_NAME}"}}"#, stack()),
        ),
        "/change-legacy" => {
            let body = change_legacy(&consumer, &op);
            LEGACY_CALLS.fetch_add(1, Ordering::Relaxed);
            (200, body)
        }
        "/change-strangler" => {
            let body = change_strangler(&consumer, &op);
            STRANGLER_CALLS.fetch_add(1, Ordering::Relaxed);
            (200, body)
        }
        "/flows" => (200, flows()),
        "/diagnostics/summary" => (200, diagnostics()),
        "/reset-lab" => {
            LEGACY_CALLS.store(0, Ordering::Relaxed);
            STRANGLER_CALLS.store(0, Ordering::Relaxed);
            ROUTED_TO_NEW_MODULE.store(0, Ordering::Relaxed);
            (200, r#"{"status":"reset"}"#.to_string())
        }
        _ => (
            404,
            format!(r#"{{"error":"not_found","path":"{}"}}"#, escape(path)),
        ),
    }
}

// ---------- endpoints ----------

/// Legacy: todos los consumers pegan al mismo monolito. Un cambio en el
/// shared_schema propaga a los 4 modulos.
fn change_legacy(consumer: &str, op: &str) -> String {
    format!(
        r#"{{"variant":"legacy","consumer":"{}","op":"{}","routed_to":"shared-monolith","blast_radius_score":4,"risk_score":8,"note":"cambio en shared_schema afecta los 4 modulos del monolito."}}"#,
        escape(consumer),
        escape(op)
    )
}

/// Strangler: consulta la tabla. Si hay handler nuevo, el monolito queda
/// intocado; si no, cae al legacy pero acotado por ACL.
fn change_strangler(consumer: &str, op: &str) -> String {
    let key = format!("{consumer}:{op}");
    let table = ROUTING_TABLE.read().unwrap();

    if let Some(handler) = table.get(&key) {
        let req = Request { consumer: consumer.into(), op: op.into() };
        let r = handler(&req);
        drop(table);
        ROUTED_TO_NEW_MODULE.fetch_add(1, Ordering::Relaxed);
        return format!(
            r#"{{"variant":"strangler","consumer":"{}","op":"{}","routed_to":"{}","blast_radius_score":{},"risk_score":{},"note":"routing table apunta a nuevo modulo; monolito intocado."}}"#,
            escape(&req.consumer),
            escape(&req.op),
            r.routed_to,
            r.blast_radius_score,
            r.risk_score
        );
    }
    drop(table);
    format!(
        r#"{{"variant":"strangler","consumer":"{}","op":"{}","routed_to":"legacy-monolith","blast_radius_score":2,"risk_score":4,"note":"consumer aun no migrado; routing cae al legacy pero con ACL."}}"#,
        escape(consumer),
        escape(op)
    )
}

fn flows() -> String {
    let progress: Vec<String> = MIGRATION_PROGRESS
        .iter()
        .map(|(k, v)| format!(r#""{k}":{v}"#))
        .collect();
    format!(
        r#"{{"migration_progress":{{{}}},"routing_table_size":{}}}"#,
        progress.join(","),
        ROUTING_TABLE.read().unwrap().len()
    )
}

fn diagnostics() -> String {
    format!(
        r#"{{"stack":"{}","case":"{CASE_NAME}","legacy":{{"calls":{},"avg_blast_radius":4,"avg_risk":8}},"strangler":{{"calls":{},"routed_to_new_module":{},"routing_table_size":{}}},"flows":{}}}"#,
        stack(),
        LEGACY_CALLS.load(Ordering::Relaxed),
        STRANGLER_CALLS.load(Ordering::Relaxed),
        ROUTED_TO_NEW_MODULE.load(Ordering::Relaxed),
        ROUTING_TABLE.read().unwrap().len(),
        flows()
    )
}

fn escape(v: &str) -> String {
    v.replace('\\', "\\\\").replace('"', "\\\"")
}
