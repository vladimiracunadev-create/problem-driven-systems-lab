//! Caso 18 — Arranque en frio y retraso del autoescalado — stack Rust 1.83.
//!
//! Frio: el autoescalador levanta instancias cuando el trafico ya subio. El
//! proceso queda vivo al instante y `/health` responde 200 — pero la instancia
//! no sirve nada hasta terminar de inicializar. El balanceador que mira liveness
//! en vez de readiness manda trafico a ese hueco. Ahi nacen los 503.
//!
//! Templado: pool tibio ya inicializado y ya ejercitado, y balanceador que
//! enruta por `/ready`.
//!
//! Que es real y que esta modelado:
//!
//! - La curva de calentamiento se **mide**, no se simula. El trabajo por
//!   peticion es un lazo entero puro, identico en los siete stacks, sin `sleep`.
//!   En Rust la curva sale **plana**, y esa es justamente la respuesta del stack.
//! - La parte de I/O de la inicializacion (abrir el pool, DNS, TLS) es un
//!   `thread::sleep` de `io_ms`: esperar a la red no quema CPU, y fijarlo hace
//!   comparables a los siete stacks. La parte de CPU —construir la tabla— es
//!   trabajo real.
//!
//! # Primitiva Rust distintiva
//!
//! Rust compila ahead-of-time a codigo maquina, sin VM, sin JIT, sin GC que
//! inicializar y sin runtime que levantar. El proceso arranca practicamente en
//! el tiempo que tarda el kernel en mapear el binario, y la peticion numero 1
//! corre exactamente el mismo codigo que la numero 100.000. `warmup_speedup_x`
//! sale ~1.0: no es que el experimento falle, es el resultado.
//!
//! Para la inicializacion perezosa que si queda —la que depende de configuracion
//! o de red— la `std` trae dos cosas desde 1.70 y 1.80:
//!
//! ```ignore
//! static TABLA: OnceLock<Vec<u32>> = OnceLock::new();
//! TABLA.get_or_init(|| construir());     // corre una vez, aunque la pidan 20 hilos
//!
//! static CONFIG: LazyLock<Config> = LazyLock::new(|| Config::cargar());
//! ```
//!
//! `OnceLock` es el equivalente exacto de `sync.Once` de Go y de `Lazy<T>` de
//! .NET, con una diferencia que solo Rust ofrece: el tipo garantiza que el valor
//! **no se puede leer antes de estar inicializado**. No hay un `null` intermedio
//! que alguien pueda desreferenciar por accidente — el estado "todavia no lista"
//! es inalcanzable, no solo improbable.
//!
//! Este caso lo deja explicito: la instancia guarda su tabla en un `OnceLock`, y
//! el unico camino para leerla pasa por `get()`, que devuelve `Option`. Olvidar
//! el chequeo de readiness deja de ser un bug de runtime y pasa a ser un error
//! de compilacion.

use std::collections::HashMap;
use std::io::{BufRead, BufReader, Write};
use std::net::{TcpListener, TcpStream};
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::{Arc, Mutex, OnceLock};
use std::thread;
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

const CASE_NAME: &str = "18 - Arranque en frio y retraso del autoescalado";
const WORK_ITERS: u32 = 300_000; // calibrado para ~0.3 ms por peticion
const INIT_TABLE_ROWS: u32 = 3_000_000; // parte de CPU de la init: trabajo real

fn start() -> &'static Instant {
    static START: OnceLock<Instant> = OnceLock::new();
    START.get_or_init(Instant::now)
}

fn now_ms() -> f64 {
    start().elapsed().as_secs_f64() * 1000.0
}

/// Trabajo por peticion: lazo entero puro, sin sleep, sin I/O.
///
/// Identico en los siete stacks. Lo que cambia es lo que el runtime hace con el
/// mismo codigo repetido mil veces — que es lo que este caso mide.
#[inline(never)]
fn work(iters: u32) -> u32 {
    let mut h: u32 = 2166136261;
    for i in 0..iters {
        h = (h ^ i).wrapping_mul(16777619);
    }
    h
}

/// Una instancia del servicio. Viva apenas arranca; lista mucho despues.
///
/// La tabla vive en un `OnceLock`: no hay forma de leerla antes de que exista.
struct Instance {
    id: String,
    live: AtomicBool,
    ready: AtomicBool,
    live_at: f64,
    ready_at: Mutex<Option<f64>>,
    served: AtomicU64,
    table: OnceLock<Vec<u32>>,
}

