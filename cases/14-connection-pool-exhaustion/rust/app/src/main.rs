// Caso 14 — Agotamiento del pool de conexiones — stack Rust 1.83.
//
// Leaky: sin deadline de adquisicion y con la devolucion solo en el camino
// feliz. Managed: deadline explicito y devolucion garantizada por RAII.
//
// Primitiva Rust distintiva — y por que este caso es el mas incomodo de
// escribir en Rust de todo el laboratorio:
//
//   En los otros seis stacks, fugar una conexion es lo que pasa **por defecto**
//   cuando uno se olvida de una linea. Un `finally` que falta, un `defer` que
//   no se escribio, un `Dispose()` que no se llamo.
//
//   En Rust no hay linea que olvidar. Un `Lease` con `impl Drop` devuelve la
//   conexion cuando sale de alcance — en el return feliz, en el temprano, y
//   tambien mientras un panic desenrolla la pila. El compilador no lo pide:
//   simplemente no existe la forma de saltearlo.
//
//   Por eso la variante leaky de este archivo tuvo que ESCRIBIRSE a proposito
//   con `std::mem::forget(lease)`. Esa funcion hace exactamente una cosa: se
//   queda con el valor y no corre su `Drop`. Es la unica manera de fugar un
//   recurso en Rust seguro, y esa es la leccion:
//
//       en seis stacks el leak es lo que pasa si te distraes;
//       en Rust hay que pedirlo por su nombre, y el nombre es grepeable.
//
//   `mem::forget` no es `unsafe` — no puede corromper memoria, solo perder un
//   recurso. Rust considera que perder memoria es seguro; lo que impide es
//   usarla despues de liberarla.
//
// El "query" es un `thread::sleep` a proposito, al reves que en el caso 13. Una
// conexion se retiene mientras se espera a la red, no mientras se quema CPU.

use std::collections::HashMap;
use std::io::{BufRead, BufReader, Write};
use std::net::{TcpListener, TcpStream};
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::{Arc, Condvar, LazyLock, Mutex};
use std::thread;
use std::time::{Duration, Instant};

const CASE_NAME: &str = "14 - Agotamiento del pool de conexiones";
const ACQUIRE_TIMEOUT_MS: u64 = 200;
/// Sin deadline la variante leaky no terminaria. El watchdog permite medirla.
const LEAKY_WATCHDOG_MS: u64 = 2000;

// ---------- pool ----------

#[derive(Debug)]
struct Conn {
    id: i64,
}

struct PoolInner {
    free: Vec<Conn>,
}

struct Pool {
    size: usize,
    inner: Mutex<PoolInner>,
    available: Condvar,
    acquired: AtomicI64,
    released: AtomicI64,
    waiting: AtomicI64,
    waiting_peak: AtomicI64,
}

impl Pool {
    fn new(size: usize) -> Arc<Self> {
        let free = (1..=size as i64).map(|id| Conn { id }).collect();
        Arc::new(Pool {
            size,
            inner: Mutex::new(PoolInner { free }),
            available: Condvar::new(),
            acquired: AtomicI64::new(0),
            released: AtomicI64::new(0),
            waiting: AtomicI64::new(0),
            waiting_peak: AtomicI64::new(0),
        })
    }

    /// Devuelve None si vencio el deadline.
    fn acquire(self: &Arc<Self>, timeout_ms: u64) -> Option<Lease> {
        let w = self.waiting.fetch_add(1, Ordering::SeqCst) + 1;
        self.waiting_peak.fetch_max(w, Ordering::SeqCst);

        let deadline = Instant::now() + Duration::from_millis(timeout_ms);
        let mut guard = self.inner.lock().unwrap();
        loop {
            if let Some(conn) = guard.free.pop() {
                self.waiting.fetch_sub(1, Ordering::SeqCst);
                self.acquired.fetch_add(1, Ordering::SeqCst);
                return Some(Lease { pool: Arc::clone(self), conn: Some(conn) });
            }
            let remaining = deadline.saturating_duration_since(Instant::now());
            if remaining.is_zero() {
                self.waiting.fetch_sub(1, Ordering::SeqCst);
                return None;
            }
            let (g, timed_out) = self.available.wait_timeout(guard, remaining).unwrap();
            guard = g;
            if timed_out.timed_out() && guard.free.is_empty() {
                self.waiting.fetch_sub(1, Ordering::SeqCst);
                return None;
            }
        }
    }

