// Caso 05 — Presion de memoria y fugas de recursos (stack Rust 1.83).
//
// Legacy: Vec global que crece sin limite por request → fuga real.
// Optimized: cache LRU acotada con VecDeque + HashMap → memoria estable.
//
// Este es EL caso donde Rust dice algo que ningun otro stack del lab puede
// decir, y tambien donde mas facil es contar una mentira comoda. Las dos cosas:
//
//   LO QUE RUST GARANTIZA
//   Rust no tiene GC. La memoria se libera cuando el valor sale de scope, de
//   forma deterministica, via `Drop`. No hay pausa de recoleccion, no hay
//   heurística, no hay un hilo de fondo decidiendo cuando. La estructura
//   `Tracked` de este caso implementa `Drop` y **cuenta sus propias
//   liberaciones**: el endpoint /state muestra `dropped_total` creciendo en
//   tiempo real cuando la LRU evicciona. Eso es observable aca y en ningun
//   otro stack del laboratorio.
//
//   LO QUE RUST NO GARANTIZA — Y ES EL PUNTO DEL CASO
//   El borrow checker **no impide esta fuga**. Meter cosas en un `Vec` global y
//   no sacarlas nunca es codigo perfectamente seguro y perfectamente legal:
//   compila sin un warning. Rust previene use-after-free y data races, no
//   previene "guardar de mas".
//
//   Esa es la leccion cruzada del caso: en PHP/Python/Node/Java/.NET/Go la
//   fuga es memoria REFERENCIADA de mas que el GC no puede tocar; en Rust es
//   memoria RETENIDA de mas que el programador nunca solto. Distinto mecanismo,
//   identico bug de diseño, identico grafico de heap subiendo hasta el OOM.

use std::collections::{HashMap, VecDeque};
use std::io::{BufRead, BufReader, Write};
use std::net::{TcpListener, TcpStream};
use std::sync::atomic::{AtomicI64, AtomicU64, Ordering};
use std::sync::{LazyLock, Mutex};
use std::thread;

const CASE_NAME: &str = "05 - Presion de memoria y fugas de recursos";
const OPTIMIZED_CAP: usize = 1000;

static LEGACY_REQUESTS: AtomicI64 = AtomicI64::new(0);
static OPTIMIZED_REQUESTS: AtomicI64 = AtomicI64::new(0);
static OPTIMIZED_EVICTIONS: AtomicI64 = AtomicI64::new(0);

/// Bytes vivos y liberaciones observadas. `DROPPED_TOTAL` lo incrementa el
/// destructor de `Tracked` — es contabilidad real, no una estimacion.
static LIVE_BYTES: AtomicI64 = AtomicI64::new(0);
static DROPPED_TOTAL: AtomicI64 = AtomicI64::new(0);
static KEY_SEQ: AtomicU64 = AtomicU64::new(0);

/// Payload con destructor propio. Al salir de scope, Rust ejecuta `drop` de
/// forma deterministica: sin GC, sin pausa, sin hilo de fondo.
struct Tracked {
    bytes: Vec<u8>,
}

impl Tracked {
    fn new(size: usize) -> Self {
        let mut bytes = vec![0u8; size];
        for (i, b) in bytes.iter_mut().enumerate() {
            *b = (i & 0xff) as u8;
        }
        LIVE_BYTES.fetch_add(size as i64, Ordering::Relaxed);
        Tracked { bytes }
    }
}

impl Drop for Tracked {
    fn drop(&mut self) {
        LIVE_BYTES.fetch_sub(self.bytes.len() as i64, Ordering::Relaxed);
        DROPPED_TOTAL.fetch_add(1, Ordering::Relaxed);
    }
}

/// La fuga: nada saca elementos de aca nunca.
static LEGACY_ACCUMULATOR: Mutex<Vec<Tracked>> = Mutex::new(Vec::new());

/// LRU acotada: VecDeque como orden de uso + HashMap como indice.
struct Lru {
    order: VecDeque<u64>,
    index: HashMap<u64, Tracked>,
}

/// `HashMap::new()` no es una funcion `const`, asi que no puede evaluarse en un
/// `static`. `LazyLock` (estable desde Rust 1.80) difiere la construccion al
/// primer uso y garantiza que ocurra una sola vez, sin `unsafe` ni `Option`.
static OPTIMIZED_CACHE: LazyLock<Mutex<Lru>> = LazyLock::new(|| {
    Mutex::new(Lru {
        order: VecDeque::new(),
        index: HashMap::new(),
    })
});

fn stack() -> String {
    std::env::var("APP_STACK").unwrap_or_else(|_| "Rust 1.83".to_string())
}

