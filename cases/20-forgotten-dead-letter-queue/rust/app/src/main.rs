//! Caso 20 — La dead letter queue olvidada — stack Rust 1.83.
//!
//! Cierra el arco que abrio el caso 15: alli la DLQ **nace**, como la politica de
//! rechazo que salva al productor de bloquearse. Aca se ve que pasa cuando nadie
//! vuelve a mirarla.
//!
//! Silencioso: el consumidor falla, manda el mensaje a la DLQ y sigue. Sin
//! clasificar, sin reintentar, sin medir, sin alerta. La cola crece durante meses
//! y el pipeline se ve sano: throughput normal, cero errores — porque los errores
//! se fueron a otro lado.
//!
//! Observado: el error se **clasifica** antes de decidir. Lo transitorio se
//! reintenta y casi todo se recupera; lo venenoso va a la DLQ con su clase y una
//! muestra del payload; la profundidad y la antiguedad se publican; hay umbral.
//!
//! La distincion que ordena el caso:
//!
//! ```text
//! transitorio  — el mismo mensaje funciona en el proximo intento
//! venenoso     — el mismo mensaje NUNCA va a funcionar
//! ```
//!
//! Reintentar lo venenoso es quemar CPU. Mandar lo transitorio a la DLQ es tirar
//! trabajo que se podia salvar. El consumidor que no distingue hace las dos mal.
//!
//! # Primitiva Rust distintiva
//!
//! **El `enum` de error con `match` exhaustivo.** En un caso cuyo nucleo es
//! *clasificar*, esa es la primitiva exacta:
//!
//! ```ignore
//! enum ErrorProceso {
//!     Transitorio(&'static str),
//!     Venenoso(ClaseVeneno),
//! }
//!
//! match procesar(msg) {
//!     Ok(()) => ok += 1,
//!     Err(ErrorProceso::Transitorio(_)) => reintentar(),
//!     Err(ErrorProceso::Venenoso(c))    => a_dlq(msg, c),
//! }
//! ```
//!
//! Lo decisivo no es la elegancia: es que **agregar una variante rompe la
//! compilacion en todos los lugares que la ignoran**. Si mañana aparece
//! `ErrorProceso::Corrupto`, el consumidor no compila hasta que alguien decida
//! si eso se reintenta o va a la DLQ.
//!
//! En los otros seis stacks una clase de error nueva cae en el `else`, en el
//! `catch (Exception)` o en el camino por defecto, y termina en la DLQ como
//! `unclassified`. Go se acerca con `errors.Is`/`As` pero no tiene
//! exhaustividad; Java se acerca con jerarquias `sealed` y necesita un `switch`
//! sobre patrones para exigirla.
//!
//! Y hay un segundo efecto, mas silencioso: **un `panic!` no es un `Result`**.
//! Un bug del propio consumidor —un indice fuera de rango, un `unwrap` sobre
//! `None`— no puede confundirse con un mensaje venenoso, porque no viaja por el
//! mismo canal. En Python, Java, .NET y Node el `except`/`catch` generico se
//! traga las dos cosas y las deja indistinguibles en la DLQ.

use std::collections::{BTreeMap, HashMap};
use std::io::{BufRead, BufReader, Write};
use std::net::{TcpListener, TcpStream};
use std::sync::{Mutex, OnceLock};
use std::thread;
use std::time::{Instant, SystemTime, UNIX_EPOCH};

const CASE_NAME: &str = "20 - La dead letter queue olvidada";
const POISON_CLASSES: [&str; 4] = ["schema_mismatch", "unknown_field", "null_required", "invalid_encoding"];

fn start() -> &'static Instant {
    static START: OnceLock<Instant> = OnceLock::new();
    START.get_or_init(Instant::now)
}

fn now_ms() -> f64 {
    start().elapsed().as_secs_f64() * 1000.0
}