    fn give_back(&self, conn: Conn) {
        self.released.fetch_add(1, Ordering::SeqCst);
        self.inner.lock().unwrap().free.push(conn);
        self.available.notify_one();
    }

    fn leaked(&self) -> i64 {
        self.acquired.load(Ordering::SeqCst) - self.released.load(Ordering::SeqCst)
    }

    fn available_count(&self) -> usize {
        self.inner.lock().unwrap().free.len()
    }
}

/// El prestamo. Su `Drop` devuelve la conexion — en el return feliz, en el
/// temprano, y tambien mientras un panic desenrolla la pila.
struct Lease {
    pool: Arc<Pool>,
    conn: Option<Conn>,
}

impl Drop for Lease {
    fn drop(&mut self) {
        if let Some(conn) = self.conn.take() {
            self.pool.give_back(conn);
        }
    }
}

static POOL: LazyLock<Mutex<Arc<Pool>>> = LazyLock::new(|| Mutex::new(Pool::new(4)));

fn active_pool() -> Arc<Pool> {
    Arc::clone(&POOL.lock().unwrap())
}

// ---------- metricas ----------

#[derive(Default)]
struct Slot {
    runs: i64,
    completed: i64,
    failed_query: i64,
    failed_timeout: i64,
    hung: i64,
    max_leaked: i64,
    wait_samples: Vec<f64>,
}

static METRICS: LazyLock<Mutex<HashMap<String, Slot>>> =
    LazyLock::new(|| Mutex::new(fresh_metrics()));

fn fresh_metrics() -> HashMap<String, Slot> {
    let mut m = HashMap::new();
    m.insert("leaky".to_string(), Slot::default());
    m.insert("managed".to_string(), Slot::default());
    m
}

// ---------- trabajo ----------

/// Reparto determinista de fallos.
///
/// `idx % 100 < fail_rate` parece equivalente y no lo es: con 24 requests y
/// fail_rate=25 fallarian las 24, porque todos los indices son menores que 25.
fn fails(idx: usize, fail_rate: usize) -> bool {
    (idx * 37) % 100 < fail_rate
}

/// El trabajo que retiene la conexion: una espera, no CPU.
fn run_query(conn_id: i64, query_ms: u64, should_fail: bool) -> Result<(), String> {
    thread::sleep(Duration::from_millis(query_ms));
    if should_fail {
        return Err(format!("query fallo en la conexion {conn_id}"));
    }
    Ok(())
}

#[derive(Clone)]
struct Outcome {
    kind: &'static str,
    wait_ms: f64,
}

// ---------- variante leaky ----------

fn worker_leaky(idx: usize, query_ms: u64, fail_rate: usize, pool: &Arc<Pool>) -> Outcome {
    let t0 = Instant::now();
    let lease = match pool.acquire(LEAKY_WATCHDOG_MS) {
        Some(l) => l,
        None => return Outcome { kind: "hung", wait_ms: ms_since(t0) },
    };
    let wait_ms = ms_since(t0);
    let conn_id = lease.conn.as_ref().map(|c| c.id).unwrap_or(0);

    if run_query(conn_id, query_ms, fails(idx, fail_rate)).is_err() {
        // AQUI esta el bug, y en Rust hay que escribirlo a proposito.
        //
        // Sin esta linea el `Drop` de `lease` devolveria la conexion al salir
        // de la funcion, incluso por este camino de error. `mem::forget` se
        // queda con el valor y NO corre su Drop: es la unica forma de fugar un
        // recurso en Rust seguro.
        //
        // En los otros seis stacks este leak es lo que pasa si te distraes.
        // Aca hay que pedirlo por su nombre.
        std::mem::forget(lease);
        return Outcome { kind: "failed_query", wait_ms };
    }
    drop(lease);
    Outcome { kind: "completed", wait_ms }
}