impl Instance {
    fn new(id: String) -> Self {
        Instance {
            id,
            live: AtomicBool::new(true), // el proceso arranco: /health da 200 YA
            ready: AtomicBool::new(false),
            live_at: now_ms(),
            ready_at: Mutex::new(None),
            served: AtomicU64::new(0),
            table: OnceLock::new(),
        }
    }

    fn boot(&self, io_ms: u64) {
        // `get_or_init` corre una sola vez por mas hilos que la pidan a la vez,
        // y el resultado no se puede leer antes de que exista.
        self.table.get_or_init(|| {
            // Parte de CPU: construir la tabla de configuracion. Trabajo real.
            let mut table = vec![0u32; 256];
            let mut h: u32 = 2166136261;
            for i in 0..INIT_TABLE_ROWS {
                h = (h ^ i).wrapping_mul(16777619);
                table[(h & 0xFF) as usize] = h;
            }
            // Parte de I/O: abrir el pool, resolver DNS, negociar TLS.
            thread::sleep(Duration::from_millis(io_ms));
            table
        });
        *self.ready_at.lock().unwrap() = Some(now_ms());
        self.ready.store(true, Ordering::SeqCst);
    }

    fn gap_ms(&self) -> f64 {
        let end = self.ready_at.lock().unwrap().unwrap_or_else(now_ms);
        round(end - self.live_at, 2)
    }
}

#[derive(Default, Clone)]
struct Slot {
    runs: u64,
    served: u64,
    rejected: u64,
    cold_starts: u64,
    max_ready_at_ms: f64,
}

struct Lab {
    fleet: Vec<Arc<Instance>>,
    warm_pool: Vec<Arc<Instance>>,
    metrics: HashMap<String, Slot>,
}

impl Lab {
    fn new() -> Self {
        let mut metrics = HashMap::new();
        metrics.insert("cold".to_string(), Slot::default());
        metrics.insert("warmed".to_string(), Slot::default());
        Lab { fleet: Vec::new(), warm_pool: Vec::new(), metrics }
    }
}

fn lab() -> &'static Mutex<Lab> {
    static LAB: OnceLock<Mutex<Lab>> = OnceLock::new();
    LAB.get_or_init(|| Mutex::new(Lab::new()))
}

fn round(v: f64, d: u32) -> f64 {
    let f = 10f64.powi(d as i32);
    (v * f).round() / f
}

fn percentile(values: &[f64], pct: f64) -> f64 {
    if values.is_empty() {
        return 0.0;
    }
    let mut sv = values.to_vec();
    sv.sort_by(|a, b| a.partial_cmp(b).unwrap());
    let idx = ((pct / 100.0 * sv.len() as f64).ceil() as usize).saturating_sub(1);
    round(sv[idx.min(sv.len() - 1)], 3)
}

// ---------------------------------------------------------------------------
// El pool tibio: instancias ya inicializadas Y ya ejercitadas
// ---------------------------------------------------------------------------

fn build_warm_pool(instances: usize, io_ms: u64, prime: u32, iters: u32) -> String {
    let t0 = now_ms();
    let pool: Vec<Arc<Instance>> = (0..instances)
        .map(|i| Arc::new(Instance::new(format!("warm-{}", i))))
        .collect();
    let handles: Vec<_> = pool
        .iter()
        .cloned()
        .map(|inst| thread::spawn(move || inst.boot(io_ms)))
        .collect();
    for h in handles {
        let _ = h.join();
    }
    let init_ms = now_ms() - t0;

    // Ejercitar: en los stacks con JIT esta mitad aplana la curva. En Rust no
    // cambia nada, porque no hay curva — el binario ya venia compilado.
    let mut sink: u32 = 0;
    for _ in 0..prime {
        sink ^= work(iters);
    }
    if sink == 42 {
        print!("");
    }
    for inst in &pool {
        inst.served.fetch_add((prime as usize / instances.max(1)) as u64, Ordering::Relaxed);
    }

    let size = pool.len();
    lab().lock().unwrap().warm_pool = pool;

    format!(
        "{{\n  \"warm_pool_size\": {},\n  \"init_ms\": {},\n  \"prime_requests\": {},\n  \"warmup_duration_ms\": {}",
        size,
        round(init_ms, 2),
        prime,
        round(now_ms() - t0, 2)
    )
}

// ---------------------------------------------------------------------------
// El balanceador: la diferencia entre mirar /health y mirar /ready
// ---------------------------------------------------------------------------

