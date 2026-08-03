// Caso 12 — Punto unico de conocimiento y riesgo operacional — stack Rust 1.83.
//
// Legacy: el incidente con owner ausente hace acceso ciego y revienta.
// Distributed: la ausencia esta en el tipo, el fallback es codigo, no folclore.
//
// El contraste que este stack aporta, y por que Rust cierra el arco del lab:
//
//   Java resuelve esto con `Optional<T>`, Node con `?.`, Go con comma-ok.
//   Rust usa `Option<T>` — que se parece al Optional de Java pero tiene una
//   diferencia decisiva: **el `match` es exhaustivo y el compilador lo exige**.
//
//     Java:  opt.get()                 // compila; explota en runtime si esta vacio
//     Go:    v, _ := m[k]              // compila; ignorar `ok` es legal
//     Rust:  match opt { Some(v) => …, None => … }   // omitir None NO compila
//
//   Y el operador `?` propaga la ausencia hacia arriba sin escribir un if:
//   `let script = owner?.runbook.get(key)?;` devuelve None en cuanto algo falta.
//
//   La variante legacy usa `.unwrap()` a proposito: es el escape hatch que
//   convierte una ausencia en un panic. Existe, es facil de escribir, y por eso
//   `unwrap()` en codigo de produccion es exactamente el mismo olor que un
//   `Optional.get()` sin `isPresent()`. Rust no impide el atajo; lo hace
//   visible en una sola palabra que cualquier reviewer puede grepear.

use std::collections::HashMap;
use std::io::{BufRead, BufReader, Write};
use std::net::{TcpListener, TcpStream};
use std::panic;
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::{LazyLock, Mutex};
use std::thread;
use std::time::{SystemTime, UNIX_EPOCH};

const CASE_NAME: &str = "12 - Punto unico de conocimiento";
const MAX_INCIDENTS: usize = 30;

static LEGACY_INCIDENTS: AtomicI64 = AtomicI64::new(0);
static LEGACY_CRASHED: AtomicI64 = AtomicI64::new(0);
static DISTRIBUTED_INCIDENTS: AtomicI64 = AtomicI64::new(0);
static DISTRIBUTED_HANDLED: AtomicI64 = AtomicI64::new(0);
static COVERAGE: AtomicI64 = AtomicI64::new(30);
static BUS_FACTOR: AtomicI64 = AtomicI64::new(1);
static RNG_STATE: AtomicI64 = AtomicI64::new(120512);

#[derive(Clone)]
struct Owner {
    name: String,
    available: bool,
    runbook: HashMap<String, String>,
}

#[derive(Clone)]
struct Incident {
    at: String,
    variant: String,
    scenario: String,
    result: String,
    mttr_min: i64,
}

static OWNERS: LazyLock<Mutex<HashMap<String, Owner>>> = LazyLock::new(|| Mutex::new(fresh_owners()));
static INCIDENTS: Mutex<Vec<Incident>> = Mutex::new(Vec::new());

fn fresh_owners() -> HashMap<String, Owner> {
    let mut runbook = HashMap::new();
    runbook.insert("db_failover".to_string(), "step1; step2; step3".to_string());
    runbook.insert(
        "cache_purge".to_string(),
        "redis-cli flushall on prod-cache-01".to_string(),
    );
    let mut m = HashMap::new();
    m.insert(
        "alice".to_string(),
        Owner { name: "alice".into(), available: true, runbook },
    );
    m
}

fn stack() -> String {
    std::env::var("APP_STACK").unwrap_or_else(|_| "Rust 1.83".to_string())
}

