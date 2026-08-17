// Caso 13 — Cache stampede (thundering herd) — stack Rust 1.83.
//
// Naive: la clave expira y los N llamadores concurrentes recalculan el origen.
// `origin_computations == concurrency`.
// Single-flight: `origin_computations == 1` sin importar cuantos lleguen.
//
// Primitiva Rust distintiva:
//
//   Node resuelve esto con una Promise compartida, Java con un CompletableFuture,
//   .NET con un Lazy<Task<T>>. Los tres apoyan el patron en un objeto "resultado
//   futuro" que el runtime ya trae. La `std` de Rust **no tiene ninguno**: no hay
//   executor, no hay Future ejecutable sin un runtime externo como tokio.
//
//   Lo que si trae es la pieza de mas abajo: `Condvar`. El lider toma el Mutex,
//   calcula, deja el valor y hace `notify_all()`; los seguidores esperan con
//   `wait_while()`. Es el mecanismo que los otros runtimes tienen escondido
//   adentro de su primitiva de alto nivel.
//
//   Y hay algo que el compilador aporta y ningun otro stack del lab tiene: el
//   `Arc<Flight>` es OBLIGATORIO. En Go o Java uno puede quedarse con un
//   puntero a una entrada que otro hilo ya borro del mapa y el codigo compila;
//   aca no hay forma de expresar eso. El seguidor se lleva su propio `Arc`
//   clonado y el vuelo vive exactamente mientras alguien lo mire, sin que nadie
//   tenga que acordarse de nada.
//
//   `wait_while` en vez de `wait` no es cosmetico: protege del spurious wakeup,
//   el despertar sin notificacion que el sistema operativo puede producir. Con
//   `wait` a secas el seguidor podria leer un `None` y seguir de largo.
//
// El origen es CPU real (digest iterativo), no `thread::sleep`. Un sleep no
// modela lo que duele: que el origen HACE el trabajo N veces.

use std::collections::HashMap;
use std::io::{BufRead, BufReader, Write};
use std::net::{TcpListener, TcpStream};
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::{Arc, Barrier, Condvar, LazyLock, Mutex};
use std::thread;
use std::time::Instant;

const CASE_NAME: &str = "13 - Cache stampede y thundering herd";
const TTL_BASE_MS: i64 = 4000;
const JITTER_PCT: i64 = 25;
const SOFT_FRACTION: f64 = 0.6;

// ---------- cache ----------

#[derive(Clone)]
struct Entry {
    value: String,
    computed_at: Instant,
    soft_ms: i64,
    hard_ms: i64,
}

static CACHE: LazyLock<Mutex<HashMap<String, Entry>>> = LazyLock::new(|| Mutex::new(HashMap::new()));
static RNG_STATE: AtomicI64 = AtomicI64::new(130513);

fn next_rand(range: i64) -> i64 {
    let prev = RNG_STATE.load(Ordering::Relaxed);
    let next = (prev.wrapping_mul(9301).wrapping_add(49297)) % 233280;
    RNG_STATE.store(next, Ordering::Relaxed);
    next.rem_euclid(range)
}

fn ttl_with_jitter() -> (i64, i64) {
    let spread = TTL_BASE_MS * JITTER_PCT / 100;
    let jitter = next_rand(spread * 2 + 1) - spread;
    let hard = TTL_BASE_MS + jitter;
    (hard, (hard as f64 * SOFT_FRACTION) as i64)
}

fn cache_store(key: &str, value: String) {
    let (hard, soft) = ttl_with_jitter();
    CACHE.lock().unwrap().insert(
        key.to_string(),
        Entry { value, computed_at: Instant::now(), soft_ms: soft, hard_ms: hard },
    );
}

/// fresh | stale | miss
fn cache_state(key: &str) -> (String, &'static str) {
    let cache = CACHE.lock().unwrap();
    match cache.get(key) {
        None => (String::new(), "miss"),
        Some(e) => {
            let age = e.computed_at.elapsed().as_millis() as i64;
            if age <= e.soft_ms {
                (e.value.clone(), "fresh")
            } else if age <= e.hard_ms {
                (e.value.clone(), "stale")
            } else {
                (String::new(), "miss")
            }
        }
    }
}

// ---------- origen: trabajo real ----------

static ORIGIN_ACTIVE: AtomicI64 = AtomicI64::new(0);
static ORIGIN_PEAK: AtomicI64 = AtomicI64::new(0);

fn digest_work(key: &str, rounds: i64) -> String {
    let mut h: u32 = 0;
    let salt = (key.len() as u32).max(1);
    let iterations = rounds * 2000;
    for i in 0..iterations {
        h = h.wrapping_mul(31).wrapping_add((i as u32) ^ salt);
    }
    format!("{h:08x}")
}