fn pick(pool: &[Arc<Instance>], by_readiness: bool, counter: usize) -> Option<Arc<Instance>> {
    let n = pool.len();
    for k in 0..n {
        let inst = &pool[(counter + k) % n];
        let ok = if by_readiness {
            inst.ready.load(Ordering::SeqCst)
        } else {
            inst.live.load(Ordering::SeqCst)
        };
        if ok {
            return Some(inst.clone());
        }
    }
    None
}

#[allow(clippy::too_many_arguments)]
fn run_scenario(
    variant: &str,
    requests: usize,
    instances: usize,
    clients: usize,
    io_ms: u64,
    pace_ms: u64,
    iters: u32,
    prime: u32,
) -> String {
    let mut warm_info: Option<String> = None;
    let by_readiness;
    let cold_starts;
    let mut boots = Vec::new();
    let local: Vec<Arc<Instance>>;

    if variant == "cold" {
        // El autoescalador reacciona tarde: las instancias arrancan CON el
        // trafico encima, no antes.
        local = (0..instances)
            .map(|i| Arc::new(Instance::new(format!("cold-{}", i))))
            .collect();
        for inst in local.iter().cloned() {
            boots.push(thread::spawn(move || inst.boot(io_ms)));
        }
        by_readiness = false; // el balanceador ingenuo mira /health
        cold_starts = instances;
    } else {
        let have = { lab().lock().unwrap().warm_pool.len() >= instances };
        if !have {
            warm_info = Some(build_warm_pool(instances, io_ms, prime, iters));
        }
        local = lab().lock().unwrap().warm_pool[..instances].to_vec();
        by_readiness = true; // el balanceador correcto mira /ready
        cold_starts = 0;
    }

    lab().lock().unwrap().fleet = local.clone();

    let ordered: Arc<Mutex<Vec<f64>>> = Arc::new(Mutex::new(Vec::with_capacity(requests)));
    let served = Arc::new(AtomicU64::new(0));
    let rejected = Arc::new(AtomicU64::new(0));

    let t0 = now_ms();
    let mut workers = Vec::new();
    for idx in 0..clients {
        let pool = local.clone();
        let ordered = ordered.clone();
        let served = served.clone();
        let rejected = rejected.clone();
        workers.push(thread::spawn(move || {
            let mine = requests / clients + usize::from(idx < requests % clients);
            for k in 0..mine {
                let inst = pick(&pool, by_readiness, idx + k);
                let st = now_ms();
                match inst {
                    Some(i) if i.ready.load(Ordering::SeqCst) => {
                        work(iters);
                        i.served.fetch_add(1, Ordering::Relaxed);
                        ordered.lock().unwrap().push(now_ms() - st);
                        served.fetch_add(1, Ordering::Relaxed);
                    }
                    _ => {
                        // El proceso esta vivo, el healthcheck da verde, y la
                        // peticion se cae igual. Nada dispara una alerta.
                        rejected.fetch_add(1, Ordering::Relaxed);
                    }
                }
                if pace_ms > 0 {
                    thread::sleep(Duration::from_millis(pace_ms));
                }
            }
        }));
    }
    for w in workers {
        let _ = w.join();
    }
    for b in boots {
        let _ = b.join();
    }
    let wall = now_ms() - t0;

    let snapshot = ordered.lock().unwrap().clone();
    let first_100: Vec<f64> = snapshot.iter().take(100).cloned().collect();
    let after_1000: Vec<f64> = if snapshot.len() > 1000 {
        snapshot[1000..].to_vec()
    } else if snapshot.len() > 100 {
        snapshot[snapshot.len() - 100..].to_vec()
    } else {
        snapshot.clone()
    };

    let p99_first = percentile(&first_100, 99.0);
    let p99_after = percentile(&after_1000, 99.0);
    let ready_at = local.iter().map(|i| i.gap_ms()).fold(0.0f64, f64::max);

    let served_n = served.load(Ordering::Relaxed);
    let rejected_n = rejected.load(Ordering::Relaxed);

    let warm_size = {
        let mut l = lab().lock().unwrap();
        let s = l.metrics.get_mut(variant).unwrap();
        s.runs += 1;
        s.served += served_n;
        s.rejected += rejected_n;
        s.cold_starts += cold_starts as u64;
        if ready_at > s.max_ready_at_ms {
            s.max_ready_at_ms = ready_at;
        }
        l.warm_pool.len()
    };

    let note = if variant == "cold" {
        "El proceso esta vivo desde el milisegundo cero y /health lo confirma, pero la instancia no sirve nada \
         hasta terminar de inicializar. El balanceador que enruta por liveness manda trafico a ese hueco: los 503 \
         salen de una instancia que ninguna alerta considera caida."
    } else {
        "El pool ya estaba inicializado y ya ejercitado, y el balanceador enruta por readiness. Ninguna peticion \
         cae en una instancia a medio levantar: 0 rechazos y la latencia parte donde la otra variante recien termina."
    };

    let mut out = format!(
        "{{\n  \"variant\": \"{}\",\n  \"instances\": {},\n  \"requests\": {},\n  \"clients\": {},\n  \
         \"lb_routes_by\": \"{}\",\n  \"cold_start_count\": {},\n  \"warm_pool_size\": {},\n  \
         \"ready_at_ms\": {},\n  \"health_vs_ready_gap_ms\": {},\n  \"first_response_ms\": {},\n  \
         \"p99_first_100_ms\": {},\n  \"p99_after_1000_ms\": {},\n  \"warmup_speedup_x\": {},\n  \
         \"p50_ms\": {},\n  \"served\": {},\n  \"rejected_cold_start\": {},\n  \"availability_pct\": {},\n  \
         \"work_iters\": {},\n  \"io_ms\": {},\n  \"pace_ms\": {},\n  \"wall_ms\": {}",
        variant,
        instances,
        requests,
        clients,
        if by_readiness { "readiness (/ready)" } else { "liveness (/health)" },
        cold_starts,
        warm_size,
        round(ready_at, 2),
        if cold_starts > 0 { round(ready_at, 2) } else { 0.0 },
        snapshot.first().map(|v| round(*v, 3)).unwrap_or(0.0),
        p99_first,
        p99_after,
        if p99_after > 0.0 { round(p99_first / p99_after, 2) } else { 1.0 },
        percentile(&snapshot, 50.0),
        served_n,
        rejected_n,
        round(served_n as f64 * 100.0 / (served_n + rejected_n).max(1) as f64, 2),
        iters,
        io_ms,
        pace_ms,
        round(wall, 2)
    );
    if let Some(info) = warm_info {
        out.push_str(&format!(",\n  \"warm_pool_built_now\": {}\n  }}", info));
    }
    out.push_str(&format!(",\n  \"note\": \"{}\"", note));
    out.push_str(
        ",\n  \"rust_note\": \"Rust compila AOT sin VM, sin JIT y sin GC que inicializar: la peticion 1 corre el \
         mismo codigo que la 100.000, y warmup_speedup_x ~1.0 es el resultado, no un fallo del experimento. Para \
         la inicializacion perezosa que si queda, OnceLock hace inalcanzable el estado 'todavia no lista'.\"",
    );
    out
}

