// Rust Lab Dispatcher — un solo contenedor, un solo puerto para los 12 casos.
//
// Espejo del patron java/dotnet/node/go-dispatcher:
//   - Spawnea cada caso como subproceso interno (/app/cases/0X/case0X).
//   - Escucha publico en :8700.
//   - Enruta por prefijo de path: /01/* → :9701, ..., /12/* → :9712.
//   - Los puertos internos nunca se exponen al host.
//
// Diferencia respecto de los otros dispatchers: Go usa
// `httputil.ReverseProxy` de su stdlib; Java y .NET copian cabeceras a mano
// sobre un cliente HTTP que su biblioteca estandar si trae. Rust no tiene
// cliente HTTP en `std`, asi que el proxy trabaja a nivel TCP: reescribe la
// linea de request y hace `io::copy` en ambas direcciones. Es menos codigo que
// parsear HTTP completo, y suficiente para el contrato del lab.

use std::io::{BufRead, BufReader, Read, Write};
use std::net::{TcpListener, TcpStream};
use std::process::{Command, Stdio};
use std::thread;
use std::time::{Duration, Instant};

struct CaseInfo {
    id: &'static str,
    port: u16,
    name: &'static str,
    binary: &'static str,
}

const CASES: [CaseInfo; 20] = [
    CaseInfo { id: "01", port: 9701, name: "API lenta bajo carga",               binary: "/app/cases/01/case01" },
    CaseInfo { id: "02", port: 9702, name: "N+1 y cuellos de botella DB",        binary: "/app/cases/02/case02" },
    CaseInfo { id: "03", port: 9703, name: "Observabilidad deficiente",          binary: "/app/cases/03/case03" },
    CaseInfo { id: "04", port: 9704, name: "Timeout chain y retry storms",       binary: "/app/cases/04/case04" },
    CaseInfo { id: "05", port: 9705, name: "Presion de memoria y fugas",         binary: "/app/cases/05/case05" },
    CaseInfo { id: "06", port: 9706, name: "Pipeline roto y delivery fragil",    binary: "/app/cases/06/case06" },
    CaseInfo { id: "07", port: 9707, name: "Modernizacion incremental monolito", binary: "/app/cases/07/case07" },
    CaseInfo { id: "08", port: 9708, name: "Extraccion critica de modulo",       binary: "/app/cases/08/case08" },
    CaseInfo { id: "09", port: 9709, name: "Integracion externa inestable",      binary: "/app/cases/09/case09" },
    CaseInfo { id: "10", port: 9710, name: "Arquitectura cara para algo simple", binary: "/app/cases/10/case10" },
    CaseInfo { id: "11", port: 9711, name: "Reportes que bloquean operacion",    binary: "/app/cases/11/case11" },
    CaseInfo { id: "12", port: 9712, name: "Punto unico de conocimiento",        binary: "/app/cases/12/case12" },
    CaseInfo { id: "13", port: 9713, name: "Cache stampede y thundering herd",  binary: "/app/cases/13/case13" },
    CaseInfo { id: "14", port: 9714, name: "Agotamiento del pool de conexiones",  binary: "/app/cases/14/case14" },
    CaseInfo { id: "15", port: 9715, name: "Backpressure en colas de mensajes",  binary: "/app/cases/15/case15" },
    CaseInfo { id: "16", port: 9716, name: "Idempotencia y efectos duplicados",  binary: "/app/cases/16/case16" },
    CaseInfo { id: "17", port: 9717, name: "Migracion de esquema sin downtime",  binary: "/app/cases/17/case17" },
    CaseInfo { id: "18", port: 9718, name: "Arranque en frio y retraso del autoescalado",  binary: "/app/cases/18/case18" },
    CaseInfo { id: "19", port: 9719, name: "Deriva del indice de busqueda y CDC roto",  binary: "/app/cases/19/case19" },
    CaseInfo { id: "20", port: 9720, name: "La dead letter queue olvidada",  binary: "/app/cases/20/case20" },
];

fn stack() -> String {
    std::env::var("APP_STACK").unwrap_or_else(|_| "Rust 1.83".to_string())
}

fn main() {
    println!("[rust-dispatcher] starting {} case servers...", CASES.len());
    for c in CASES.iter() {
        spawn_case(c);
    }
    for c in CASES.iter() {
        wait_healthy(c, Duration::from_secs(30));
    }

    let port: u16 = std::env::var("PORT").ok().and_then(|p| p.parse().ok()).unwrap_or(8700);
    let listener = TcpListener::bind(("0.0.0.0", port)).expect("bind dispatcher");
    println!("[rust-dispatcher] listening on {port}");

    for stream in listener.incoming().flatten() {
        thread::spawn(move || handle_conn(stream));
    }
}

fn spawn_case(c: &CaseInfo) {
    match Command::new(c.binary)
        .env("PORT", c.port.to_string())
        .env("APP_STACK", stack())
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .spawn()
    {
        Ok(child) => println!("  case {} -> interno :{} (pid {})", c.id, c.port, child.id()),
        Err(e) => eprintln!("[rust-dispatcher] no se pudo spawnear caso {}: {e}", c.id),
    }
}