fn main() {
    // Silenciar el backtrace por defecto: el panic de la variante legacy es
    // intencional y se reporta como dato del caso, no como ruido.
    panic::set_hook(Box::new(|_| {}));

    let port: u16 = std::env::var("PORT").ok().and_then(|p| p.parse().ok()).unwrap_or(8080);
    let listener = TcpListener::bind(("0.0.0.0", port)).expect("bind");
    println!("[case12-rust] listening on {port}");
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
    let scenario = params.get("scenario").cloned().unwrap_or_else(|| "owner_absent".into());
    let runbook_key = params.get("runbook").cloned().unwrap_or_else(|| "db_failover".into());
    let owner_name = params.get("owner").cloned().unwrap_or_else(|| "bob".into());

    match path {
        "/" | "/index" => (
            200,
            format!(
                r#"{{"case":"{CASE_NAME}","stack":"{}","routes":["/health","/incident-legacy?scenario=owner_absent&runbook=db_failover","/incident-distributed?scenario=owner_absent&runbook=db_failover","/share-knowledge?owner=bob&runbook=db_failover","/incidents","/diagnostics/summary","/reset-lab"]}}"#,
                stack()
            ),
        ),
        "/health" => (
            200,
            format!(r#"{{"status":"ok","stack":"{}","case":"{CASE_NAME}"}}"#, stack()),
        ),
        "/incident-legacy" => {
            let body = incident_legacy(&scenario, &runbook_key);
            LEGACY_INCIDENTS.fetch_add(1, Ordering::Relaxed);
            (200, body)
        }
        "/incident-distributed" => {
            let body = incident_distributed(&scenario, &runbook_key);
            DISTRIBUTED_INCIDENTS.fetch_add(1, Ordering::Relaxed);
            (200, body)
        }
        "/share-knowledge" => (200, share_knowledge(&owner_name, &runbook_key)),
        "/incidents" => (200, incidents_json()),
        "/diagnostics/summary" => (200, diagnostics()),
        "/reset-lab" => {
            LEGACY_INCIDENTS.store(0, Ordering::Relaxed);
            LEGACY_CRASHED.store(0, Ordering::Relaxed);
            DISTRIBUTED_INCIDENTS.store(0, Ordering::Relaxed);
            DISTRIBUTED_HANDLED.store(0, Ordering::Relaxed);
            COVERAGE.store(30, Ordering::Relaxed);
            BUS_FACTOR.store(1, Ordering::Relaxed);
            *OWNERS.lock().unwrap() = fresh_owners();
            INCIDENTS.lock().unwrap().clear();
            (200, r#"{"status":"reset"}"#.to_string())
        }
        _ => (
            404,
            format!(r#"{{"error":"not_found","path":"{}"}}"#, escape(path)),
        ),
    }
}

// ---------- endpoints ----------

/// Devuelve Option: la ausencia esta en el tipo.
fn pick_owner_legacy(scenario: &str) -> Option<Owner> {
    if scenario == "owner_absent" || scenario == "night_shift" {
        return None;
    }
    OWNERS.lock().unwrap().get("alice").cloned()
}

/// Legacy: usa `.unwrap()` — el atajo que convierte la ausencia en panic.
/// `catch_unwind` es el ultimo recurso que impide que el incidente tumbe el
/// proceso, el equivalente del catch de los otros stacks.
fn incident_legacy(scenario: &str, runbook_key: &str) -> String {
    let scenario_owned = scenario.to_string();
    let key_owned = runbook_key.to_string();

    let outcome = panic::catch_unwind(move || {
        let owner = pick_owner_legacy(&scenario_owned).unwrap(); // panic si no hay owner
        let script = owner.runbook.get(&key_owned).unwrap();     // panic si no hay runbook
        let executed = script.to_uppercase();
        let mttr = rand_between(25, 30);
        (executed, mttr)
    });

    match outcome {
        Ok((executed, mttr)) => {
            record("legacy", scenario, "executed", mttr);
            let preview: String = executed.chars().take(40).collect();
            format!(
                r#"{{"variant":"legacy","status":"executed","scenario":"{}","runbook":"{}","mttr_min":{mttr},"executed_preview":"{}..."}}"#,
                escape(scenario),
                escape(runbook_key),
                escape(&preview)
            )
        }
        Err(_) => {
            LEGACY_CRASHED.fetch_add(1, Ordering::Relaxed);
            record("legacy", scenario, "crashed: unwrap on None", 120);
            format!(
                r#"{{"variant":"legacy","status":"crashed","scenario":"{}","reason":"panic: called `Option::unwrap()` on a `None` value","mttr_min":120,"note":"acceso ciego con unwrap() falla cuando owner/runbook ausente."}}"#,
                escape(scenario)
            )
        }
    }
}

fn pick_owner_distributed(scenario: &str) -> Option<Owner> {
    let owners = OWNERS.lock().unwrap();
    if scenario == "owner_absent" || scenario == "night_shift" {
        return owners
            .values()
            .find(|o| o.name != "alice" && o.available)
            .cloned();
    }
    owners.get("alice").cloned()
}

/// Distributed: el operador `?` propaga la ausencia sin un solo `if`, y el
/// `match` posterior obliga a contemplar el caso vacio.
fn incident_distributed(scenario: &str, runbook_key: &str) -> String {
    // `?` en una closure que devuelve Option: si falta owner o falta runbook,
    // el resultado es None y no hay forma de leer un valor inexistente.
    let script: Option<String> = (|| {
        let owner = pick_owner_distributed(scenario)?;
        let script = owner.runbook.get(runbook_key)?;
        Some(script.clone())
    })();

    let (mttr, result) = match script {
        Some(_) => (rand_between(15, 10), "executed_by_primary"),
        None => (rand_between(35, 15), "owner_absent_handled_via_team_runbook"),
    };

    let primary_available = pick_owner_distributed(scenario)
        .map(|o| o.available)
        .unwrap_or(false);

    DISTRIBUTED_HANDLED.fetch_add(1, Ordering::Relaxed);
    record("distributed", scenario, result, mttr);

    format!(
        r#"{{"variant":"distributed","status":"handled","scenario":"{}","runbook":"{}","result":"{result}","primary_available":{primary_available},"mttr_min":{mttr},"coverage":{},"bus_factor":{},"note":"Option + operador ? : el match es exhaustivo, el compilador exige contemplar la ausencia."}}"#,
        escape(scenario),
        escape(runbook_key),
        COVERAGE.load(Ordering::Relaxed),
        BUS_FACTOR.load(Ordering::Relaxed)
    )
}

fn share_knowledge(name: &str, runbook_key: &str) -> String {
    let mut owners = OWNERS.lock().unwrap();
    let alice = match owners.get("alice") {
        Some(a) => a.clone(),
        None => {
            return r#"{"status":"error","reason":"primary owner missing"}"#.to_string();
        }
    };
    owners.insert(
        name.to_string(),
        Owner { name: name.to_string(), available: true, runbook: alice.runbook.clone() },
    );
    drop(owners);

    let new_coverage = (COVERAGE.load(Ordering::Relaxed) + 15).min(100);
    COVERAGE.store(new_coverage, Ordering::Relaxed);
    BUS_FACTOR.fetch_add(1, Ordering::Relaxed);

    format!(
        r#"{{"status":"shared","new_owner":"{}","runbook":"{}","coverage":{new_coverage},"bus_factor":{},"note":"el conocimiento dejo de vivir solo en alice; bus factor sube."}}"#,
        escape(name),
        escape(runbook_key),
        BUS_FACTOR.load(Ordering::Relaxed)
    )
}

fn record(variant: &str, scenario: &str, result: &str, mttr: i64) {
    let mut incidents = INCIDENTS.lock().unwrap();
    incidents.insert(
        0,
        Incident {
            at: rfc3339_now(),
            variant: variant.into(),
            scenario: scenario.into(),
            result: result.into(),
            mttr_min: mttr,
        },
    );
    incidents.truncate(MAX_INCIDENTS);
}

fn incidents_json() -> String {
    let incidents = INCIDENTS.lock().unwrap();
    let rendered: Vec<String> = incidents
        .iter()
        .map(|i| {
            format!(
                r#"{{"at":"{}","variant":"{}","scenario":"{}","result":"{}","mttr_min":{}}}"#,
                escape(&i.at),
                escape(&i.variant),
                escape(&i.scenario),
                escape(&i.result),
                i.mttr_min
            )
        })
        .collect();
    format!(
        r#"{{"incidents":[{}],"max_kept":{MAX_INCIDENTS}}}"#,
        rendered.join(",")
    )
}

fn diagnostics() -> String {
    format!(
        r#"{{"stack":"{}","case":"{CASE_NAME}","legacy":{{"incidents":{},"crashed":{},"behavior":"unwrap() sobre Option::None; panic contenido con catch_unwind"}},"distributed":{{"incidents":{},"handled":{},"behavior":"Option + operador ?; match exhaustivo, sin panic posible"}},"knowledge":{{"coverage":{},"bus_factor":{}}}}}"#,
        stack(),
        LEGACY_INCIDENTS.load(Ordering::Relaxed),
        LEGACY_CRASHED.load(Ordering::Relaxed),
        DISTRIBUTED_INCIDENTS.load(Ordering::Relaxed),
        DISTRIBUTED_HANDLED.load(Ordering::Relaxed),
        COVERAGE.load(Ordering::Relaxed),
        BUS_FACTOR.load(Ordering::Relaxed)
    )
}

// ---------- helpers ----------

fn rand_between(base: i64, spread: i64) -> i64 {
    let prev = RNG_STATE.load(Ordering::Relaxed);
    let next = (prev * 9301 + 49297) % 233280;
    RNG_STATE.store(next, Ordering::Relaxed);
    base + (next % spread)
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

fn escape(v: &str) -> String {
    v.replace('\\', "\\\\").replace('"', "\\\"")
}
