// Caso 15 — Backpressure en colas de mensajes — stack Rust 1.83.
//
// Unbounded: `mpsc::channel()`. Bounded: `mpsc::sync_channel(N)`.
//
// Primitiva Rust distintiva — dos cosas que ningun otro stack del lab tiene:
//
//   1. **El limite esta en el TIPO, no en un parametro.** `mpsc::channel()`
//      devuelve un `Sender<T>`; `mpsc::sync_channel(N)` devuelve un
//      `SyncSender<T>`. Son tipos distintos con metodos distintos. No se puede
//      "olvidar" el limite de un canal acotado ni pedirle backpressure a uno sin
//      limite: el compilador no deja escribir la confusion.
//
//      En Java, `ConcurrentLinkedQueue` y `ArrayBlockingQueue` implementan la
//      MISMA interfaz `Queue` — cambiar una por otra es una linea que compila y
//      saca el freno del sistema. Aca eso no existe.
//
//   2. **El error de rechazo se lleva el mensaje adentro.**
//
//          match tx.try_send(msg) {
//              Err(TrySendError::Full(msg)) => dlq.push(msg),   // <- msg vuelve
//              ...
//          }
//
//      `TrySendError::Full(T)` devuelve la propiedad del valor rechazado. En Go
//      o Java el mensaje descartado simplemente "sigue en tu mano" por
//      convencion; aca el tipo garantiza que no se perdio en el intento y que
//      hay que decidir explicitamente que hacer con el. Es exactamente lo que
//      una DLQ necesita.
//
// La leccion del caso: ninguna politica es gratis. Bloquear frena al productor,
// descartar pierde datos, y la DLQ muda el problema (eso es el caso 20).

use std::collections::HashMap;
use std::io::{BufRead, BufReader, Write};
use std::net::{TcpListener, TcpStream};
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::mpsc::{self, TrySendError};
use std::sync::{Arc, LazyLock, Mutex};
use std::thread;
use std::time::{Duration, Instant};

const CASE_NAME: &str = "15 - Backpressure en colas de mensajes";
const MSG_BYTES: i64 = 2048;
const POLICIES: [&str; 3] = ["block", "drop_oldest", "dead_letter"];

struct Msg {
    seq: i64,
    enqueued_at: Instant,
}

#[derive(Clone)]
struct DlqEntry {
    seq: i64,
    reason: String,
    at: String,
}

static DLQ: LazyLock<Mutex<Vec<DlqEntry>>> = LazyLock::new(|| Mutex::new(Vec::new()));
static LAST_STATE: LazyLock<Mutex<HashMap<String, String>>> =
    LazyLock::new(|| Mutex::new(HashMap::new()));

#[derive(Default)]
struct Slot {
    runs: i64,
    produced: i64,
    consumed: i64,
    dropped: i64,
    dead_lettered: i64,
    max_queue_depth: i64,
    max_oldest_age_ms: f64,
    producer_blocked_ms: f64,
}

static METRICS: LazyLock<Mutex<HashMap<String, Slot>>> =
    LazyLock::new(|| Mutex::new(fresh_metrics()));

fn fresh_metrics() -> HashMap<String, Slot> {
    let mut m = HashMap::new();
    m.insert("unbounded".to_string(), Slot::default());
    m.insert("bounded".to_string(), Slot::default());
    m
}

// ---------- variante unbounded: mpsc::channel() ----------

