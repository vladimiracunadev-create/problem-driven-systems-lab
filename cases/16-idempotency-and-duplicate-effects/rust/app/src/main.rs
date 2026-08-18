// Caso 16 — Idempotencia y efectos duplicados — stack Rust 1.83.
//
// Unsafe: N reintentos del mismo pago aplican N cargos.
// Idempotent: `Idempotency-Key` persistida + outbox pattern.
//
// Primitiva Rust distintiva:
//
//   La **entry API**: `map.entry(key)` devuelve un `Entry`, que es un enum de
//   dos variantes — `Occupied` y `Vacant` — y el `match` es exhaustivo.
//
//       match table.entry(key) {
//           Entry::Occupied(e) => { /* ya estaba: reintento */ }
//           Entry::Vacant(e)   => { e.insert(v); /* soy el primero */ }
//       }
//
//   Es la misma operacion que `putIfAbsent` de Java, `TryAdd` de .NET y
//   `LoadOrStore` de Go, con una diferencia decisiva: **el compilador obliga a
//   contemplar las dos ramas**. En los otros tres, ignorar el valor de retorno
//   compila — y ese descarte silencioso es exactamente el bug del caso.
//
//   Hay algo mas que solo Rust aporta: el `Entry` **toma prestado el mapa**
//   mientras existe. Mientras se decide que hacer con la clave, nadie mas puede
//   tocar el mapa, y eso no es una convencion sino una regla del borrow checker.
//   La ventana check-then-act no es que sea dificil de escribir: es que el
//   prestamo la vuelve inexpresable.
//
// La segunda mitad es el **outbox pattern**: el cargo va a la base y el email a
// una cola, sin transaccion que los abarque. El outbox escribe el efecto en la
// misma escritura que el cargo y deja que un worker lo entregue.

use std::collections::hash_map::Entry;
use std::collections::HashMap;
use std::io::{BufRead, BufReader, Write};
use std::net::{TcpListener, TcpStream};
use std::sync::{Arc, Barrier, Condvar, LazyLock, Mutex};
use std::thread;
use std::time::{Instant, SystemTime, UNIX_EPOCH};

const CASE_NAME: &str = "16 - Idempotencia y efectos duplicados";
const DEDUPE_WINDOW_MS: i64 = 24 * 60 * 60 * 1000;
const MAX_ROWS: usize = 200;

struct IdemEntry {
    response: Mutex<Option<String>>,
    ready: Condvar,
    stored_at: i64,
}

#[derive(Clone)]
struct OutboxRow {
    key: String,
    kind: String,
    amount_cents: i64,
    at: String,
    status: String,
    via: String,
}

static LEDGER: LazyLock<Mutex<HashMap<String, i64>>> = LazyLock::new(|| Mutex::new(HashMap::new()));
static IDEMPOTENCY: LazyLock<Mutex<HashMap<String, Arc<IdemEntry>>>> =
    LazyLock::new(|| Mutex::new(HashMap::new()));
static OUTBOX: LazyLock<Mutex<Vec<OutboxRow>>> = LazyLock::new(|| Mutex::new(Vec::new()));
static DELIVERED: LazyLock<Mutex<Vec<OutboxRow>>> = LazyLock::new(|| Mutex::new(Vec::new()));

#[derive(Default, Clone)]
struct Slot {
    runs: i64,
    attempts: i64,
    charges_applied: i64,
    duplicates_prevented: i64,
    duplicates_applied: i64,
    idempotency_hits: i64,
    side_effects: i64,
    overcharged: i64,
}

static METRICS: LazyLock<Mutex<HashMap<String, Slot>>> = LazyLock::new(|| Mutex::new(fresh_metrics()));

fn fresh_metrics() -> HashMap<String, Slot> {
    let mut m = HashMap::new();
    m.insert("unsafe".to_string(), Slot::default());
    m.insert("idempotent".to_string(), Slot::default());
    m
}