/// La clasificacion, como tipo. Agregar una variante rompe la compilacion en
/// todos los `match` que no la manejen — que es exactamente lo que este caso
/// necesita.
enum ErrorProceso {
    Transitorio(&'static str),
    Venenoso(&'static str),
}

struct Dead {
    id: String,
    error_class: String,
    attempts: u32,
    first_seen_ms: f64,
    sample: Option<(usize, String)>,
}

#[derive(Default, Clone)]
struct Slot {
    runs: u64,
    consumed: u64,
    succeeded: u64,
    retried: u64,
    dead_lettered: u64,
    alerts_fired: u64,
}

struct Lab {
    dlq: Vec<Dead>,
    alerts_fired: u64,
    metrics: HashMap<String, Slot>,
}

impl Lab {
    fn new() -> Self {
        let mut metrics = HashMap::new();
        metrics.insert("silent".to_string(), Slot::default());
        metrics.insert("observed".to_string(), Slot::default());
        Lab { dlq: Vec::new(), alerts_fired: 0, metrics }
    }
}

fn lab() -> &'static Mutex<Lab> {
    static LAB: OnceLock<Mutex<Lab>> = OnceLock::new();
    LAB.get_or_init(|| Mutex::new(Lab::new()))
}

fn round2(v: f64) -> f64 {
    (v * 100.0).round() / 100.0
}

/// Procesa un mensaje. El transitorio falla solo en el primer intento: es la
/// definicion de transitorio, y es lo que hace que reintentarlo tenga sentido.
fn procesar(idx: usize, transient_pct: u32, poison_pct: u32, attempt: u32) -> Result<(), ErrorProceso> {
    if ((idx * 53) % 101) < poison_pct as usize {
        return Err(ErrorProceso::Venenoso(POISON_CLASSES[idx % POISON_CLASSES.len()]));
    }
    if ((idx * 37) % 101) < transient_pct as usize && attempt == 0 {
        return Err(ErrorProceso::Transitorio("timeout del downstream"));
    }
    Ok(())
}

// ---------------------------------------------------------------------------
// Variante silenciosa: cualquier fallo va a la DLQ, y nadie vuelve
// ---------------------------------------------------------------------------

fn consume_silent(messages: usize, transient_pct: u32, poison_pct: u32) -> (u64, u64, u64, u64, f64) {
    let mut l = lab().lock().unwrap();
    l.dlq.clear();
    l.alerts_fired = 0;

    let (mut consumed, mut succeeded, mut dead_count) = (0u64, 0u64, 0u64);
    let t0 = now_ms();

    for i in 0..messages {
        consumed += 1;
        // El bug entero. `is_err()` y nada mas: no mira QUE variante es, no
        // reintenta, y no guarda por que fallo. Rust no lo impide — pero para
        // escribirlo hay que ignorar a proposito el `match`.
        if procesar(i, transient_pct, poison_pct, 0).is_err() {
            l.dlq.push(Dead {
                id: format!("msg-{}", i),
                error_class: "unclassified".to_string(),
                attempts: 1,
                first_seen_ms: now_ms(),
                sample: None,
            });
            dead_count += 1;
            continue;
        }
        succeeded += 1;
    }
    (consumed, succeeded, dead_count, 0, round2(now_ms() - t0))
}

// ---------------------------------------------------------------------------
// Variante observada: clasificar con match exhaustivo, reintentar, alertar
// ---------------------------------------------------------------------------

fn consume_observed(
    messages: usize,
    transient_pct: u32,
    poison_pct: u32,
    max_retries: u32,
    alert_threshold: usize,
    sample_size: usize,
) -> (u64, u64, u64, u64, u64, u64, f64) {
    let mut l = lab().lock().unwrap();
    l.dlq.clear();
    l.alerts_fired = 0;

    let (mut consumed, mut succeeded, mut retried, mut dead_count, mut sampled) = (0u64, 0u64, 0u64, 0u64, 0usize);
    let t0 = now_ms();

    for i in 0..messages {
        consumed += 1;
        for attempt in 0..=max_retries {
            // EL match EXHAUSTIVO. Si mañana aparece una variante nueva de
            // ErrorProceso, este bloque deja de compilar hasta que alguien
            // decida si se reintenta o va a la DLQ.
            match procesar(i, transient_pct, poison_pct, attempt) {
                Ok(()) => {
                    succeeded += 1;
                    break;
                }
                Err(ErrorProceso::Transitorio(_)) => {
                    // El proximo intento tiene otra suerte. Mandarlo a la DLQ
                    // seria tirar trabajo que se podia salvar.
                    retried += 1;
                    if attempt == max_retries {
                        l.dlq.push(Dead {
                            id: format!("msg-{}", i),
                            error_class: "transient_exhausted".to_string(),
                            attempts: attempt + 1,
                            first_seen_ms: now_ms(),
                            sample: None,
                        });
                        dead_count += 1;
                    }
                }
                Err(ErrorProceso::Venenoso(clase)) => {
                    // Reintentarlo es quemar CPU. Va a la DLQ ya mismo, con su
                    // clase y —para los primeros— una muestra del payload.
                    let muestra = if sampled < sample_size {
                        sampled += 1;
                        Some((i, format!("{{\"id\": {}, \"campo\": \"...\"}}", i)))
                    } else {
                        None
                    };
                    l.dlq.push(Dead {
                        id: format!("msg-{}", i),
                        error_class: clase.to_string(),
                        attempts: attempt + 1,
                        first_seen_ms: now_ms(),
                        sample: muestra,
                    });
                    dead_count += 1;
                    break;
                }
            }
        }
    }

    let mut alerts = 0u64;
    if l.dlq.len() > alert_threshold {
        l.alerts_fired += 1;
        alerts = 1;
    }

    (consumed, succeeded, retried, dead_count, alerts, sampled as u64, round2(now_ms() - t0))
}

// ---------------------------------------------------------------------------
// La DLQ como cola observable, no como agujero
// ---------------------------------------------------------------------------

fn dlq_stats_json(alert_threshold: usize) -> String {
    let l = lab().lock().unwrap();
    let mut por_clase: BTreeMap<&str, usize> = BTreeMap::new();
    for m in &l.dlq {
        *por_clase.entry(m.error_class.as_str()).or_insert(0) += 1;
    }
    let now = now_ms();
    let oldest = l.dlq.iter().map(|m| now - m.first_seen_ms).fold(0.0f64, f64::max);

    let clases = por_clase
        .iter()
        .map(|(k, v)| format!("\"{}\": {}", k, v))
        .collect::<Vec<_>>()
        .join(", ");
    let muestras = l
        .dlq
        .iter()
        .filter_map(|m| m.sample.as_ref())
        .take(5)
        .map(|(i, p)| format!("{{ \"idx\": {}, \"payload\": \"{}\" }}", i, p.replace('"', "\\\"")))
        .collect::<Vec<_>>()
        .join(", ");

    format!(
        "\"dlq_depth\": {},\n  \"dlq_oldest_msg_age_ms\": {},\n  \"by_error_class\": {{{}}},\n  \
         \"alert_threshold\": {},\n  \"over_threshold\": {},\n  \"alerts_fired\": {},\n  \"samples\": [{}]",
        l.dlq.len(),
        round2(oldest),
        clases,
        alert_threshold,
        l.dlq.len() > alert_threshold,
        l.alerts_fired,
        muestras
    )
}

/// Replay desde la DLQ. Lo que se recupera vuelve; lo venenoso sigue ahi.
/// Una DLQ que solo recibe es un cementerio; una de la que se puede volver es un
/// buffer.
fn dlq_drain(limit: usize, transient_pct: u32, poison_pct: u32, max_retries: u32) -> String {
    let t0 = now_ms();
    let mut l = lab().lock().unwrap();
    let n = limit.min(l.dlq.len());
    let resto: Vec<Dead> = l.dlq.drain(n..).collect();
    let lote: Vec<Dead> = l.dlq.drain(..).collect();

    let (mut ok, mut fallo) = (0u64, 0u64);
    let mut quedan: Vec<Dead> = Vec::new();

    for mut m in lote {
        let idx: usize = m.id.trim_start_matches("msg-").parse().unwrap_or(0);
        let mut recuperado = false;
        for attempt in 1..=max_retries {
            match procesar(idx, transient_pct, poison_pct, attempt) {
                Ok(()) => {
                    recuperado = true;
                    break;
                }
                Err(ErrorProceso::Transitorio(_)) => continue,
                Err(ErrorProceso::Venenoso(_)) => break,
            }
        }
        if recuperado {
            ok += 1;
        } else {
            fallo += 1;
            m.attempts += max_retries;
            quedan.push(m);
        }
    }

    quedan.extend(resto);
    l.dlq = quedan;
    let depth = l.dlq.len();
    drop(l);

    format!(
        "{{\n  \"drain_limit\": {},\n  \"drained_ok\": {},\n  \"drain_failed\": {},\n  \"recovered_pct\": {},\n  \
         \"drain_duration_ms\": {},\n  \"dlq_depth_after\": {},\n  \"note\": \"Lo que se recupera en el replay es \
         exactamente lo que nunca deberia haber estado aca: errores transitorios que un reintento habria resuelto. \
         Lo que sigue fallando es veneno de verdad, y necesita un cambio de codigo o de datos — no otro \
         reintento.\"",
        limit,
        ok,
        fallo,
        round2(ok as f64 * 100.0 / (ok + fallo).max(1) as f64),
        round2(now_ms() - t0),
        depth
    )
}

#[allow(clippy::too_many_arguments)]
fn run_scenario(
    variant: &str,
    messages: usize,
    transient_pct: u32,
    poison_pct: u32,
    max_retries: u32,
    alert_threshold: usize,
    sample_size: usize,
) -> String {
    let (consumed, succeeded, retried, dead_count, alerts, sampled, wall) = if variant == "silent" {
        let (c, s, d, a, w) = consume_silent(messages, transient_pct, poison_pct);
        (c, s, 0, d, a, 0, w)
    } else {
        consume_observed(messages, transient_pct, poison_pct, max_retries, alert_threshold, sample_size)
    };

    {
        let mut l = lab().lock().unwrap();
        let s = l.metrics.get_mut(variant).unwrap();
        s.runs += 1;
        s.consumed += consumed;
        s.succeeded += succeeded;
        s.retried += retried;
        s.dead_lettered += dead_count;
        s.alerts_fired += alerts;
    }

    let stats = dlq_stats_json(alert_threshold);
    let note = if variant == "silent" {
        "El consumidor no clasifico nada: transitorio y venenoso fueron al mismo lugar, sin reintentar y sin \
         registrar por que. El pipeline se ve sano —throughput normal, cero errores— porque los errores se fueron a \
         otro lado. Y nadie va a volver."
    } else {
        "Lo transitorio se reintento y casi todo se recupero; solo el veneno llego a la DLQ, con su clase de error y \
         una muestra del payload. La profundidad esta publicada y el umbral disparo alerta."
    };

    format!(
        "{{\n  \"variant\": \"{}\",\n  \"messages\": {},\n  \"transient_pct\": {},\n  \"poison_pct\": {},\n  \
         \"max_retries\": {},\n  \"consumed\": {},\n  \"succeeded\": {},\n  \"retried\": {},\n  \
         \"dead_lettered\": {},\n  \"alerts_fired\": {},\n  \"sampled\": {},\n  \"wall_ms\": {},\n  {},\n  \
         \"dead_letter_rate_pct\": {},\n  \"note\": \"{}\",\n  \"rust_note\": \"El enum de error con match \
         exhaustivo es la primitiva exacta para un caso que trata de clasificar: agregar una variante rompe la \
         compilacion en todos los lugares que la ignoran. Y un panic! no es un Result, asi que un bug del propio \
         consumidor no puede confundirse con un mensaje venenoso — en Python, Java, .NET y Node el catch generico \
         se traga las dos cosas.\"",
        variant,
        messages,
        transient_pct,
        poison_pct,
        if variant == "observed" { max_retries } else { 0 },
        consumed,
        succeeded,
        retried,
        dead_count,
        alerts,
        sampled,
        wall,
        stats,
        round2(dead_count as f64 * 100.0 / consumed.max(1) as f64),
        note
    )
}

fn diagnostics(stack: &str, alert_threshold: usize) -> String {
    let variants = {
        let l = lab().lock().unwrap();
        ["silent", "observed"]
            .iter()
            .map(|name| {
                let s = l.metrics.get(*name).cloned().unwrap_or_default();
                format!(
                    "\"{}\": {{ \"runs\": {}, \"consumed\": {}, \"succeeded\": {}, \"retried\": {}, \
                     \"dead_lettered\": {}, \"alerts_fired\": {} }}",
                    name, s.runs, s.consumed, s.succeeded, s.retried, s.dead_lettered, s.alerts_fired
                )
            })
            .collect::<Vec<_>>()
            .join(",\n    ")
    };
    format!(
        "{{\n  \"stack\": \"{}\",\n  \"case\": \"{}\",\n  \"variants\": {{\n    {}\n  }},\n  \"dlq\": {{\n  {}\n  \
         }},\n  \"arco_con_el_caso_15\": \"En el caso 15 la DLQ NACE: es la politica de rechazo que salva al \
         productor de bloquearse cuando la cola se llena. Aca se ve que pasa cuando nadie vuelve a mirarla.\",\n  \
         \"fidelity\": {{\n    \"real\": \"La clasificacion con enum y match exhaustivo, el reintento con \
         presupuesto acotado, el desglose por clase, el muestreo de payloads y el replay desde la DLQ son codigo de \
         verdad.\",\n    \"modelado\": \"La DLQ es un Vec en memoria, no SQS ni RabbitMQ. La clase de error de cada \
         mensaje es deterministica para que el escenario sea reproducible.\",\n    \"honesto\": \"Lo que define el \
         caso no es el broker: es que un mensaje que falla tiene que ir a algun lado, y que ese lado necesita \
         profundidad, antiguedad, clasificacion y una salida.\"\n  }},\n  \"interpretation\": {{\n    \"silent\": \
         \"dead_letter_rate_pct alto, by_error_class con una sola entrada ('unclassified') y alerts_fired en cero. \
         El pipeline se ve sano.\",\n    \"observed\": \"dead_letter_rate_pct bajo —solo el veneno—, \
         by_error_class desglosado y la alerta disparada.\",\n    \"rust_note\": \"Un panic! no es un Result: un \
         bug del consumidor no puede terminar en la DLQ disfrazado de dato malo.\"\n  }}",
        stack,
        CASE_NAME,
        variants,
        dlq_stats_json(alert_threshold)
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

    let messages = clamp(query_int(&q, "messages", 3000), 10, 200000) as usize;
    let transient_pct = clamp(query_int(&q, "transient_pct", 12), 0, 100) as u32;
    let poison_pct = clamp(query_int(&q, "poison_pct", 4), 0, 100) as u32;
    let max_retries = clamp(query_int(&q, "max_retries", 3), 0, 20) as u32;
    let alert_threshold = clamp(query_int(&q, "alert_threshold", 50), 0, 100000) as usize;
    let sample_size = clamp(query_int(&q, "sample_size", 20), 0, 1000) as usize;
    let limit = clamp(query_int(&q, "limit", 500), 1, 200000) as usize;

    let mut status = 200;
    let body_core = match path.as_str() {
        "/" | "/index" => format!(
            "{{\n  \"lab\": \"Problem-Driven Systems Lab\",\n  \"case\": \"{}\",\n  \"stack\": \"{}\",\n  \
             \"goal\": \"Mostrar que un pipeline con throughput normal y cero errores puede estar perdiendo el 16% \
             de los mensajes, porque los errores se fueron a un lugar que nadie mira.\",\n  \"arco\": \"Cierra el \
             arco del caso 15, donde la DLQ nace como politica de rechazo.\",\n  \"rust_specific\": \"enum de error \
             con match exhaustivo: agregar una clase nueva rompe la compilacion en todos los lugares que la \
             ignoran.\",\n  \"routes\": {{\n    \"/health\": \"Estado basico del servicio.\",\n    \
             \"/consume-silent?messages=3000\": \"Cualquier fallo a la DLQ, sin clasificar ni reintentar.\",\n    \
             \"/consume-observed?messages=3000\": \"Clasificar, reintentar lo transitorio, alertar.\",\n    \
             \"/dlq/stats\": \"Profundidad, antiguedad del mas viejo y desglose por clase de error.\",\n    \
             \"/dlq/drain?limit=500\": \"Replay desde la DLQ: que se recupera y que sigue siendo veneno.\",\n    \
             \"/diagnostics/summary\": \"Comparativa entre variantes.\",\n    \"/reset-lab\": \"Vacia la DLQ y las \
             metricas.\"\n  }}",
            CASE_NAME, stack
        ),
        "/health" => format!(
            "{{\n  \"status\": \"ok\",\n  \"stack\": \"{}\",\n  \"case\": \"{}\"",
            stack, CASE_NAME
        ),
        "/consume-silent" => run_scenario("silent", messages, transient_pct, poison_pct, max_retries,
                                          alert_threshold, sample_size),
        "/consume-observed" => run_scenario("observed", messages, transient_pct, poison_pct, max_retries,
                                            alert_threshold, sample_size),
        "/dlq/stats" => format!(
            "{{\n  {},\n  \"note\": \"Una DLQ sin profundidad publicada, sin antiguedad del mensaje mas viejo y \
             sin desglose por clase de error no es una cola: es un agujero.\"",
            dlq_stats_json(alert_threshold)
        ),
        "/dlq/drain" => dlq_drain(limit, transient_pct, poison_pct, max_retries),
        "/diagnostics/summary" => diagnostics(stack, alert_threshold),
        "/reset-lab" => {
            let mut l = lab().lock().unwrap();
            l.dlq.clear();
            l.alerts_fired = 0;
            l.metrics.insert("silent".to_string(), Slot::default());
            l.metrics.insert("observed".to_string(), Slot::default());
            "{\n  \"status\": \"reset\",\n  \"message\": \"DLQ y metricas reiniciadas.\"".to_string()
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