// ---------- variante managed ----------

fn worker_managed(idx: usize, query_ms: u64, fail_rate: usize, pool: &Arc<Pool>) -> Outcome {
    let t0 = Instant::now();
    let lease = match pool.acquire(ACQUIRE_TIMEOUT_MS) {
        Some(l) => l,
        // Falla rapido y de forma contable, en vez de bloquear el hilo
        // esperando algo que ya no va a llegar.
        None => return Outcome { kind: "failed_timeout", wait_ms: ms_since(t0) },
    };
    let wait_ms = ms_since(t0);
    let conn_id = lease.conn.as_ref().map(|c| c.id).unwrap_or(0);

    // No hay `defer`, ni `finally`, ni `using`. El Drop de `lease` corre al
    // salir de la funcion por CUALQUIER camino, incluido este return de error.
    match run_query(conn_id, query_ms, fails(idx, fail_rate)) {
        Ok(()) => Outcome { kind: "completed", wait_ms },
        Err(_) => Outcome { kind: "failed_query", wait_ms },
    }
}

fn ms_since(t0: Instant) -> f64 {
    (t0.elapsed().as_micros() as f64) / 1000.0
}

// ---------- orquestacion ----------

fn run_load(variant: &str, requests: usize, pool_size: usize, query_ms: u64, fail_rate: usize) -> String {
    let pool = Pool::new(pool_size);
    *POOL.lock().unwrap() = Arc::clone(&pool);

    let t0 = Instant::now();
    let handles: Vec<_> = (0..requests)
        .map(|idx| {
            let pool = Arc::clone(&pool);
            let variant = variant.to_string();
            thread::spawn(move || {
                if variant == "leaky" {
                    worker_leaky(idx, query_ms, fail_rate, &pool)
                } else {
                    worker_managed(idx, query_ms, fail_rate, &pool)
                }
            })
        })
        .collect();

    let results: Vec<Outcome> = handles
        .into_iter()
        .map(|h| h.join().unwrap_or(Outcome { kind: "hung", wait_ms: 0.0 }))
        .collect();
    let wall_ms = ms_since(t0);

    let count = |k: &str| results.iter().filter(|r| r.kind == k).count() as i64;
    let completed = count("completed");
    let failed_query = count("failed_query");
    let failed_timeout = count("failed_timeout");
    let hung = count("hung");
    let mut waits: Vec<f64> = results.iter().map(|r| r.wait_ms).collect();
    waits.sort_by(|a, b| a.partial_cmp(b).unwrap());

    {
        let mut metrics = METRICS.lock().unwrap();
        let s = metrics.entry(variant.to_string()).or_default();
        s.runs += 1;
        s.completed += completed;
        s.failed_query += failed_query;
        s.failed_timeout += failed_timeout;
        s.hung += hung;
        s.max_leaked = s.max_leaked.max(pool.leaked());
        s.wait_samples.extend(waits.iter().copied());
        if s.wait_samples.len() > 500 {
            let cut = s.wait_samples.len() - 500;
            s.wait_samples.drain(0..cut);
        }
    }

    let note = if variant == "leaky" {
        "Sin deadline y con la fuga escrita a proposito con mem::forget: en Rust el leak hay que pedirlo por su nombre."
    } else {
        "Deadline explicito + Lease con impl Drop: la devolucion no depende de acordarse de ninguna linea."
    };
    let acquire_timeout = if variant == "managed" {
        ACQUIRE_TIMEOUT_MS.to_string()
    } else {
        "null".to_string()
    };
    let max_wait = waits.last().copied().unwrap_or(0.0);

    format!(
        r#"{{"variant":"{}","requests":{},"pool_size":{},"query_ms":{},"fail_rate_pct":{},"acquire_timeout_ms":{},"completed":{},"failed_query":{},"failed_timeout":{},"hung":{},"acquired":{},"released":{},"leaked":{},"pool_available_after":{},"pool_waiting_peak":{},"pool_wait_ms_p99":{},"pool_wait_ms_max":{},"wall_ms":{},"littles_law":{},"note":"{}"}}"#,
        escape(variant),
        requests,
        pool_size,
        query_ms,
        fail_rate,
        acquire_timeout,
        completed,
        failed_query,
        failed_timeout,
        hung,
        pool.acquired.load(Ordering::SeqCst),
        pool.released.load(Ordering::SeqCst),
        pool.leaked(),
        pool.available_count(),
        pool.waiting_peak.load(Ordering::SeqCst),
        percentile(&waits, 99),
        round2(max_wait),
        round2(wall_ms),
        littles_law(requests, query_ms, wall_ms),
        note
    )
}