fn main() {
    let port: u16 = std::env::var("PORT").ok().and_then(|p| p.parse().ok()).unwrap_or(8080);
    let listener = TcpListener::bind(("0.0.0.0", port)).expect("bind");
    println!("[case05-rust] listening on {port}");
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
    let size_kb = bounded(params.get("size_kb").map(String::as_str), 64, 1, 4096);

    match path {
        "/" | "/index" => (
            200,
            format!(
                r#"{{"case":"{CASE_NAME}","stack":"{}","routes":["/health","/batch-legacy?size_kb=64","/batch-optimized?size_kb=64","/state","/diagnostics/summary","/reset-lab"]}}"#,
                stack()
            ),
        ),
        "/health" => (
            200,
            format!(r#"{{"status":"ok","stack":"{}","case":"{CASE_NAME}"}}"#, stack()),
        ),
        "/batch-legacy" => {
            let body = batch_legacy(size_kb as usize);
            LEGACY_REQUESTS.fetch_add(1, Ordering::Relaxed);
            (200, body)
        }
        "/batch-optimized" => {
            let body = batch_optimized(size_kb as usize);
            OPTIMIZED_REQUESTS.fetch_add(1, Ordering::Relaxed);
            (200, body)
        }
        "/state" => (200, state()),
        "/diagnostics/summary" => (200, diagnostics()),
        "/reset-lab" => {
            // Vaciar los contenedores ejecuta el Drop de cada Tracked: la
            // memoria vuelve al asignador aqui mismo, no "en algun momento".
            LEGACY_ACCUMULATOR.lock().unwrap().clear();
            let mut cache = OPTIMIZED_CACHE.lock().unwrap();
            cache.order.clear();
            cache.index.clear();
            drop(cache);
            LEGACY_REQUESTS.store(0, Ordering::Relaxed);
            OPTIMIZED_REQUESTS.store(0, Ordering::Relaxed);
            OPTIMIZED_EVICTIONS.store(0, Ordering::Relaxed);
            (
                200,
                r#"{"status":"reset","note":"acumuladores limpios; Drop libero la memoria de forma deterministica, sin GC."}"#
                    .to_string(),
            )
        }
        _ => (
            404,
            format!(r#"{{"error":"not_found","path":"{}"}}"#, escape(path)),
        ),
    }
}

// ---------- endpoints ----------

/// Legacy: cada request empuja al Vec global y nada lo saca. El borrow checker
/// no tiene nada que objetar: esto es codigo seguro que fuga por diseño.
fn batch_legacy(size_kb: usize) -> String {
    let payload = Tracked::new(size_kb * 1024);
    let mut acc = LEGACY_ACCUMULATOR.lock().unwrap();
    acc.push(payload);
    let retained = acc.len();
    drop(acc);

    format!(
        r#"{{"variant":"legacy","appended_kb":{size_kb},"retained_count":{retained},"retained_kb_estimate":{},"note":"se acumula en Vec global sin eviccion → fuga real cross-request. Rust no la impide: es codigo seguro."}}"#,
        retained * size_kb
    )
}

/// Optimized: la LRU evicciona al mas viejo. El `Tracked` evictado sale de
/// scope y su Drop corre en el acto — `dropped_total` lo confirma.
fn batch_optimized(size_kb: usize) -> String {
    let payload = Tracked::new(size_kb * 1024);
    let key = KEY_SEQ.fetch_add(1, Ordering::Relaxed);

    let mut cache = OPTIMIZED_CACHE.lock().unwrap();
    cache.index.insert(key, payload);
    cache.order.push_back(key);
    if cache.order.len() > OPTIMIZED_CAP {
        if let Some(oldest) = cache.order.pop_front() {
            // El remove devuelve el Tracked; al no ligarlo a nada, Drop corre YA.
            cache.index.remove(&oldest);
            OPTIMIZED_EVICTIONS.fetch_add(1, Ordering::Relaxed);
        }
    }
    let retained = cache.index.len();
    drop(cache);

    format!(
        r#"{{"variant":"optimized","appended_kb":{size_kb},"retained_count":{retained},"cap":{OPTIMIZED_CAP},"evictions_total":{},"note":"VecDeque + HashMap como LRU con cap fijo; el Drop del evictado libera en el acto."}}"#,
        OPTIMIZED_EVICTIONS.load(Ordering::Relaxed)
    )
}

fn state() -> String {
    let legacy_count = LEGACY_ACCUMULATOR.lock().unwrap().len();
    let optimized_count = OPTIMIZED_CACHE.lock().unwrap().index.len();
    let live = LIVE_BYTES.load(Ordering::Relaxed);
    format!(
        r#"{{"stack":"{}","live_bytes":{live},"live_mb":{},"dropped_total":{},"gc":"ninguno — liberacion deterministica via Drop","legacy_retained_count":{legacy_count},"optimized_retained_count":{optimized_count},"optimized_cap":{OPTIMIZED_CAP}}}"#,
        stack(),
        live / (1024 * 1024),
        DROPPED_TOTAL.load(Ordering::Relaxed)
    )
}

fn diagnostics() -> String {
    let legacy_count = LEGACY_ACCUMULATOR.lock().unwrap().len();
    let optimized_count = OPTIMIZED_CACHE.lock().unwrap().index.len();
    format!(
        r#"{{"stack":"{}","case":"{CASE_NAME}","legacy":{{"requests":{},"retained_count":{legacy_count},"behavior":"sin eviccion, leak monotonicamente creciente"}},"optimized":{{"requests":{},"retained_count":{optimized_count},"evictions":{},"cap":{OPTIMIZED_CAP},"behavior":"LRU con VecDeque + HashMap y cap fijo"}},"runtime":{}}}"#,
        stack(),
        LEGACY_REQUESTS.load(Ordering::Relaxed),
        OPTIMIZED_REQUESTS.load(Ordering::Relaxed),
        OPTIMIZED_EVICTIONS.load(Ordering::Relaxed),
        state()
    )
}

// ---------- helpers ----------

fn bounded(raw: Option<&str>, dflt: i64, min: i64, max: i64) -> i64 {
    raw.and_then(|r| r.parse::<i64>().ok()).unwrap_or(dflt).clamp(min, max)
}

fn escape(v: &str) -> String {
    v.replace('\\', "\\\\").replace('"', "\\\"")
}
