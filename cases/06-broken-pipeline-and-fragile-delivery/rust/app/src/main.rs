// Caso 06 — Pipeline roto y entrega fragil (stack Rust 1.83).
//
// Legacy: deploy directo sin preflight, sin smoke, sin rollback.
// Controlled: preflight → deploy → smoke → promote | rollback.
//
// El contraste que este stack aporta:
//
//   Los resultados posibles del pipeline se modelan con un `enum` con datos
//   asociados, no con strings sueltos:
//
//       enum DeployOutcome {
//           Deployed,
//           DeployedButBroken,
//           BlockedInPreflight { current_version: String },
//           RolledBack { to_version: String },
//           Promoted,
//       }
//
//   Y el `match` sobre ese enum es **exhaustivo**: si mañana alguien agrega
//   una variante `Canary`, todos los `match` que no la contemplen dejan de
//   compilar. En Java, .NET, Go o Node el resultado es un string y agregar un
//   estado nuevo no rompe nada — simplemente cae al `else` de algun `if` y se
//   comporta como si fuera otra cosa.
//
//   Para una maquina de estados de deploy, esa diferencia no es estetica: el
//   estado no contemplado es exactamente el que deja produccion a medio camino.

use std::collections::HashMap;
use std::io::{BufRead, BufReader, Write};
use std::net::{TcpListener, TcpStream};
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::Mutex;
use std::thread;
use std::time::{SystemTime, UNIX_EPOCH};

const CASE_NAME: &str = "06 - Pipeline roto y delivery fragil";
const MAX_DEPLOYMENTS: usize = 30;

static LEGACY_DEPLOYS: AtomicI64 = AtomicI64::new(0);
static LEGACY_BROKEN: AtomicI64 = AtomicI64::new(0);
static CONTROLLED_DEPLOYS: AtomicI64 = AtomicI64::new(0);
static CONTROLLED_ROLLBACKS: AtomicI64 = AtomicI64::new(0);
static CONTROLLED_BLOCKED: AtomicI64 = AtomicI64::new(0);

#[derive(Clone)]
struct EnvState {
    name: String,
    version: String,
    health: String,
}

#[derive(Clone)]
struct Deployment {
    at: String,
    variant: String,
    env: String,
    version: String,
    scenario: String,
    result: String,
}

/// Resultados posibles del pipeline. El match sobre este enum es exhaustivo:
/// agregar una variante rompe la compilacion de quien no la contemple.
enum DeployOutcome {
    Deployed,
    DeployedButBroken,
    BlockedInPreflight { current_version: String },
    RolledBack { to_version: String },
    Promoted,
}

impl DeployOutcome {
    fn label(&self) -> String {
        match self {
            DeployOutcome::Deployed => "deployed".to_string(),
            DeployOutcome::DeployedButBroken => "deployed_but_broken".to_string(),
            DeployOutcome::BlockedInPreflight { .. } => "blocked_in_preflight".to_string(),
            DeployOutcome::RolledBack { to_version } => format!("rolled_back_to_{to_version}"),
            DeployOutcome::Promoted => "promoted".to_string(),
        }
    }
}

struct State {
    environs: Vec<EnvState>,
    deployments: Vec<Deployment>,
}

static STATE: Mutex<Option<State>> = Mutex::new(None);

fn stack() -> String {
    std::env::var("APP_STACK").unwrap_or_else(|_| "Rust 1.83".to_string())
}

fn fresh_state() -> State {
    State {
        environs: vec![
            EnvState { name: "staging".into(), version: "v1.0.0".into(), health: "healthy".into() },
            EnvState { name: "prod".into(), version: "v1.0.0".into(), health: "healthy".into() },
        ],
        deployments: Vec::new(),
    }
}

fn main() {
    *STATE.lock().unwrap() = Some(fresh_state());

    let port: u16 = std::env::var("PORT").ok().and_then(|p| p.parse().ok()).unwrap_or(8080);
    let listener = TcpListener::bind(("0.0.0.0", port)).expect("bind");
    println!("[case06-rust] listening on {port}");
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
        out.insert(url_decode(k), url_decode(v));
    }
    out
}

fn url_decode(s: &str) -> String {
    s.replace('+', " ")
}