fn littles_law(requests: usize, query_ms: u64, wall_ms: f64) -> String {
    if wall_ms <= 0.0 {
        return format!(
            r#"{{"avg_throughput_rps":0,"avg_query_ms":{query_ms},"recommended_pool_size":1}}"#
        );
    }
    let rps = requests as f64 / (wall_ms / 1000.0);
    let recommended = ((rps * (query_ms as f64 / 1000.0)).ceil() as i64 + 2).max(1);
    format!(
        r#"{{"avg_throughput_rps":{},"avg_query_ms":{},"recommended_pool_size":{},"formula":"ceil(throughput_rps * query_s) + 2 de buffer"}}"#,
        round2(rps),
        query_ms,
        recommended
    )
}

fn percentile(sorted: &[f64], pct: usize) -> f64 {
    if sorted.is_empty() {
        return 0.0;
    }
    let idx = ((pct * sorted.len() + 99) / 100).saturating_sub(1).min(sorted.len() - 1);
    round2(sorted[idx])
}

fn round2(v: f64) -> f64 {
    (v * 100.0).round() / 100.0
}

// ---------- rutas ----------

fn pool_state() -> String {
    let p = active_pool();
    format!(
        r#"{{"initialized":true,"pool_size":{},"available":{},"acquired_total":{},"released_total":{},"leaked":{},"waiting_now":{},"waiting_peak":{},"acquire_timeout_ms":{ACQUIRE_TIMEOUT_MS},"leaky_watchdog_ms":{LEAKY_WATCHDOG_MS}}}"#,
        p.size,
        p.available_count(),
        p.acquired.load(Ordering::SeqCst),
        p.released.load(Ordering::SeqCst),
        p.leaked(),
        p.waiting.load(Ordering::SeqCst),
        p.waiting_peak.load(Ordering::SeqCst)
    )
}

fn variant_json(name: &str, s: &Slot) -> String {
    let mut sorted = s.wait_samples.clone();
    sorted.sort_by(|a, b| a.partial_cmp(b).unwrap());
    let avg = if sorted.is_empty() {
        0.0
    } else {
        round2(sorted.iter().sum::<f64>() / sorted.len() as f64)
    };
    format!(
        r#""{}":{{"runs":{},"completed":{},"failed_query":{},"failed_timeout":{},"hung":{},"max_leaked":{},"avg_wait_ms":{},"p99_wait_ms":{}}}"#,
        escape(name),
        s.runs,
        s.completed,
        s.failed_query,
        s.failed_timeout,
        s.hung,
        s.max_leaked,
        avg,
        percentile(&sorted, 99)
    )
}