fn compute_origin(key: &str, rounds: i64) -> String {
    let active = ORIGIN_ACTIVE.fetch_add(1, Ordering::SeqCst) + 1;
    ORIGIN_PEAK.fetch_max(active, Ordering::SeqCst);
    let digest = digest_work(key, rounds);
    cache_store(key, digest.clone());
    ORIGIN_ACTIVE.fetch_sub(1, Ordering::SeqCst);
    digest
}

// ---------- single-flight con Condvar ----------

/// Un recalculo en curso. El `Arc` que envuelve esto es lo que garantiza que el
/// vuelo sobreviva a su propia entrada en el mapa: el lider lo borra del
/// HashMap apenas termina, pero los seguidores siguen teniendo su clon.
struct Flight {
    /// `None` = todavia en vuelo. `Some(did)` = terminado, y `did` dice si
    /// realmente hubo que tocar el origen.
    result: Mutex<Option<bool>>,
    ready: Condvar,
}

static FLIGHTS: LazyLock<Mutex<HashMap<String, Arc<Flight>>>> =
    LazyLock::new(|| Mutex::new(HashMap::new()));

/// Devuelve (hubo_recalculo_real, fui_el_lider).
fn single_flight(key: &str, rounds: i64) -> (bool, bool) {
    let mut flights = FLIGHTS.lock().unwrap();
    if let Some(existing) = flights.get(key) {
        let flight = Arc::clone(existing);
        // Soltar el lock del mapa ANTES de esperar: con el tomado, el lider no
        // podria borrar su entrada y nadie despertaria nunca.
        drop(flights);
        let guard = flight.result.lock().unwrap();
        // wait_while re-chequea la condicion en cada despertar: inmune a los
        // spurious wakeups que `wait` a secas no cubre.
        let done = flight.ready.wait_while(guard, |r| r.is_none()).unwrap();
        return (done.unwrap_or(false), false);
    }

    let flight = Arc::new(Flight { result: Mutex::new(None), ready: Condvar::new() });
    flights.insert(key.to_string(), Arc::clone(&flight));
    drop(flights);

    // Double check dentro del vuelo. Sin esto el patron funciona pero no
    // alcanza: el lider de la primera generacion termina, borra su entrada del
    // mapa, y los hilos que todavia no habian llegado aca se vuelven lideres de
    // una segunda generacion. Con `rounds` chico eso da 3 o 4 recalculos en vez
    // de 1 — falta este `if`, no el patron.
    let (_, state) = cache_state(key);
    let did_compute = if state == "fresh" {
        false
    } else {
        compute_origin(key, rounds);
        true
    };

    {
        let mut slot = flight.result.lock().unwrap();
        *slot = Some(did_compute);
    }
    flight.ready.notify_all();
    FLIGHTS.lock().unwrap().remove(key);
    (did_compute, true)
}

// ---------- llamadores ----------

#[derive(Default, Clone, Copy)]
struct Outcome {
    wait_ms: f64,
    computed: bool,
    stale: bool,
    waited: bool,
}

fn caller_naive(key: &str, rounds: i64, gate: &Barrier) -> Outcome {
    gate.wait();
    let t0 = Instant::now();
    let (_, state) = cache_state(key);
    // Segunda fase: los N ya leyeron la cache antes de que ninguno escriba.
    // `Barrier` de std es reutilizable, asi que el mismo objeto sirve para las
    // dos fases sin construir otro.
    gate.wait();
    if state == "fresh" {
        return Outcome { wait_ms: ms_since(t0), ..Default::default() };
    }
    compute_origin(key, rounds);
    Outcome { wait_ms: ms_since(t0), computed: true, ..Default::default() }
}

fn caller_singleflight(key: &str, rounds: i64, gate: &Barrier) -> Outcome {
    gate.wait();
    let t0 = Instant::now();
    let (_, state) = cache_state(key);
    gate.wait();
    if state == "fresh" {
        return Outcome { wait_ms: ms_since(t0), ..Default::default() };
    }

    if state == "stale" {
        // Soft TTL vencida pero dentro de la hard: si ya hay alguien
        // refrescando, se devuelve el valor viejo sin pagar la espera.
        let refreshing = FLIGHTS.lock().unwrap().contains_key(key);
        if refreshing {
            return Outcome { wait_ms: ms_since(t0), stale: true, ..Default::default() };
        }
    }

    let (did_compute, leader) = single_flight(key, rounds);
    if leader && did_compute {
        Outcome { wait_ms: ms_since(t0), computed: true, ..Default::default() }
    } else {
        Outcome { wait_ms: ms_since(t0), waited: true, ..Default::default() }
    }
}

fn ms_since(t0: Instant) -> f64 {
    (t0.elapsed().as_micros() as f64) / 1000.0
}

// ---------- metricas ----------