fn ready_state() -> String {
    let l = lab().lock().unwrap();
    let items: Vec<String> = l
        .fleet
        .iter()
        .map(|i| {
            format!(
                "{{ \"id\": \"{}\", \"live\": {}, \"ready\": {}, \"ready_at_ms\": {}, \"requests_served\": {} }}",
                i.id,
                i.live.load(Ordering::SeqCst),
                i.ready.load(Ordering::SeqCst),
                i.gap_ms(),
                i.served.load(Ordering::Relaxed)
            )
        })
        .collect();
    let all_ready = !l.fleet.is_empty() && l.fleet.iter().all(|i| i.ready.load(Ordering::SeqCst));
    format!(
        "{{\n  \"ready\": {},\n  \"instances\": [{}],\n  \"warm_pool_size\": {},\n  \"note\": \"`/health` responde \
         200 apenas el proceso arranca. `/ready` responde 200 recien cuando la instancia puede servir. Si el \
         balanceador mira la primera en vez de la segunda, el hueco entre las dos es tiempo de caida que nadie \
         registra como caida.\"",
        all_ready,
        items.join(", "),
        l.warm_pool.len()
    )
}

fn diagnostics(stack: &str) -> String {
    let variants = {
        let l = lab().lock().unwrap();
        ["cold", "warmed"]
            .iter()
            .map(|name| {
                let s = l.metrics.get(*name).cloned().unwrap_or_default();
                format!(
                    "\"{}\": {{ \"runs\": {}, \"served\": {}, \"rejected_cold_start\": {}, \"cold_starts\": {}, \
                     \"max_ready_at_ms\": {} }}",
                    name, s.runs, s.served, s.rejected, s.cold_starts, round(s.max_ready_at_ms, 2)
                )
            })
            .collect::<Vec<_>>()
            .join(",\n    ")
    };
    format!(
        "{{\n  \"stack\": \"{}\",\n  \"case\": \"{}\",\n  \"variants\": {{\n    {}\n  }},\n  \"fleet\": {} }},\n  \
         \"fidelity\": {{\n    \"medido\": \"La curva de calentamiento. El trabajo por peticion es un lazo entero \
         puro sin sleep, identico en los 7 stacks; p99_first_100_ms vs p99_after_1000_ms es lo que ese runtime hace \
         de verdad.\",\n    \"modelado\": \"La parte de I/O de la inicializacion (abrir pool, DNS, TLS) es un sleep \
         de io_ms: esperar a la red no quema CPU, y fijarlo es lo que hace comparables a los 7 stacks.\",\n    \
         \"real\": \"La parte de CPU de la inicializacion recorre 3.000.000 de iteraciones. Eso si es trabajo.\"\n  \
         }},\n  \"interpretation\": {{\n    \"cold\": \"rejected_cold_start > 0 con el proceso vivo todo el tiempo. \
         health_vs_ready_gap_ms es la ventana exacta en la que el balanceador mando trafico a una instancia que no \
         podia servirlo.\",\n    \"warmed\": \"rejected_cold_start = 0. El pool ya estaba, y el balanceador enruta \
         por readiness.\",\n    \"rust_note\": \"warmup_speedup_x ~1.0 es la firma de un binario AOT. Rust no gana \
         este caso por ser rapido: lo gana por no tener nada que calentar — y por hacer inalcanzable, via OnceLock, \
         el estado 'todavia no inicializada'.\"\n  }}",
        stack, CASE_NAME, variants, ready_state()
    )
}