fn wait_healthy(c: &CaseInfo, timeout: Duration) {
    let deadline = Instant::now() + timeout;
    while Instant::now() < deadline {
        if probe_health(c.port) {
            println!("  case {} healthy", c.id);
            return;
        }
        thread::sleep(Duration::from_millis(300));
    }
    eprintln!("[rust-dispatcher] case {} no respondio health en {timeout:?}", c.id);
}

fn probe_health(port: u16) -> bool {
    let mut stream = match TcpStream::connect(("127.0.0.1", port)) {
        Ok(s) => s,
        Err(_) => return false,
    };
    let _ = stream.set_read_timeout(Some(Duration::from_millis(800)));
    if stream
        .write_all(b"GET /health HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n")
        .is_err()
    {
        return false;
    }
    let mut buf = [0u8; 64];
    match stream.read(&mut buf) {
        Ok(n) if n > 12 => buf.starts_with(b"HTTP/1.1 200"),
        _ => false,
    }
}

// ---------- proxy ----------

fn handle_conn(mut client: TcpStream) {
    let mut reader = BufReader::new(match client.try_clone() {
        Ok(s) => s,
        Err(_) => return,
    });

    let mut request_line = String::new();
    if reader.read_line(&mut request_line).is_err() || request_line.trim().is_empty() {
        return;
    }

    let mut headers = Vec::new();
    let mut content_length = 0usize;
    loop {
        let mut line = String::new();
        match reader.read_line(&mut line) {
            Ok(0) => break,
            Ok(_) if line.trim().is_empty() => break,
            Ok(_) => {
                let lower = line.to_ascii_lowercase();
                if let Some(rest) = lower.strip_prefix("content-length:") {
                    content_length = rest.trim().parse().unwrap_or(0);
                }
                headers.push(line);
            }
            Err(_) => break,
        }
    }

    let mut parts = request_line.split_whitespace();
    let method = parts.next().unwrap_or("GET").to_string();
    let target = parts.next().unwrap_or("/").to_string();
    let version = parts.next().unwrap_or("HTTP/1.1").to_string();

    let (path, query) = target.split_once('?').unwrap_or((target.as_str(), ""));

    if path == "/" || path == "/index" || path == "/index.html" {
        respond(&mut client, 200, &index_json());
        return;
    }
    if path == "/health" {
        respond(
            &mut client,
            200,
            &format!(r#"{{"status":"ok","stack":"{}","role":"dispatcher"}}"#, stack()),
        );
        return;
    }

    if path.len() < 3 {
        respond(
            &mut client,
            404,
            r#"{"error":"not_found","hint":"usa /01/..., /02/..., ..., /12/..."}"#,
        );
        return;
    }
    let case_id = &path[1..3];
    let target_case = match CASES.iter().find(|c| c.id == case_id) {
        Some(c) => c,
        None => {
            respond(
                &mut client,
                404,
                &format!(r#"{{"error":"case_not_found","case":"{case_id}"}}"#),
            );
            return;
        }
    };

    // Reescribir el path quitando el prefijo /0X.
    let remainder = if path.len() > 3 { &path[3..] } else { "/" };
    let upstream_target = if query.is_empty() {
        remainder.to_string()
    } else {
        format!("{remainder}?{query}")
    };

    let mut upstream = match TcpStream::connect(("127.0.0.1", target_case.port)) {
        Ok(s) => s,
        Err(e) => {
            respond(
                &mut client,
                502,
                &format!(
                    r#"{{"error":"upstream_unavailable","case":"{case_id}","detail":"{}"}}"#,
                    e.to_string().replace('"', "'")
                ),
            );
            return;
        }
    };

    let mut out = format!("{method} {upstream_target} {version}\r\n");
    for h in &headers {
        // Forzar cierre para no dejar la conexion upstream colgada.
        if h.to_ascii_lowercase().starts_with("connection:") {
            continue;
        }
        out.push_str(h);
    }
    out.push_str("Connection: close\r\n\r\n");

    if upstream.write_all(out.as_bytes()).is_err() {
        return;
    }
    if content_length > 0 {
        let mut body = vec![0u8; content_length];
        if reader.read_exact(&mut body).is_ok() {
            let _ = upstream.write_all(&body);
        }
    }
    let _ = upstream.flush();

    // Copiar la respuesta tal cual: sin reinterpretar cabeceras ni cuerpo.
    let _ = std::io::copy(&mut upstream, &mut client);
    let _ = client.flush();
}

fn respond(client: &mut TcpStream, status: u16, body: &str) {
    let reason = match status {
        200 => "OK",
        404 => "Not Found",
        502 => "Bad Gateway",
        _ => "OK",
    };
    let response = format!(
        "HTTP/1.1 {status} {reason}\r\nContent-Type: application/json; charset=utf-8\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{body}",
        body.as_bytes().len()
    );
    let _ = client.write_all(response.as_bytes());
    let _ = client.flush();
}

fn index_json() -> String {
    let cases: Vec<String> = CASES
        .iter()
        .map(|c| {
            format!(
                r#""{}":{{"name":"{}","health":"/{}/health","index":"/{}/","internal_port":{}}}"#,
                c.id, c.name, c.id, c.id, c.port
            )
        })
        .collect();
    format!(
        r#"{{"lab":"Problem-Driven Systems Lab","stack":"{}","role":"dispatcher","usage":"GET /{{caso}}/{{ruta}}  ->  e.g. /01/health, /04/quote-resilient","cases":{{{}}}}}"#,
        stack(),
        cases.join(",")
    )
}