fn now_ms() -> i64 {
    SystemTime::now().duration_since(UNIX_EPOCH).unwrap_or_default().as_millis() as i64
}

fn apply_charge(account: &str, amount: i64) -> i64 {
    let mut ledger = LEDGER.lock().unwrap();
    let e = ledger.entry(account.to_string()).or_insert(0);
    *e += amount;
    *e
}

/// El efecto DIRECTO, fuera de la transaccion del cargo.
fn emit_direct(key: &str, amount: i64) {
    let mut d = DELIVERED.lock().unwrap();
    d.push(OutboxRow {
        key: key.to_string(),
        kind: "payment_receipt_email".to_string(),
        amount_cents: amount,
        at: rfc3339_now(),
        status: "delivered".to_string(),
        via: "direct".to_string(),
    });
    let len = d.len();
    if len > MAX_ROWS {
        d.drain(0..len - MAX_ROWS);
    }
}

/// Escribe el efecto en el outbox, junto al cargo. No lo entrega.
fn enqueue_outbox(key: &str, amount: i64) {
    let mut o = OUTBOX.lock().unwrap();
    o.push(OutboxRow {
        key: key.to_string(),
        kind: "payment_receipt_email".to_string(),
        amount_cents: amount,
        at: rfc3339_now(),
        status: "pending".to_string(),
        via: "outbox".to_string(),
    });
    let len = o.len();
    if len > MAX_ROWS {
        o.drain(0..len - MAX_ROWS);
    }
}

/// El worker que mueve el outbox al destino real. Idempotente por diseño.
fn drain_outbox() -> usize {
    let mut o = OUTBOX.lock().unwrap();
    let mut d = DELIVERED.lock().unwrap();
    let mut moved = 0;
    for row in o.iter_mut() {
        if row.status == "pending" {
            row.status = "delivered".to_string();
            d.push(row.clone());
            moved += 1;
        }
    }
    let len = d.len();
    if len > MAX_ROWS {
        d.drain(0..len - MAX_ROWS);
    }
    moved
}

#[derive(Default, Clone, Copy)]
struct Outcome {
    applied: bool,
    hit: bool,
    lookup_ms: f64,
}

// ---------- variante unsafe ----------

fn attempt_unsafe(key: &str, account: &str, amount: i64, gate: &Barrier) -> Outcome {
    gate.wait();
    apply_charge(account, amount);
    emit_direct(key, amount);
    Outcome { applied: true, ..Default::default() }
}

// ---------- variante idempotent: entry API ----------

fn attempt_idempotent(key: &str, account: &str, amount: i64, gate: &Barrier) -> Outcome {
    gate.wait();
    let t0 = Instant::now();

    let (flight, leader) = {
        let mut table = IDEMPOTENCY.lock().unwrap();

        // Caducidad: fuera de la ventana la clave es una operacion nueva.
        if let Some(e) = table.get(key) {
            if now_ms() - e.stored_at > DEDUPE_WINDOW_MS {
                table.remove(key);
            }
        }

        // entry(): el `match` es exhaustivo y el `Entry` presta el mapa mientras
        // existe. La ventana check-then-act no es dificil de escribir — es
        // inexpresable, porque nadie mas puede tocar el mapa hasta que se
        // resuelva el match.
        match table.entry(key.to_string()) {
            Entry::Occupied(e) => (Arc::clone(e.get()), false),
            Entry::Vacant(e) => {
                let fresh = Arc::new(IdemEntry {
                    response: Mutex::new(None),
                    ready: Condvar::new(),
                    stored_at: now_ms(),
                });
                e.insert(Arc::clone(&fresh));
                (fresh, true)
            }
        }
    };

    if leader {
        // El cargo y el efecto pendiente se escriben JUNTOS.
        let balance = apply_charge(account, amount);
        enqueue_outbox(key, amount);
        let body = format!(
            r#"{{"status":"charged","key":"{}","account":"{}","amount_cents":{},"balance_cents":{}}}"#,
            escape(key), escape(account), amount, balance
        );
        *flight.response.lock().unwrap() = Some(body);
        flight.ready.notify_all();
        return Outcome { applied: true, lookup_ms: ms_since(t0), ..Default::default() };
    }

    // Reintento: se espera la respuesta del lider y se devuelve tal cual.
    let guard = flight.response.lock().unwrap();
    let _done = flight.ready.wait_while(guard, |r| r.is_none()).unwrap();
    Outcome { hit: true, lookup_ms: ms_since(t0), ..Default::default() }
}