fn run_unbounded(messages: i64, consume_ms: u64) -> String {
    // `channel()` devuelve Sender<T>: sin capacidad, `send` nunca espera.
    let (tx, rx) = mpsc::channel::<Msg>();
    let depth = Arc::new(AtomicI64::new(0));
    let peak = Arc::new(AtomicI64::new(0));
    let consumed = Arc::new(AtomicI64::new(0));
    let oldest_us = Arc::new(AtomicI64::new(0));

    let c_depth = Arc::clone(&depth);
    let c_consumed = Arc::clone(&consumed);
    let c_oldest = Arc::clone(&oldest_us);
    let consumer = thread::spawn(move || {
        for m in rx {
            c_depth.fetch_sub(1, Ordering::SeqCst);
            // Se mide ANTES de procesar: es la latencia real del consumidor
            // final, y sin limite no tiene techo.
            let age_us = m.enqueued_at.elapsed().as_micros() as i64;
            c_oldest.fetch_max(age_us, Ordering::SeqCst);
            if consume_ms > 0 {
                thread::sleep(Duration::from_millis(consume_ms));
            }
            c_consumed.fetch_add(1, Ordering::SeqCst);
        }
    });

    let t0 = Instant::now();
    for seq in 0..messages {
        let _ = tx.send(Msg { seq, enqueued_at: Instant::now() });
        let d = depth.fetch_add(1, Ordering::SeqCst) + 1;
        peak.fetch_max(d, Ordering::SeqCst);
    }
    let depth_at_end = depth.load(Ordering::SeqCst);
    drop(tx);
    let _ = consumer.join();
    let wall_ms = ms_since(t0);

    result_json(
        "unbounded", None, None, messages, consumed.load(Ordering::SeqCst), 0, 0,
        peak.load(Ordering::SeqCst), depth_at_end,
        oldest_us.load(Ordering::SeqCst) as f64 / 1000.0, 0.0, 0, wall_ms,
        "mpsc::channel() devuelve un Sender<T> sin capacidad: send() nunca espera y la cola crece hasta donde de \
         la memoria. El tipo mismo dice que no hay freno.",
    )
}

// ---------- variante bounded: mpsc::sync_channel(N) ----------

fn run_bounded(messages: i64, capacity: usize, policy: &str, consume_ms: u64) -> String {
    // `sync_channel(N)` devuelve SyncSender<T>: un tipo DISTINTO, con `send`
    // bloqueante y `try_send` que devuelve el mensaje rechazado.
    let (tx, rx) = mpsc::sync_channel::<Msg>(capacity);
    let depth = Arc::new(AtomicI64::new(0));
    let peak = Arc::new(AtomicI64::new(0));
    let consumed = Arc::new(AtomicI64::new(0));
    let oldest_us = Arc::new(AtomicI64::new(0));

    let c_depth = Arc::clone(&depth);
    let c_consumed = Arc::clone(&consumed);
    let c_oldest = Arc::clone(&oldest_us);
    let consumer = thread::spawn(move || {
        for m in rx {
            c_depth.fetch_sub(1, Ordering::SeqCst);
            let age_us = m.enqueued_at.elapsed().as_micros() as i64;
            c_oldest.fetch_max(age_us, Ordering::SeqCst);
            if consume_ms > 0 {
                thread::sleep(Duration::from_millis(consume_ms));
            }
            c_consumed.fetch_add(1, Ordering::SeqCst);
        }
    });

    let t0 = Instant::now();
    let (mut produced, mut dropped, mut dead, mut signals) = (0i64, 0i64, 0i64, 0i64);
    let mut blocked_ms = 0.0f64;

    for seq in 0..messages {
        let msg = Msg { seq, enqueued_at: Instant::now() };
        match policy {
            "block" => {
                // send() bloqueante sobre SyncSender: la capacidad ES el freno.
                if depth.load(Ordering::SeqCst) >= capacity as i64 {
                    signals += 1;
                }
                let b0 = Instant::now();
                let _ = tx.send(msg);
                let waited = ms_since(b0);
                if waited > 0.5 {
                    blocked_ms += waited;
                }
                produced += 1;
                let d = depth.fetch_add(1, Ordering::SeqCst) + 1;
                peak.fetch_max(d, Ordering::SeqCst);
            }
            _ => {
                match tx.try_send(msg) {
                    Ok(()) => {
                        produced += 1;
                        let d = depth.fetch_add(1, Ordering::SeqCst) + 1;
                        peak.fetch_max(d, Ordering::SeqCst);
                    }
                    // El mensaje rechazado VUELVE dentro del error. No hay que
                    // clonarlo antes por las dudas ni confiar en una convencion:
                    // el tipo garantiza que sigue siendo nuestro.
                    Err(TrySendError::Full(rejected)) => {
                        signals += 1;
                        if policy == "drop_oldest" {
                            // El canal de la std no deja sacar el mas viejo, asi
                            // que se descarta el nuevo. Se cuenta igual como
                            // perdida de datos, que es lo que importa.
                            dropped += 1;
                            drop(rejected);
                        } else {
                            let mut dlq = DLQ.lock().unwrap();
                            dlq.push(DlqEntry {
                                seq: rejected.seq,
                                reason: "queue_full".to_string(),
                                at: rfc3339_now(),
                            });
                            let len = dlq.len();
                            if len > 200 {
                                dlq.drain(0..len - 200);
                            }
                            dead += 1;
                        }
                    }
                    Err(TrySendError::Disconnected(_)) => break,
                }
            }
        }
    }

    let depth_at_end = depth.load(Ordering::SeqCst);
    drop(tx);
    let _ = consumer.join();
    let wall_ms = ms_since(t0);

    let note = match policy {
        "block" => "send() bloqueante sobre SyncSender: la capacidad del canal ES la señal de backpressure. Nada se \
                    pierde, pero el productor se frena y esa lentitud viaja aguas arriba.",
        "drop_oldest" => "try_send() devuelve TrySendError::Full con el mensaje adentro y se descarta: el productor \
                          nunca se frena, pero se pierden datos en silencio.",
        _ => "try_send() devuelve el mensaje rechazado y va a la DLQ sin clonarlo: no se frena ni se pierde, pero el \
              problema se muda a otra cola que alguien tiene que mirar. Si nadie la mira, es el caso 20.",
    };

    result_json(
        "bounded", Some(policy), Some(capacity as i64), produced,
        consumed.load(Ordering::SeqCst), dropped, dead,
        peak.load(Ordering::SeqCst), depth_at_end,
        oldest_us.load(Ordering::SeqCst) as f64 / 1000.0, blocked_ms, signals, wall_ms, note,
    )
}