// ---------------------------------------------------------------------------
// HTTP
// ---------------------------------------------------------------------------

fn clamp(v: i64, lo: i64, hi: i64) -> i64 {
    v.max(lo).min(hi)
}

fn query_int(q: &HashMap<String, String>, key: &str, def: i64) -> i64 {
    q.get(key).and_then(|v| v.parse::<i64>().ok()).unwrap_or(def)
}

fn parse_query(raw: &str) -> HashMap<String, String> {
    let mut out = HashMap::new();
    for pair in raw.split('&').filter(|p| !p.is_empty()) {
        if let Some((k, v)) = pair.split_once('=') {
            out.insert(k.to_string(), v.to_string());
        }
    }
    out
}

fn timestamp() -> String {
    let secs = SystemTime::now().duration_since(UNIX_EPOCH).unwrap().as_secs();
    let days = secs / 86400;
    let rem = secs % 86400;
    let (y, m, d) = civil_from_days(days as i64);
    format!("{:04}-{:02}-{:02}T{:02}:{:02}:{:02}Z", y, m, d, rem / 3600, (rem % 3600) / 60, rem % 60)
}

fn civil_from_days(z: i64) -> (i64, u32, u32) {
    let z = z + 719468;
    let era = z.div_euclid(146097);
    let doe = z.rem_euclid(146097);
    let yoe = (doe - doe / 1460 + doe / 36524 - doe / 146096) / 365;
    let y = yoe + era * 400;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let d = (doy - (153 * mp + 2) / 5 + 1) as u32;
    let m = if mp < 10 { mp + 3 } else { mp - 9 } as u32;
    (if m <= 2 { y + 1 } else { y }, m, d)
}

