// Caso 10 — Arquitectura cara para un problema simple — stack Rust 1.83.
//
// Complex: N hops simulados con serializacion costosa en cada uno, alto CPU.
// Right-sized: lookup directo en un HashMap, O(1), CPU minimo.
//
// El contraste que este stack aporta:
//
//   El costo aca es CPU puro: construir y recorrer buffers. Rust lo hace con
//   `String::with_capacity` + `push_str`, sin asignaciones intermedias
//   ocultas y sin GC que despues tenga que recoger la basura generada.
//
//   Eso hace que el numero absoluto de Rust salga el mas bajo de los siete
//   stacks. Y por eso mismo vale repetir lo que dice el caso en todos los
//   lenguajes: **comparar milisegundos entre stacks aca no dice nada util**.
//
//   Lo comparable es la FORMA DE LA CURVA dentro de cada stack — lineal en
//   `hops` para la variante compleja, constante para la right-sized. Esa
//   pendiente es identica en los siete lenguajes, porque la sobrearquitectura
//   no es un problema de runtime: es un problema de diseño. Un lenguaje rapido
//   no arregla ocho saltos de red que no hacian falta, solo hace que tarden
//   menos en no hacer falta.

use std::collections::HashMap;
use std::io::{BufRead, BufReader, Write};
use std::net::{TcpListener, TcpStream};
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::LazyLock;
use std::thread;
use std::time::Instant;

const CASE_NAME: &str = "10 - Arquitectura cara para algo simple";

static COMPLEX_CALLS: AtomicI64 = AtomicI64::new(0);
static COMPLEX_TIMEOUTS: AtomicI64 = AtomicI64::new(0);
static RIGHT_SIZED_CALLS: AtomicI64 = AtomicI64::new(0);

/// El "right-sized": un mapa y nada mas. Se construye una vez y solo se lee,
/// asi que no necesita lock — `LazyLock<HashMap>` es inmutable tras la init.
static DIRECT_STORE: LazyLock<HashMap<String, i64>> = LazyLock::new(|| {
    let mut m = HashMap::with_capacity(100);
    for i in 1..=100i64 {
        m.insert(format!("feature-{i}"), i * 10);
    }
    m
});

const DECISIONS: [&str; 2] = [
    "ADR-001: empezar con monolito + HashMap; revisitar si pasa de 10k QPS sostenido",
    "ADR-002: posponer queue distribuida hasta que el modelo de datos lo requiera",
];

fn stack() -> String {
    std::env::var("APP_STACK").unwrap_or_else(|_| "Rust 1.83".to_string())
}