// ---------- routing ----------

fn route(path: &str, params: &HashMap<String, String>) -> (u16, String) {
    let env = params.get("env").cloned().unwrap_or_else(|| "prod".into());
    let version = params.get("version").cloned().unwrap_or_else(|| "v1.1.0".into());
    let scenario = params.get("scenario").cloned().unwrap_or_else(|| "clean".into());

    match path {
        "/" | "/index" => (
            200,
            format!(
                r#"{{"case":"{CASE_NAME}","stack":"{}","routes":["/health","/deploy-legacy?env=prod&version=v1.1.0&scenario=secret_drift","/deploy-controlled?env=prod&version=v1.1.0&scenario=secret_drift","/environments","/deployments","/diagnostics/summary","/reset-lab"]}}"#,
                stack()
            ),
        ),
        "/health" => (
            200,
            format!(r#"{{"status":"ok","stack":"{}","case":"{CASE_NAME}"}}"#, stack()),
        ),
        "/deploy-legacy" => (200, deploy_legacy(&env, &version, &scenario)),
        "/deploy-controlled" => (200, deploy_controlled(&env, &version, &scenario)),
        "/environments" => (200, environments_json()),
        "/deployments" => (200, deployments_json()),
        "/diagnostics/summary" => (200, diagnostics()),
        "/reset-lab" => {
            *STATE.lock().unwrap() = Some(fresh_state());
            LEGACY_DEPLOYS.store(0, Ordering::Relaxed);
            LEGACY_BROKEN.store(0, Ordering::Relaxed);
            CONTROLLED_DEPLOYS.store(0, Ordering::Relaxed);
            CONTROLLED_ROLLBACKS.store(0, Ordering::Relaxed);
            CONTROLLED_BLOCKED.store(0, Ordering::Relaxed);
            (200, r#"{"status":"reset"}"#.to_string())
        }
        _ => (
            404,
            format!(r#"{{"error":"not_found","path":"{}"}}"#, escape(path)),
        ),
    }
}

// ---------- endpoints ----------

fn is_bad_scenario(s: &str) -> bool {
    s == "secret_drift" || s == "breaking_change" || s == "schema_mismatch"
}

/// Legacy: aplica la version sin preflight y deja el ambiente como quede.
fn deploy_legacy(env: &str, version: &str, scenario: &str) -> String {
    LEGACY_DEPLOYS.fetch_add(1, Ordering::Relaxed);

    let outcome = if is_bad_scenario(scenario) {
        LEGACY_BROKEN.fetch_add(1, Ordering::Relaxed);
        DeployOutcome::DeployedButBroken
    } else {
        DeployOutcome::Deployed
    };
    let health = match outcome {
        DeployOutcome::DeployedButBroken => "degraded",
        _ => "healthy",
    };

    let mut guard = STATE.lock().unwrap();
    let state = guard.as_mut().expect("state");
    if let Some(e) = state.environs.iter_mut().find(|e| e.name == env) {
        e.version = version.to_string();
        e.health = health.to_string();
    }
    record(state, "legacy", env, version, scenario, &outcome.label());
    drop(guard);

    format!(
        r#"{{"variant":"legacy","env":"{}","version":"{}","scenario":"{}","result":"{}","health":"{health}","note":"sin preflight ni rollback; ambiente queda como quede."}}"#,
        escape(env),
        escape(version),
        escape(scenario),
        outcome.label()
    )
}

/// Controlled: toda la secuencia bajo un solo lock — leer version, decidir y
/// escribir es una sola transaccion logica.
fn deploy_controlled(env: &str, version: &str, scenario: &str) -> String {
    CONTROLLED_DEPLOYS.fetch_add(1, Ordering::Relaxed);

    let mut guard = STATE.lock().unwrap();
    let state = guard.as_mut().expect("state");
    let before = state
        .environs
        .iter()
        .find(|e| e.name == env)
        .map(|e| e.version.clone())
        .unwrap_or_else(|| "unknown".into());

    let outcome = if scenario == "missing_artifact" || scenario == "secret_drift_detected" {
        CONTROLLED_BLOCKED.fetch_add(1, Ordering::Relaxed);
        DeployOutcome::BlockedInPreflight { current_version: before.clone() }
    } else if is_bad_scenario(scenario) {
        CONTROLLED_ROLLBACKS.fetch_add(1, Ordering::Relaxed);
        DeployOutcome::RolledBack { to_version: before.clone() }
    } else {
        if let Some(e) = state.environs.iter_mut().find(|e| e.name == env) {
            e.version = version.to_string();
            e.health = "healthy".to_string();
        }
        DeployOutcome::Promoted
    };

    record(state, "controlled", env, version, scenario, &outcome.label());
    drop(guard);

    // match exhaustivo: agregar una variante al enum rompe aca la compilacion.
    let body = match &outcome {
        DeployOutcome::BlockedInPreflight { current_version } => format!(
            r#""result":"blocked_in_preflight","current_version":"{}","note":"preflight bloqueo antes de tocar el ambiente.""#,
            escape(current_version)
        ),
        DeployOutcome::RolledBack { to_version } => format!(
            r#""result":"rolled_back","current_version":"{}","note":"smoke fallo, rollback automatico a la version anterior.""#,
            escape(to_version)
        ),
        DeployOutcome::Promoted => {
            r#""result":"promoted","health":"healthy","note":"preflight ok + smoke ok → promote.""#.to_string()
        }
        DeployOutcome::Deployed | DeployOutcome::DeployedButBroken => {
            r#""result":"unexpected","note":"variante legacy en ruta controlada.""#.to_string()
        }
    };

    format!(
        r#"{{"variant":"controlled","env":"{}","version":"{}","scenario":"{}",{body}}}"#,
        escape(env),
        escape(version),
        escape(scenario)
    )
}

fn record(state: &mut State, variant: &str, env: &str, version: &str, scenario: &str, result: &str) {
    state.deployments.insert(
        0,
        Deployment {
            at: rfc3339_now(),
            variant: variant.into(),
            env: env.into(),
            version: version.into(),
            scenario: scenario.into(),
            result: result.into(),
        },
    );
    state.deployments.truncate(MAX_DEPLOYMENTS);
}

fn environments_json() -> String {
    let guard = STATE.lock().unwrap();
    let state = guard.as_ref().expect("state");
    let envs: Vec<String> = state
        .environs
        .iter()
        .map(|e| {
            format!(
                r#"{{"name":"{}","version":"{}","health":"{}"}}"#,
                escape(&e.name),
                escape(&e.version),
                escape(&e.health)
            )
        })
        .collect();
    format!(r#"{{"envs":[{}]}}"#, envs.join(","))
}

fn deployments_json() -> String {
    let guard = STATE.lock().unwrap();
    let state = guard.as_ref().expect("state");
    let history: Vec<String> = state
        .deployments
        .iter()
        .map(|d| {
            format!(
                r#"{{"at":"{}","variant":"{}","env":"{}","version":"{}","scenario":"{}","result":"{}"}}"#,
                escape(&d.at),
                escape(&d.variant),
                escape(&d.env),
                escape(&d.version),
                escape(&d.scenario),
                escape(&d.result)
            )
        })
        .collect();
    format!(
        r#"{{"history":[{}],"max_kept":{MAX_DEPLOYMENTS}}}"#,
        history.join(",")
    )
}

fn diagnostics() -> String {
    format!(
        r#"{{"stack":"{}","case":"{CASE_NAME}","legacy":{{"deploys":{},"broken_state_left":{},"behavior":"sin preflight, sin rollback"}},"controlled":{{"deploys":{},"blocked_in_preflight":{},"rollbacks":{},"behavior":"preflight + smoke + rollback automatico"}},"environments":{}}}"#,
        stack(),
        LEGACY_DEPLOYS.load(Ordering::Relaxed),
        LEGACY_BROKEN.load(Ordering::Relaxed),
        CONTROLLED_DEPLOYS.load(Ordering::Relaxed),
        CONTROLLED_BLOCKED.load(Ordering::Relaxed),
        CONTROLLED_ROLLBACKS.load(Ordering::Relaxed),
        environments_json()
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

fn escape(v: &str) -> String {
    v.replace('\\', "\\\\").replace('"', "\\\"")
}