// ---------- salida ----------

#[allow(clippy::too_many_arguments)]
fn result_json(
    variant: &str, policy: Option<&str>, capacity: Option<i64>, produced: i64, consumed: i64,
    dropped: i64, dead: i64, peak: i64, depth_at_end: i64, oldest_ms: f64, blocked_ms: f64,
    signals: i64, wall_ms: f64, note: &str,
) -> String {
    {
        let mut metrics = METRICS.lock().unwrap();
        let s = metrics.entry(variant.to_string()).or_default();
        s.runs += 1;
        s.produced += produced;
        s.consumed += consumed;
        s.dropped += dropped;
        s.dead_lettered += dead;
        s.max_queue_depth = s.max_queue_depth.max(peak);
        if oldest_ms > s.max_oldest_age_ms {
            s.max_oldest_age_ms = oldest_ms;
        }
        s.producer_blocked_ms += blocked_ms;
    }
    {
        let mut st = LAST_STATE.lock().unwrap();
        st.insert("last_variant".into(), variant.into());
        st.insert("last_policy".into(), policy.unwrap_or("null").into());
        st.insert("capacity".into(), capacity.map(|c| c.to_string()).unwrap_or_else(|| "-1".into()));
        st.insert("queue_depth_peak".into(), peak.to_string());
        st.insert("queue_bytes_peak".into(), (peak * MSG_BYTES).to_string());
        st.insert("oldest_msg_age_ms_peak".into(), round2(oldest_ms).to_string());
    }

    let policy_json = match policy {
        Some(p) => format!("\"{}\"", escape(p)),
        None => "null".to_string(),
    };
    let capacity_json = match capacity {
        Some(c) => c.to_string(),
        None => "null".to_string(),
    };
    let throughput = if wall_ms > 0.0 { round2(produced as f64 / (wall_ms / 1000.0)) } else { 0.0 };

    format!(
        r#"{{"variant":"{}","policy":{},"capacity":{},"produced":{},"consumed":{},"dropped":{},"dead_lettered":{},"queue_depth_peak":{},"queue_depth_at_end_of_production":{},"queue_bytes_peak":{},"oldest_msg_age_ms_peak":{},"producer_blocked_ms":{},"backpressure_signals":{},"wall_ms":{},"throughput_msg_s":{},"note":"{}"}}"#,
        escape(variant), policy_json, capacity_json, produced, consumed, dropped, dead,
        peak, depth_at_end, peak * MSG_BYTES, round2(oldest_ms), round2(blocked_ms),
        signals, round2(wall_ms), throughput, note
    )
}