fn ms_since(t0: Instant) -> f64 {
    (t0.elapsed().as_micros() as f64) / 1000.0
}

// ---------- orquestacion ----------

fn run_attempts(variant: &str, key: &str, account: &str, amount: i64, attempts: usize) -> String {
    // Largada comun: los reintentos de un cliente con timeout llegan casi juntos.
    let gate = Arc::new(Barrier::new(attempts));
    let t0 = Instant::now();
    let handles: Vec<_> = (0..attempts)
        .map(|_| {
            let gate = Arc::clone(&gate);
            let key = key.to_string();
            let account = account.to_string();
            let variant = variant.to_string();
            thread::spawn(move || {
                if variant == "unsafe" {
                    attempt_unsafe(&key, &account, amount, &gate)
                } else {
                    attempt_idempotent(&key, &account, amount, &gate)
                }
            })
        })
        .collect();

    let results: Vec<Outcome> = handles.into_iter().map(|h| h.join().unwrap_or_default()).collect();
    let wall_ms = ms_since(t0);

    let applied = results.iter().filter(|r| r.applied).count() as i64;
    let hits = results.iter().filter(|r| r.hit).count() as i64;
    let lookups: Vec<f64> = results.iter().map(|r| r.lookup_ms).filter(|v| *v > 0.0).collect();
    let delivered_now = if variant == "idempotent" { drain_outbox() } else { 0 };

    let balance = *LEDGER.lock().unwrap().get(account).unwrap_or(&0);
    let pending = OUTBOX.lock().unwrap().iter().filter(|r| r.status == "pending").count();
    let delivered_total = DELIVERED.lock().unwrap().len();
    let overcharged = if applied > 1 { (applied - 1) * amount } else { 0 };
    let effects = if variant == "unsafe" { attempts as i64 } else { delivered_now as i64 };
    let dup_applied = if applied > 1 { applied - 1 } else { 0 };

    {
        let mut m = METRICS.lock().unwrap();
        let s = m.entry(variant.to_string()).or_default();
        s.runs += 1;
        s.attempts += attempts as i64;
        s.charges_applied += applied;
        s.duplicates_prevented += hits;
        s.duplicates_applied += dup_applied;
        s.idempotency_hits += hits;
        s.side_effects += effects;
        s.overcharged += overcharged;
    }

    let avg_lookup = if lookups.is_empty() {
        0.0
    } else {
        round3(lookups.iter().sum::<f64>() / lookups.len() as f64)
    };

    let (note, transport) = if variant == "unsafe" {
        ("Sin clave de idempotencia: cada reintento aplica su propio cargo y publica su propio efecto. El cliente reintento por un timeout, no porque quisiera pagar de nuevo.",
         "directo, fuera de la transaccion")
    } else {
        ("La entry API resuelve la carrera con un match exhaustivo que el compilador exige + outbox en la misma escritura que el cargo: un cobro, un efecto, y los reintentos reciben la respuesta guardada.",
         "outbox, en la misma escritura que el cargo")
    };

    format!(
        r#"{{"variant":"{}","key":"{}","account":"{}","attempts":{},"amount_cents":{},"charges_applied":{},"duplicates_prevented":{},"duplicates_applied":{},"idempotency_hits":{},"balance_cents":{},"overcharged_cents":{},"side_effects_emitted":{},"side_effect_transport":"{}","outbox_pending":{},"outbox_delivered":{},"lookup_overhead_ms":{},"dedupe_window_ms":{DEDUPE_WINDOW_MS},"wall_ms":{},"note":"{}"}}"#,
        escape(variant), escape(key), escape(account), attempts, amount, applied, hits, dup_applied,
        hits, balance, overcharged, effects, transport, pending, delivered_total, avg_lookup,
        round2(wall_ms), note
    )
}