fn diagnostics() -> String {
    let metrics = METRICS.lock().unwrap();
    let leaky = metrics.get("leaky").map(|s| variant_json("leaky", s)).unwrap_or_default();
    let managed = metrics.get("managed").map(|s| variant_json("managed", s)).unwrap_or_default();
    drop(metrics);
    format!(
        r#"{{"stack":"{}","case":"{CASE_NAME}","variants":{{{},{}}},"pool":{},"interpretation":{{"leaky":"leaked > 0 y hung > 0: las conexiones perdidas en el camino de error no vuelven, y lo que llega despues espera a algo que ya no existe.","managed":"leaked = 0 siempre. Los fallos de query se siguen contando, pero la conexion vuelve al pool y el que no alcanza recibe un timeout rapido.","rust_note":"Con impl Drop la devolucion no depende de ninguna linea: la variante leaky tuvo que escribirse con mem::forget, la unica forma de fugar un recurso en Rust seguro."}}}}"#,
        escape(&stack()),
        leaky,
        managed,
        pool_state()
    )
}

fn stack() -> String {
    std::env::var("APP_STACK").unwrap_or_else(|_| "Rust 1.83".to_string())
}

fn route(path: &str, params: &HashMap<String, String>) -> (u16, String) {
    let requests = params
        .get("requests")
        .and_then(|v| v.parse::<usize>().ok())
        .unwrap_or(24)
        .clamp(1, 200);
    let pool_size = params
        .get("pool")
        .and_then(|v| v.parse::<usize>().ok())
        .unwrap_or(4)
        .clamp(1, 64);
    let query_ms = params
        .get("query_ms")
        .and_then(|v| v.parse::<u64>().ok())
        .unwrap_or(25)
        .clamp(1, 500);
    let fail_rate = params
        .get("fail_rate")
        .and_then(|v| v.parse::<usize>().ok())
        .unwrap_or(25)
        .clamp(0, 100);

    match path {
        "/" | "/index" => (
            200,
            format!(
                r#"{{"case":"{CASE_NAME}","stack":"{}","rust_specific":"Lease con impl Drop: la devolucion no depende de acordarse de ninguna linea, y fugar exige escribir mem::forget a proposito.","routes":["/health","/pool-leaky?requests=24&pool=4&query_ms=25&fail_rate=25","/pool-managed?requests=24&pool=4&query_ms=25&fail_rate=25","/pool/state","/diagnostics/summary","/reset-lab"]}}"#,
                escape(&stack())
            ),
        ),
        "/health" => (
            200,
            format!(r#"{{"status":"ok","stack":"{}","case":"{CASE_NAME}"}}"#, escape(&stack())),
        ),
        "/pool-leaky" => (200, run_load("leaky", requests, pool_size, query_ms, fail_rate)),
        "/pool-managed" => (200, run_load("managed", requests, pool_size, query_ms, fail_rate)),
        "/pool/state" => (200, pool_state()),
        "/diagnostics/summary" => (200, diagnostics()),
        "/reset-lab" => {
            *POOL.lock().unwrap() = Pool::new(pool_size);
            *METRICS.lock().unwrap() = fresh_metrics();
            (200, r#"{"status":"reset","message":"Pool reconstruido y metricas reiniciadas."}"#.to_string())
        }
        _ => (
            404,
            format!(r#"{{"error":"Ruta no encontrada","path":"{}"}}"#, escape(path)),
        ),
    }
}

// ---------- capa HTTP minima ----------

fn main() {
    let port: u16 = std::env::var("PORT").ok().and_then(|p| p.parse().ok()).unwrap_or(8080);
    let listener = TcpListener::bind(("0.0.0.0", port)).expect("bind");
    println!("[case14-rust] listening on {port}");
    for stream in listener.incoming().flatten() {
        thread::spawn(move || handle_conn(stream));
    }
}

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

fn escape(v: &str) -> String {
    v.replace('\\', "\\\\").replace('"', "\\\"")
}