fn queue_state() -> String {
    let st = LAST_STATE.lock().unwrap();
    let g = |k: &str, d: &str| st.get(k).cloned().unwrap_or_else(|| d.to_string());
    let out = format!(
        r#"{{"last_variant":"{}","last_policy":"{}","capacity":{},"queue_depth_peak":{},"queue_bytes_peak":{},"oldest_msg_age_ms_peak":{},"dlq_depth":{},"msg_bytes":{MSG_BYTES},"policies":["block","drop_oldest","dead_letter"],"note":"queue_depth_peak x msg_bytes es lo que la cola llego a ocupar. mpsc::channel() no tiene techo."}}"#,
        escape(&g("last_variant", "")),
        escape(&g("last_policy", "")),
        g("capacity", "-1"),
        g("queue_depth_peak", "0"),
        g("queue_bytes_peak", "0"),
        g("oldest_msg_age_ms_peak", "0"),
        DLQ.lock().unwrap().len()
    );
    out
}

fn dlq_view(limit: usize) -> String {
    let dlq = DLQ.lock().unwrap();
    let items: Vec<String> = dlq
        .iter()
        .rev()
        .take(limit)
        .map(|e| {
            format!(
                r#"{{"seq":{},"reason":"{}","at":"{}"}}"#,
                e.seq,
                escape(&e.reason),
                escape(&e.at)
            )
        })
        .collect();
    format!(
        r#"{{"dlq_depth":{},"limit":{},"messages":[{}],"note":"La DLQ no resuelve el backpressure: lo muda. El caso 20 trata que pasa cuando nadie la mira."}}"#,
        dlq.len(),
        limit,
        items.join(",")
    )
}

fn variant_json(name: &str, s: &Slot) -> String {
    format!(
        r#""{}":{{"runs":{},"produced":{},"consumed":{},"dropped":{},"dead_lettered":{},"max_queue_depth":{},"max_oldest_age_ms":{},"producer_blocked_ms":{}}}"#,
        escape(name), s.runs, s.produced, s.consumed, s.dropped, s.dead_lettered,
        s.max_queue_depth, round2(s.max_oldest_age_ms), round2(s.producer_blocked_ms)
    )
}

fn diagnostics() -> String {
    let metrics = METRICS.lock().unwrap();
    let unb = metrics.get("unbounded").map(|s| variant_json("unbounded", s)).unwrap_or_default();
    let bnd = metrics.get("bounded").map(|s| variant_json("bounded", s)).unwrap_or_default();
    drop(metrics);
    format!(
        r#"{{"stack":"{}","case":"{CASE_NAME}","variants":{{{},{}}},"dlq_depth":{},"interpretation":{{"unbounded":"producer_blocked_ms = 0 y dropped = 0 se ven bien hasta que se mira queue_depth_peak y oldest_msg_age_ms_peak.","bounded":"Las tres politicas pagan algo distinto: block paga latencia del productor, drop_oldest paga datos, dead_letter paga deuda operativa.","rust_note":"El limite esta en el TIPO: Sender vs SyncSender son tipos distintos, asi que no se puede confundir uno con otro. Y TrySendError::Full devuelve el mensaje rechazado adentro, que es justo lo que una DLQ necesita."}}}}"#,
        escape(&stack()),
        unb,
        bnd,
        DLQ.lock().unwrap().len()
    )
}

fn stack() -> String {
    std::env::var("APP_STACK").unwrap_or_else(|_| "Rust 1.83".to_string())
}