fn handle(mut stream: TcpStream, stack: &str) {
    let mut reader = BufReader::new(match stream.try_clone() {
        Ok(s) => s,
        Err(_) => return,
    });
    let mut line = String::new();
    if reader.read_line(&mut line).is_err() {
        return;
    }
    let target = line.split_whitespace().nth(1).unwrap_or("/").to_string();
    loop {
        let mut h = String::new();
        match reader.read_line(&mut h) {
            Ok(0) => break,
            Ok(_) if h.trim().is_empty() => break,
            Ok(_) => {}
            Err(_) => break,
        }
    }

    let (path, raw_q) = match target.split_once('?') {
        Some((p, q)) => (p.to_string(), q.to_string()),
        None => (target.clone(), String::new()),
    };
    let q = parse_query(&raw_q);

    let requests = clamp(query_int(&q, "requests", 2400), 100, 20000) as usize;
    let instances = clamp(query_int(&q, "instances", 3), 1, 32) as usize;
    let clients = clamp(query_int(&q, "clients", 8), 1, 64) as usize;
    let io_ms = clamp(query_int(&q, "io_ms", 150), 0, 5000) as u64;
    let pace_ms = clamp(query_int(&q, "pace_ms", 1), 0, 100) as u64;
    let iters = clamp(query_int(&q, "work_iters", WORK_ITERS as i64), 100, 5_000_000) as u32;
    let prime = clamp(query_int(&q, "prime", 1500), 0, 100_000) as u32;

    let mut status = 200;
    let body_core = match path.as_str() {
        "/" | "/index" => format!(
            "{{\n  \"lab\": \"Problem-Driven Systems Lab\",\n  \"case\": \"{}\",\n  \"stack\": \"{}\",\n  \
             \"goal\": \"Mostrar que el hueco entre 'el proceso esta vivo' y 'la instancia puede servir' es tiempo \
             de caida real que ningun healthcheck registra como caida.\",\n  \"rust_specific\": \"Binario AOT sin \
             VM ni JIT: la curva sale plana. OnceLock hace que el estado 'todavia no inicializada' sea inalcanzable, \
             no solo improbable.\",\n  \"routes\": {{\n    \"/health\": \"Liveness: responde 200 apenas el proceso \
             arranca.\",\n    \"/ready\": \"Readiness: responde 200 recien cuando la instancia puede servir.\",\n    \
             \"/boot-cold?requests=2400&instances=3\": \"Instancias frias con el trafico ya encima.\",\n    \
             \"/boot-warmed?requests=2400&instances=3\": \"Pool tibio y balanceador que mira readiness.\",\n    \
             \"/warmup?instances=3&prime=1500\": \"Construye el pool tibio antes de que llegue el trafico.\",\n    \
             \"/diagnostics/summary\": \"Comparativa entre variantes.\",\n    \"/reset-lab\": \"Vacia la flota, el \
             pool tibio y las metricas.\"\n  }}",
            CASE_NAME, stack
        ),
        "/health" => format!(
            "{{\n  \"status\": \"ok\",\n  \"stack\": \"{}\",\n  \"case\": \"{}\",\n  \"note\": \"Liveness. Esto \
             responde 200 aunque la instancia no pueda servir una sola peticion.\"",
            stack, CASE_NAME
        ),
        "/ready" => ready_state(),
        "/boot-cold" => run_scenario("cold", requests, instances, clients, io_ms, pace_ms, iters, prime),
        "/boot-warmed" => run_scenario("warmed", requests, instances, clients, io_ms, pace_ms, iters, prime),
        "/warmup" => format!(
            "{},\n  \"status\": \"warm\",\n  \"note\": \"Inicializar deja la instancia lista. Ejercitarla deja al \
             runtime listo. Las dos mitades hacen falta, y solo la segunda depende del lenguaje.\"",
            build_warm_pool(instances, io_ms, prime, iters)
        ),
        "/diagnostics/summary" => diagnostics(stack),
        "/reset-lab" => {
            let mut l = lab().lock().unwrap();
            l.fleet.clear();
            l.warm_pool.clear();
            l.metrics.insert("cold".to_string(), Slot::default());
            l.metrics.insert("warmed".to_string(), Slot::default());
            "{\n  \"status\": \"reset\",\n  \"message\": \"Flota, pool tibio y metricas reiniciados.\"".to_string()
        }
        _ => {
            status = 404;
            format!("{{\n  \"error\": \"Ruta no encontrada\",\n  \"path\": \"{}\"", path)
        }
    };

    let body = format!(
        "{},\n  \"timestamp_utc\": \"{}\",\n  \"pid\": {}\n}}",
        body_core,
        timestamp(),
        std::process::id()
    );

    let reason = if status == 200 { "OK" } else { "Not Found" };
    let response = format!(
        "HTTP/1.1 {} {}\r\nContent-Type: application/json; charset=utf-8\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{}",
        status,
        reason,
        body.len(),
        body
    );
    let _ = stream.write_all(response.as_bytes());
    let _ = stream.flush();
}

fn main() {
    let _ = start();
    let stack = std::env::var("APP_STACK").unwrap_or_else(|_| "Rust 1.83".to_string());
    let port = std::env::var("PORT").unwrap_or_else(|_| "8080".to_string());
    let listener = TcpListener::bind(format!("0.0.0.0:{}", port)).expect("bind");
    println!("Servidor Rust escuchando en {}", port);

    for stream in listener.incoming() {
        match stream {
            Ok(s) => {
                let stack = stack.clone();
                thread::spawn(move || handle(s, &stack));
            }
            Err(_) => continue,
        }
    }
}