fn main() {
    let port: u16 = std::env::var("PORT").ok().and_then(|p| p.parse().ok()).unwrap_or(8080);
    let listener = TcpListener::bind(("0.0.0.0", port)).expect("bind");
    println!("[case10-rust] listening on {port}");
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
    let key = params.get("key").cloned().unwrap_or_else(|| "feature-1".into());
    let hops = bounded(params.get("hops").map(String::as_str), 8, 1, 50);

    match path {
        "/" | "/index" => (
            200,
            format!(
                r#"{{"case":"{CASE_NAME}","stack":"{}","routes":["/health","/feature-complex?key=feature-1&hops=8","/feature-right-sized?key=feature-1","/decisions","/diagnostics/summary","/reset-lab"]}}"#,
                stack()
            ),
        ),
        "/health" => (
            200,
            format!(r#"{{"status":"ok","stack":"{}","case":"{CASE_NAME}"}}"#, stack()),
        ),
        "/feature-complex" => {
            let body = feature_complex(&key, hops);
            COMPLEX_CALLS.fetch_add(1, Ordering::Relaxed);
            (200, body)
        }
        "/feature-right-sized" => {
            let body = feature_right_sized(&key);
            RIGHT_SIZED_CALLS.fetch_add(1, Ordering::Relaxed);
            (200, body)
        }
        "/decisions" => {
            let rendered: Vec<String> = DECISIONS.iter().map(|d| format!(r#""{}""#, escape(d))).collect();
            (200, format!(r#"{{"decisions":[{}]}}"#, rendered.join(",")))
        }
        "/diagnostics/summary" => (200, diagnostics()),
        "/reset-lab" => {
            COMPLEX_CALLS.store(0, Ordering::Relaxed);
            COMPLEX_TIMEOUTS.store(0, Ordering::Relaxed);
            RIGHT_SIZED_CALLS.store(0, Ordering::Relaxed);
            (200, r#"{"status":"reset"}"#.to_string())
        }
        _ => (
            404,
            format!(r#"{{"error":"not_found","path":"{}"}}"#, escape(path)),
        ),
    }
}

// ---------- endpoints ----------

/// Complex: el payload "viaja" por N servicios y cada uno lo serializa. El
/// costo crece linealmente con hops — esa pendiente es el sintoma.
fn feature_complex(key: &str, hops: i64) -> String {
    let start = Instant::now();

    let mut payload = String::with_capacity(2048 * hops as usize);
    payload.push_str(r#"{"key":""#);
    payload.push_str(key);
    payload.push_str(r#"","trace":["#);
    for h in 0..hops {
        let mut hop = String::with_capacity(2048);
        hop.push_str(r#""hop-"#);
        hop.push_str(&h.to_string());
        hop.push('-');
        for i in 0..200 {
            hop.push((b'A' + (i % 26)) as char);
        }
        hop.push('"');
        payload.push_str(&hop);
        if h < hops - 1 {
            payload.push(',');
        }
    }
    payload.push_str(r#"],"final_lookup":"#);
    let value = DIRECT_STORE.get(key).copied();
    match value {
        Some(v) => payload.push_str(&v.to_string()),
        None => payload.push_str("null"),
    }
    payload.push('}');

    let elapsed_ms = start.elapsed().as_millis() as i64;

    if hops > 20 {
        COMPLEX_TIMEOUTS.fetch_add(1, Ordering::Relaxed);
        return format!(
            r#"{{"variant":"complex","status":"internal_timeout","hops":{hops},"elapsed_ms":{elapsed_ms},"services_touched":{hops},"cost_usd_month_est":{},"lead_time_days":{},"note":"sobrearquitectura: muchos hops, timeout interno bajo seasonal_peak."}}"#,
            hops * 25,
            hops * 2
        );
    }

    format!(
        r#"{{"variant":"complex","key":"{}","hops":{hops},"elapsed_ms":{elapsed_ms},"services_touched":{hops},"cost_usd_month_est":{},"lead_time_days":{},"value":{},"payload_bytes":{},"note":"N hops con serializacion en cada uno; CPU real medido."}}"#,
        escape(key),
        hops * 25,
        hops * 2,
        nullable(value),
        payload.len()
    )
}

/// Right-sized: un lookup. Constante en el tamaño del problema.
fn feature_right_sized(key: &str) -> String {
    let start = Instant::now();
    let value = DIRECT_STORE.get(key).copied();
    let elapsed_ms = start.elapsed().as_millis() as i64;
    format!(
        r#"{{"variant":"right_sized","key":"{}","elapsed_ms":{elapsed_ms},"services_touched":1,"cost_usd_month_est":3,"lead_time_days":1,"value":{},"note":"HashMap O(1); proporcional al problema real."}}"#,
        escape(key),
        nullable(value)
    )
}

fn diagnostics() -> String {
    let rendered: Vec<String> = DECISIONS.iter().map(|d| format!(r#""{}""#, escape(d))).collect();
    format!(
        r#"{{"stack":"{}","case":"{CASE_NAME}","complex":{{"calls":{},"timeouts":{},"behavior":"N hops con serializacion por hop; costo lineal en hops"}},"right_sized":{{"calls":{},"behavior":"HashMap lookup O(1); costo constante"}},"decisions":[{}]}}"#,
        stack(),
        COMPLEX_CALLS.load(Ordering::Relaxed),
        COMPLEX_TIMEOUTS.load(Ordering::Relaxed),
        RIGHT_SIZED_CALLS.load(Ordering::Relaxed),
        rendered.join(",")
    )
}

// ---------- helpers ----------

fn nullable(v: Option<i64>) -> String {
    match v {
        Some(n) => n.to_string(),
        None => "null".to_string(),
    }
}

fn bounded(raw: Option<&str>, dflt: i64, min: i64, max: i64) -> i64 {
    raw.and_then(|r| r.parse::<i64>().ok()).unwrap_or(dflt).clamp(min, max)
}

fn escape(v: &str) -> String {
    v.replace('\\', "\\\\").replace('"', "\\\"")
}