fn route(path: &str, params: &HashMap<String, String>) -> (u16, String) {
    let messages = params.get("messages").and_then(|v| v.parse::<i64>().ok()).unwrap_or(120).clamp(1, 2000);
    let capacity = params.get("capacity").and_then(|v| v.parse::<usize>().ok()).unwrap_or(32).clamp(1, 1000);
    let consume_ms = params.get("consume_ms").and_then(|v| v.parse::<u64>().ok()).unwrap_or(2).clamp(0, 100);
    let limit = params.get("limit").and_then(|v| v.parse::<usize>().ok()).unwrap_or(20).clamp(1, 200);
    let policy_raw = params.get("policy").cloned().unwrap_or_else(|| "block".into());
    let policy = if POLICIES.contains(&policy_raw.as_str()) { policy_raw } else { "block".to_string() };

    match path {
        "/" | "/index" => (
            200,
            format!(
                r#"{{"case":"{CASE_NAME}","stack":"{}","rust_specific":"El limite esta en el tipo: Sender (sin capacidad) vs SyncSender (acotado). Y TrySendError::Full devuelve el mensaje rechazado adentro.","routes":["/health","/produce-unbounded?messages=120&consume_ms=2","/produce-bounded?messages=120&capacity=32&policy=block&consume_ms=2","/produce-bounded?messages=120&capacity=32&policy=drop_oldest","/produce-bounded?messages=120&capacity=32&policy=dead_letter","/queue/state","/dlq?limit=20","/diagnostics/summary","/reset-lab"],"allowed_policies":["block","drop_oldest","dead_letter"]}}"#,
                escape(&stack())
            ),
        ),
        "/health" => (
            200,
            format!(r#"{{"status":"ok","stack":"{}","case":"{CASE_NAME}"}}"#, escape(&stack())),
        ),
        "/produce-unbounded" => (200, run_unbounded(messages, consume_ms)),
        "/produce-bounded" => (200, run_bounded(messages, capacity, &policy, consume_ms)),
        "/queue/state" => (200, queue_state()),
        "/dlq" => (200, dlq_view(limit)),
        "/diagnostics/summary" => (200, diagnostics()),
        "/reset-lab" => {
            DLQ.lock().unwrap().clear();
            LAST_STATE.lock().unwrap().clear();
            *METRICS.lock().unwrap() = fresh_metrics();
            (200, r#"{"status":"reset","message":"DLQ y metricas reiniciadas."}"#.to_string())
        }
        _ => (404, format!(r#"{{"error":"Ruta no encontrada","path":"{}"}}"#, escape(path))),
    }
}

// ---------- capa HTTP minima ----------

fn main() {
    let port: u16 = std::env::var("PORT").ok().and_then(|p| p.parse().ok()).unwrap_or(8080);
    let listener = TcpListener::bind(("0.0.0.0", port)).expect("bind");
    println!("[case15-rust] listening on {port}");
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

fn ms_since(t0: Instant) -> f64 {
    (t0.elapsed().as_micros() as f64) / 1000.0
}

fn round2(v: f64) -> f64 {
    (v * 100.0).round() / 100.0
}

fn rfc3339_now() -> String {
    let d = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .unwrap_or_default();
    let secs = d.as_secs();
    let days = secs / 86400;
    let rem = secs % 86400;
    let (h, m, s) = (rem / 3600, (rem % 3600) / 60, rem % 60);
    let mut year = 1970u64;
    let mut dd = days;
    loop {
        let leap = (year % 4 == 0 && year % 100 != 0) || year % 400 == 0;
        let len = if leap { 366 } else { 365 };
        if dd < len {
            break;
        }
        dd -= len;
        year += 1;
    }
    let leap = (year % 4 == 0 && year % 100 != 0) || year % 400 == 0;
    let months = [31, if leap { 29 } else { 28 }, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    let mut month = 1;
    for len in months {
        if dd < len {
            break;
        }
        dd -= len;
        month += 1;
    }
    format!("{year:04}-{month:02}-{:02}T{h:02}:{m:02}:{s:02}Z", dd + 1)
}

fn escape(v: &str) -> String {
    v.replace('\\', "\\\\").replace('"', "\\\"")
}