#[derive(Default)]
struct Slot {
    runs: i64,
    origin_computations: i64,
    cache_hits: i64,
    coalesced_waiters: i64,
    served_stale: i64,
    max_stampede_depth: i64,
    wall_samples: Vec<f64>,
}

static METRICS: LazyLock<Mutex<HashMap<String, Slot>>> = LazyLock::new(|| Mutex::new(fresh_metrics()));

fn fresh_metrics() -> HashMap<String, Slot> {
    let mut m = HashMap::new();
    m.insert("naive".to_string(), Slot::default());
    m.insert("singleflight".to_string(), Slot::default());
    m
}

// ---------- rafaga ----------

fn run_burst(variant: &str, key: &str, concurrency: usize, rounds: i64) -> String {
    ORIGIN_PEAK.store(0, Ordering::SeqCst);
    let gate = Arc::new(Barrier::new(concurrency));
    let key_owned = key.to_string();
    let variant_owned = variant.to_string();

    let t0 = Instant::now();
    let handles: Vec<_> = (0..concurrency)
        .map(|_| {
            let gate = Arc::clone(&gate);
            let key = key_owned.clone();
            let variant = variant_owned.clone();
            thread::spawn(move || {
                if variant == "naive" {
                    caller_naive(&key, rounds, &gate)
                } else {
                    caller_singleflight(&key, rounds, &gate)
                }
            })
        })
        .collect();

    let results: Vec<Outcome> = handles
        .into_iter()
        .map(|h| h.join().unwrap_or_default())
        .collect();
    let wall_ms = ms_since(t0);

    let computations = results.iter().filter(|r| r.computed).count() as i64;
    let stale = results.iter().filter(|r| r.stale).count() as i64;
    let waiters = results.iter().filter(|r| r.waited).count() as i64;
    let hits = concurrency as i64 - computations - stale - waiters;
    let mut waits: Vec<f64> = results.iter().map(|r| r.wait_ms).collect();
    waits.sort_by(|a, b| a.partial_cmp(b).unwrap());
    let depth = ORIGIN_PEAK.load(Ordering::SeqCst);

    {
        let mut metrics = METRICS.lock().unwrap();
        let s = metrics.entry(variant.to_string()).or_default();
        s.runs += 1;
        s.origin_computations += computations;
        s.cache_hits += hits;
        s.coalesced_waiters += waiters;
        s.served_stale += stale;
        s.max_stampede_depth = s.max_stampede_depth.max(depth);
        s.wall_samples.push(wall_ms);
        if s.wall_samples.len() > 200 {
            s.wall_samples.remove(0);
        }
    }

    let (value, _) = cache_state(key);
    let note = if variant == "naive" {
        "Sin coordinacion: cada llamador que vio el miss recalcula. El origen recibe la rafaga entera."
    } else {
        "Arc<Flight> con Mutex + Condvar: el lider notifica, los seguidores esperan con wait_while."
    };
    let max_wait = waits.last().copied().unwrap_or(0.0);

    format!(
        r#"{{"variant":"{}","key":"{}","concurrency":{},"cost_rounds":{},"origin_computations":{},"cache_hits":{},"coalesced_waiters":{},"served_stale":{},"stampede_depth":{},"wall_ms":{},"p99_wait_ms":{},"max_wait_ms":{},"value_digest":"{}","ttl_base_ms":{},"jitter_pct":{},"note":"{}"}}"#,
        escape(variant),
        escape(key),
        concurrency,
        rounds,
        computations,
        hits,
        waiters,
        stale,
        depth,
        round2(wall_ms),
        percentile(&waits, 99),
        round2(max_wait),
        escape(&value),
        TTL_BASE_MS,
        JITTER_PCT,
        note
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

fn cache_state_json() -> String {
    let cache = CACHE.lock().unwrap();
    let mut keys: Vec<&String> = cache.keys().collect();
    keys.sort();
    let entries: Vec<String> = keys
        .iter()
        .map(|k| {
            let e = &cache[*k];
            let age = e.computed_at.elapsed().as_millis() as i64;
            format!(
                r#""{}":{{"age_ms":{},"soft_ttl_ms":{},"hard_ttl_ms":{},"soft_expired":{},"hard_expired":{},"value_digest":"{}"}}"#,
                escape(k),
                age,
                e.soft_ms,
                e.hard_ms,
                age > e.soft_ms,
                age > e.hard_ms,
                escape(&e.value)
            )
        })
        .collect();
    drop(cache);

    let flights = FLIGHTS.lock().unwrap();
    let mut fkeys: Vec<String> = flights.keys().cloned().collect();
    drop(flights);
    fkeys.sort();
    let inflight: Vec<String> = fkeys.iter().map(|k| format!(r#""{}""#, escape(k))).collect();

    format!(
        r#"{{"entries":{{{}}},"ttl_base_ms":{},"jitter_pct":{},"soft_fraction":{},"inflight_keys":[{}]}}"#,
        entries.join(","),
        TTL_BASE_MS,
        JITTER_PCT,
        SOFT_FRACTION,
        inflight.join(",")
    )
}

fn variant_json(name: &str, s: &Slot) -> String {
    let mut sorted = s.wall_samples.clone();
    sorted.sort_by(|a, b| a.partial_cmp(b).unwrap());
    let avg = if sorted.is_empty() {
        0.0
    } else {
        round2(sorted.iter().sum::<f64>() / sorted.len() as f64)
    };
    format!(
        r#""{}":{{"runs":{},"origin_computations":{},"cache_hits":{},"coalesced_waiters":{},"served_stale":{},"max_stampede_depth":{},"avg_wall_ms":{},"p99_wall_ms":{}}}"#,
        escape(name),
        s.runs,
        s.origin_computations,
        s.cache_hits,
        s.coalesced_waiters,
        s.served_stale,
        s.max_stampede_depth,
        avg,
        percentile(&sorted, 99)
    )
}

fn diagnostics() -> String {
    let metrics = METRICS.lock().unwrap();
    let naive = metrics.get("naive").map(|s| variant_json("naive", s)).unwrap_or_default();
    let sf = metrics
        .get("singleflight")
        .map(|s| variant_json("singleflight", s))
        .unwrap_or_default();
    let total: i64 = metrics.values().map(|s| s.origin_computations).sum();
    format!(
        r#"{{"stack":"{}","case":"{CASE_NAME}","variants":{{{},{}}},"origin_total_computations":{},"interpretation":{{"naive":"origin_computations crece linealmente con la concurrencia: el origen ve la rafaga completa.","singleflight":"origin_computations se mantiene en 1 por expiracion, sin importar cuantos llamadores lleguen.","rust_note":"std no trae Future ejecutable: el single-flight se apoya en Condvar, y el Arc obliga a que el vuelo sobreviva a su entrada en el mapa."}}}}"#,
        escape(&stack()),
        naive,
        sf,
        total
    )
}

fn stack() -> String {
    std::env::var("APP_STACK").unwrap_or_else(|_| "Rust 1.83".to_string())
}

fn route(path: &str, params: &HashMap<String, String>) -> (u16, String) {
    let mut key = params
        .get("key")
        .cloned()
        .unwrap_or_else(|| "report-alpha".to_string());
    if key.len() > 60 {
        key.truncate(60);
    }
    let concurrency = params
        .get("concurrency")
        .and_then(|v| v.parse::<usize>().ok())
        .unwrap_or(16)
        .clamp(1, 128);
    let rounds = params
        .get("cost")
        .and_then(|v| v.parse::<i64>().ok())
        .unwrap_or(40)
        .clamp(1, 400);

    match path {
        "/" | "/index" => (
            200,
            format!(
                r#"{{"case":"{CASE_NAME}","stack":"{}","rust_specific":"Mutex + Condvar + Arc: la std no trae Future ejecutable, asi que el single-flight se construye con la primitiva de mas abajo.","routes":["/health","/cache-naive?key=report-alpha&concurrency=16&cost=40","/cache-singleflight?key=report-alpha&concurrency=16&cost=40","/cache/state","/diagnostics/summary","/reset-lab"]}}"#,
                escape(&stack())
            ),
        ),
        "/health" => (
            200,
            format!(
                r#"{{"status":"ok","stack":"{}","case":"{CASE_NAME}"}}"#,
                escape(&stack())
            ),
        ),
        "/cache-naive" => (200, run_burst("naive", &key, concurrency, rounds)),
        "/cache-singleflight" => (200, run_burst("singleflight", &key, concurrency, rounds)),
        "/cache/state" => (200, cache_state_json()),
        "/diagnostics/summary" => (200, diagnostics()),
        "/reset-lab" => {
            CACHE.lock().unwrap().clear();
            FLIGHTS.lock().unwrap().clear();
            *METRICS.lock().unwrap() = fresh_metrics();
            ORIGIN_PEAK.store(0, Ordering::SeqCst);
            (200, r#"{"status":"reset","message":"Cache y metricas reiniciadas."}"#.to_string())
        }
        _ => (
            404,
            format!(r#"{{"error":"Ruta no encontrada","path":"{}"}}"#, escape(path)),
        ),
    }
}

// ---------- capa HTTP minima ----------

fn main() {
    let port: u16 = std::env::var("PORT")
        .ok()
        .and_then(|p| p.parse().ok())
        .unwrap_or(8080);
    let listener = TcpListener::bind(("0.0.0.0", port)).expect("bind");
    println!("[case13-rust] listening on {port}");
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