// ---------- rutas ----------

fn idempotency_state() -> String {
    let table = IDEMPOTENCY.lock().unwrap();
    let now = now_ms();
    let mut keys: Vec<&String> = table.keys().collect();
    keys.sort();
    let entries: Vec<String> = keys
        .iter()
        .map(|k| {
            let e = &table[*k];
            let age = now - e.stored_at;
            format!(
                r#""{}":{{"age_ms":{},"expired":{},"has_response":{}}}"#,
                escape(k), age, age > DEDUPE_WINDOW_MS, e.response.lock().unwrap().is_some()
            )
        })
        .collect();
    let count = table.len();
    drop(table);

    let ledger = LEDGER.lock().unwrap();
    let mut lkeys: Vec<&String> = ledger.keys().collect();
    lkeys.sort();
    let led: Vec<String> = lkeys.iter().map(|k| format!(r#""{}":{}"#, escape(k), ledger[*k])).collect();
    drop(ledger);

    format!(
        r#"{{"keys":{{{}}},"key_count":{},"ledger_cents":{{{}}},"dedupe_window_ms":{DEDUPE_WINDOW_MS},"note":"La tabla de idempotencia necesita ventana y limpieza: una clave que vive para siempre es una tabla que crece para siempre."}}"#,
        entries.join(","), count, led.join(",")
    )
}

fn rows_json(list: &[OutboxRow], limit: usize) -> String {
    let items: Vec<String> = list
        .iter()
        .rev()
        .take(limit)
        .map(|r| {
            format!(
                r#"{{"key":"{}","kind":"{}","amount_cents":{},"at":"{}","status":"{}","via":"{}"}}"#,
                escape(&r.key), escape(&r.kind), r.amount_cents, escape(&r.at),
                escape(&r.status), escape(&r.via)
            )
        })
        .collect();
    format!("[{}]", items.join(","))
}

fn outbox_view(limit: usize) -> String {
    let o = OUTBOX.lock().unwrap();
    let d = DELIVERED.lock().unwrap();
    let pending = o.iter().filter(|r| r.status == "pending").count();
    format!(
        r#"{{"outbox_pending":{},"outbox_total":{},"delivered_total":{},"limit":{},"outbox":{},"delivered":{},"note":"El outbox se escribe en la misma transaccion que el cargo. El worker que lo drena puede reintentar sin miedo: entregar dos veces el mismo row es visible y corregible, perder el efecto no."}}"#,
        pending, o.len(), d.len(), limit, rows_json(&o, limit), rows_json(&d, limit)
    )
}

fn variant_json(name: &str, s: &Slot) -> String {
    format!(
        r#""{}":{{"runs":{},"attempts":{},"charges_applied":{},"duplicates_prevented":{},"duplicates_applied":{},"idempotency_hits":{},"side_effects_emitted":{},"overcharged_cents":{}}}"#,
        escape(name), s.runs, s.attempts, s.charges_applied, s.duplicates_prevented,
        s.duplicates_applied, s.idempotency_hits, s.side_effects, s.overcharged
    )
}

fn diagnostics() -> String {
    let m = METRICS.lock().unwrap();
    let u = m.get("unsafe").map(|s| variant_json("unsafe", s)).unwrap_or_default();
    let i = m.get("idempotent").map(|s| variant_json("idempotent", s)).unwrap_or_default();
    drop(m);
    let pending = OUTBOX.lock().unwrap().iter().filter(|r| r.status == "pending").count();
    let delivered = DELIVERED.lock().unwrap().len();
    format!(
        r#"{{"stack":"{}","case":"{CASE_NAME}","variants":{{{},{}}},"outbox_pending":{},"outbox_delivered":{},"interpretation":{{"unsafe":"charges_applied = attempts: cada reintento cobro de nuevo. overcharged_cents es plata real que el negocio tiene que devolver.","idempotent":"charges_applied = 1 y duplicates_prevented = attempts - 1, sin importar cuantas veces reintente el cliente.","rust_note":"La entry API obliga a contemplar las dos ramas — Occupied y Vacant — y el Entry presta el mapa mientras existe. La ventana check-then-act no es dificil de escribir: es inexpresable."}}}}"#,
        escape(&stack()), u, i, pending, delivered
    )
}

fn stack() -> String {
    std::env::var("APP_STACK").unwrap_or_else(|_| "Rust 1.83".to_string())
}

fn route(path: &str, params: &HashMap<String, String>) -> (u16, String) {
    let mut key = params.get("key").cloned().unwrap_or_else(|| "order-4711".into());
    key.truncate(60);
    let mut account = params.get("account").cloned().unwrap_or_else(|| "acct-1".into());
    account.truncate(40);
    let attempts = params.get("attempts").and_then(|v| v.parse::<usize>().ok()).unwrap_or(5).clamp(1, 64);
    let amount = params.get("amount").and_then(|v| v.parse::<i64>().ok()).unwrap_or(2500).clamp(1, 10_000_000);
    let limit = params.get("limit").and_then(|v| v.parse::<usize>().ok()).unwrap_or(20).clamp(1, 200);

    match path {
        "/" | "/index" => (
            200,
            format!(
                r#"{{"case":"{CASE_NAME}","stack":"{}","rust_specific":"La entry API con match exhaustivo sobre Occupied/Vacant: el compilador obliga a contemplar las dos ramas, y el Entry presta el mapa mientras se decide.","routes":["/health","/charge-unsafe?key=order-4711&attempts=5&amount=2500","/charge-idempotent?key=order-4711&attempts=5&amount=2500","/idempotency/state","/outbox?limit=20","/diagnostics/summary","/reset-lab"]}}"#,
                escape(&stack())
            ),
        ),
        "/health" => (
            200,
            format!(r#"{{"status":"ok","stack":"{}","case":"{CASE_NAME}"}}"#, escape(&stack())),
        ),
        "/charge-unsafe" => (200, run_attempts("unsafe", &key, &account, amount, attempts)),
        "/charge-idempotent" => (200, run_attempts("idempotent", &key, &account, amount, attempts)),
        "/idempotency/state" => (200, idempotency_state()),
        "/outbox" => (200, outbox_view(limit)),
        "/diagnostics/summary" => (200, diagnostics()),
        "/reset-lab" => {
            LEDGER.lock().unwrap().clear();
            IDEMPOTENCY.lock().unwrap().clear();
            OUTBOX.lock().unwrap().clear();
            DELIVERED.lock().unwrap().clear();
            *METRICS.lock().unwrap() = fresh_metrics();
            (200, r#"{"status":"reset","message":"Ledger, claves de idempotencia y outbox reiniciados."}"#.to_string())
        }
        _ => (404, format!(r#"{{"error":"Ruta no encontrada","path":"{}"}}"#, escape(path))),
    }
}

// ---------- capa HTTP minima ----------

fn main() {
    let port: u16 = std::env::var("PORT").ok().and_then(|p| p.parse().ok()).unwrap_or(8080);
    let listener = TcpListener::bind(("0.0.0.0", port)).expect("bind");
    println!("[case16-rust] listening on {port}");
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

fn round2(v: f64) -> f64 {
    (v * 100.0).round() / 100.0
}

fn round3(v: f64) -> f64 {
    (v * 1000.0).round() / 1000.0
}

fn rfc3339_now() -> String {
    let d = SystemTime::now().duration_since(UNIX_EPOCH).unwrap_or_default();
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
