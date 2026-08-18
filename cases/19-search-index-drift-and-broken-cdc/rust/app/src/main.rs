//! Caso 19 — Deriva del indice de busqueda y CDC roto — stack Rust 1.83.
//!
//! Dual-write: la aplicacion escribe en la base y despues en el indice. Cuando la
//! segunda escritura falla —y falla, porque son dos sistemas sin transaccion
//! comun— nadie se entera. La busqueda sigue respondiendo 200; lo que devuelve
//! esta mal.
//!
//! Outbox + checkpoint + reconciliacion: el cambio se anota junto con la escritura
//! a la base, el consumidor aplica en orden y solo avanza el checkpoint cuando la
//! aplicacion se confirma, y un barrido repara lo que los dos primeros no cubren.
//!
//! Las tres formas de deriva, que no son la misma cosa:
//!
//! ```text
//! missing  — esta en la base, no en el indice      → la busqueda no lo encuentra
//! stale    — esta en los dos, con version vieja    → la busqueda lo encuentra mal
//! orphan   — esta en el indice, borrado en la base → la busqueda devuelve fantasmas
//! ```
//!
//! # Primitiva Rust distintiva
//!
//! **`#[must_use]` sobre `Result`.** Este caso entero nace de una escritura que
//! fallo y que nadie miro. En Rust, no mirarla no es una omision: es algo que hay
//! que escribir.
//!
//! ```ignore
//! indice.escribir(&doc);          // warning: unused `Result` that must be used
//! let _ = indice.escribir(&doc);  // compila — y el `let _ =` queda en el diff
//! indice.escribir(&doc)?;         // el error sube
//! ```
//!
//! La primera linea produce una advertencia del compilador **sin configurar
//! nada**: `#[must_use]` esta en la definicion de `Result` en la `std`. Con
//! `#![deny(unused_must_use)]` —una linea— pasa a ser un error de compilacion.
//!
//! Go llega parecido con `errcheck`, pero errcheck es una herramienta externa que
//! alguien tiene que instalar y poner en el CI. En Python, Java, .NET, Node y PHP
//! **no hay nada**: el `except:`, el `catch {}` y la promesa sin `await` compilan
//! y callan.
//!
//! La segunda mitad: `HashSet::difference` e `intersection` dan el diff de tres
//! caras sin escribirlo a mano, algo que Go no puede — asi que Rust es el unico
//! stack del laboratorio que tiene **las dos** piezas: el error imposible de
//! ignorar por accidente y el algebra de conjuntos para el diagnostico.

#![deny(unused_must_use)]

use std::collections::{BTreeMap, HashMap, HashSet};
use std::io::{BufRead, BufReader, Write};
use std::net::{TcpListener, TcpStream};
use std::sync::{Mutex, OnceLock};
use std::thread;
use std::time::{Instant, SystemTime, UNIX_EPOCH};

const CASE_NAME: &str = "19 - Deriva del indice de busqueda y CDC roto";
const TERMS: [&str; 8] = ["alfa", "beta", "gamma", "delta", "epsilon", "zeta", "eta", "theta"];

fn start() -> &'static Instant {
    static START: OnceLock<Instant> = OnceLock::new();
    START.get_or_init(Instant::now)
}

fn now_ms() -> f64 {
    start().elapsed().as_secs_f64() * 1000.0
}

#[derive(Clone)]
struct Doc {
    version: u32,
    term: &'static str,
    deleted: bool,
    updated_ms: f64,
}

#[derive(Clone)]
struct IdxEntry {
    version: u32,
    term: &'static str,
}

#[derive(Clone)]
struct Change {
    seq: u64,
    id: String,
    version: u32,
    term: &'static str,
    deleted: bool,
}

#[derive(Default, Clone)]
struct Slot {
    runs: u64,
    writes: u64,
    silent_failures: u64,
    drift_count: u64,
    outbox_retried: u64,
}

struct Lab {
    db: HashMap<String, Doc>,
    index: HashMap<String, IdxEntry>,
    outbox: BTreeMap<u64, Change>,
    checkpoint: u64,
    seq: u64,
    metrics: HashMap<String, Slot>,
}

impl Lab {
    fn new() -> Self {
        let mut metrics = HashMap::new();
        metrics.insert("drifted".to_string(), Slot::default());
        metrics.insert("reconciled".to_string(), Slot::default());
        Lab {
            db: HashMap::new(),
            index: HashMap::new(),
            outbox: BTreeMap::new(),
            checkpoint: 0,
            seq: 0,
            metrics,
        }
    }

    fn reset_data(&mut self) {
        self.db.clear();
        self.index.clear();
        self.outbox.clear();
        self.checkpoint = 0;
        self.seq = 0;
    }

    /// La escritura al segundo sistema. Devuelve `Result` a proposito: el
    /// `#[must_use]` de la `std` es lo que impide ignorarla sin escribirlo.
    fn escribir_indice(
        &mut self,
        id: &str,
        e: IdxEntry,
        borrar: bool,
        idx: u64,
        fail_rate: u32,
    ) -> Result<(), String> {
        if index_write_fails(idx, fail_rate) {
            return Err(format!("el indice rechazo la escritura de {}", id));
        }
        if borrar {
            self.index.remove(id);
        } else {
            self.index.insert(id.to_string(), e);
        }
        Ok(())
    }
}

fn lab() -> &'static Mutex<Lab> {
    static LAB: OnceLock<Mutex<Lab>> = OnceLock::new();
    LAB.get_or_init(|| Mutex::new(Lab::new()))
}

fn round2(v: f64) -> f64 {
    (v * 100.0).round() / 100.0
}

/// El indice rechaza una fraccion de las escrituras.
///
/// El modulo 101 —primo— importa: con 100, las dos escrituras del mismo documento
/// (i e i+keyspace) caen en el mismo residuo y corren siempre la misma suerte, asi
/// que nunca se produce deriva `stale`. Con 101 se separan.
fn index_write_fails(idx: u64, fail_rate: u32) -> bool {
    ((idx.wrapping_mul(37)) % 101) < fail_rate as u64
}

// ---------------------------------------------------------------------------
// Variante dual-write: escribir en la base, escribir en el indice, y rezar
// ---------------------------------------------------------------------------

fn run_drifted(writes: u64, fail_rate: u32, delete_pct: u32) -> u64 {
    let mut l = lab().lock().unwrap();
    l.reset_data();
    let keyspace = (writes / 2).max(1);
    let mut silent = 0u64;

    for i in 0..writes {
        let id = format!("doc-{}", i % keyspace);
        let term = TERMS[(i as usize) % TERMS.len()];
        let deleting = (i.wrapping_mul(53) % 101) < delete_pct as u64;

        let version = l.db.get(&id).map(|d| d.version + 1).unwrap_or(1);
        l.db.insert(
            id.clone(),
            Doc { version, term, deleted: deleting, updated_ms: now_ms() },
        );

        // AQUI ESTA EL BUG, y en Rust hay que escribirlo. Sin el `if let Err`,
        // el compilador advierte por el `Result` sin usar — y con el
        // `#![deny(unused_must_use)]` de arriba, directamente no compila.
        if let Err(_e) = l.escribir_indice(&id, IdxEntry { version, term }, deleting, i, fail_rate) {
            silent += 1;
        }
    }
    silent
}

// ---------------------------------------------------------------------------
// Variante outbox + checkpoint + reconciliacion
// ---------------------------------------------------------------------------

fn run_reconciled(writes: u64, fail_rate: u32, delete_pct: u32) -> u64 {
    {
        let mut l = lab().lock().unwrap();
        l.reset_data();
        let keyspace = (writes / 2).max(1);

        for i in 0..writes {
            let id = format!("doc-{}", i % keyspace);
            let term = TERMS[(i as usize) % TERMS.len()];
            let deleting = (i.wrapping_mul(53) % 101) < delete_pct as u64;

            let version = l.db.get(&id).map(|d| d.version + 1).unwrap_or(1);
            l.db.insert(
                id.clone(),
                Doc { version, term, deleted: deleting, updated_ms: now_ms() },
            );
            // El cambio se anota JUNTO con la escritura, bajo el mismo lock.
            l.seq += 1;
            let seq = l.seq;
            l.outbox.insert(seq, Change { seq, id, version, term, deleted: deleting });
        }
    }
    drain_outbox(fail_rate, 5)
}

/// Aplica los cambios pendientes al indice, en orden, reintentando.
///
/// - **En orden**: el `BTreeMap` los entrega ya ordenados por secuencia. Saltear
///   uno dejaria una version vieja pisando a una nueva.
/// - **El checkpoint avanza solo con la confirmacion**: un cambio que no entra
///   queda **pendiente**, no perdido. Eso es lo que el dual-write no puede hacer.
fn drain_outbox(fail_rate: u32, max_retries: u32) -> u64 {
    let mut l = lab().lock().unwrap();
    let checkpoint = l.checkpoint;
    let pending: Vec<Change> = l.outbox.range((checkpoint + 1)..).map(|(_, c)| c.clone()).collect();
    let mut retried = 0u64;

    for entry in pending {
        let mut applied = false;
        for attempt in 0..max_retries {
            let idx = entry.seq * (attempt as u64 + 1) + attempt as u64;
            match l.escribir_indice(
                &entry.id,
                IdxEntry { version: entry.version, term: entry.term },
                entry.deleted,
                idx,
                fail_rate,
            ) {
                Ok(()) => {
                    applied = true;
                    break;
                }
                Err(_) => retried += 1,
            }
        }
        if !applied {
            break; // el checkpoint se frena: el cambio queda pendiente
        }
        l.checkpoint = entry.seq;
    }
    retried
}

// ---------------------------------------------------------------------------
// La deriva de tres caras, con el algebra de conjuntos de la std
// ---------------------------------------------------------------------------

struct Drift {
    db_count: usize,
    index_count: usize,
    missing: Vec<String>,
    stale: Vec<String>,
    orphan: Vec<String>,
    drift_age_ms: f64,
    checkpoint: u64,
    outbox_pending: usize,
}

fn compute_drift_locked(l: &Lab) -> Drift {
    let db_live: HashMap<&String, &Doc> = l.db.iter().filter(|(_, d)| !d.deleted).collect();
    let db_ids: HashSet<&String> = db_live.keys().copied().collect();
    let index_ids: HashSet<&String> = l.index.keys().collect();

    let mut missing: Vec<String> = db_ids.difference(&index_ids).map(|s| (*s).clone()).collect();
    let mut orphan: Vec<String> = index_ids.difference(&db_ids).map(|s| (*s).clone()).collect();
    let stale: Vec<String> = db_ids
        .intersection(&index_ids)
        .filter(|id| l.index[**id].version != db_live[**id].version)
        .map(|s| (*s).clone())
        .collect();

    let now = now_ms();
    let mut oldest = 0.0f64;
    for id in missing.iter().chain(stale.iter()) {
        let age = now - db_live[id].updated_ms;
        if age > oldest {
            oldest = age;
        }
    }

    missing.sort();
    orphan.sort();
    Drift {
        db_count: db_live.len(),
        index_count: l.index.len(),
        missing,
        stale,
        orphan,
        drift_age_ms: round2(oldest),
        checkpoint: l.checkpoint,
        outbox_pending: l.outbox.range((l.checkpoint + 1)..).count(),
    }
}

fn drift_json(d: &Drift) -> String {
    let ids = |v: &Vec<String>| {
        v.iter()
            .take(8)
            .map(|s| format!("\"{}\"", s))
            .collect::<Vec<_>>()
            .join(", ")
    };
    format!(
        "\"db_count\": {},\n  \"index_count\": {},\n  \"missing\": {},\n  \"stale\": {},\n  \"orphan\": {},\n  \
         \"drift_count\": {},\n  \"drift_age_ms\": {},\n  \"missing_ids\": [{}],\n  \"orphan_ids\": [{}],\n  \
         \"last_checkpoint\": {},\n  \"outbox_pending\": {}",
        d.db_count,
        d.index_count,
        d.missing.len(),
        d.stale.len(),
        d.orphan.len(),
        d.missing.len() + d.stale.len() + d.orphan.len(),
        d.drift_age_ms,
        ids(&d.missing),
        ids(&d.orphan),
        d.checkpoint,
        d.outbox_pending
    )
}

fn reconcile() -> String {
    let t0 = now_ms();
    let mut l = lab().lock().unwrap();
    let before = compute_drift_locked(&l);
    let before_count = before.missing.len() + before.stale.len() + before.orphan.len();
    let (bm, bs, bo) = (before.missing.len(), before.stale.len(), before.orphan.len());
    drop(before);

    let db_live: HashMap<String, Doc> = l
        .db
        .iter()
        .filter(|(_, d)| !d.deleted)
        .map(|(k, v)| (k.clone(), v.clone()))
        .collect();
    for (id, d) in &db_live {
        let necesita = match l.index.get(id) {
            None => true,
            Some(cur) => cur.version != d.version,
        };
        if necesita {
            l.index.insert(id.clone(), IdxEntry { version: d.version, term: d.term });
        }
    }
    l.index.retain(|id, _| db_live.contains_key(id));

    let after = compute_drift_locked(&l);
    let after_count = after.missing.len() + after.stale.len() + after.orphan.len();
    let after_json = drift_json(&after);
    drop(l);

    format!(
        "{{\n  \"reconcile_duration_ms\": {},\n  \"drift_before\": {},\n  \"drift_after\": {},\n  \
         \"repaired\": {},\n  \"detail_before\": {{ \"missing\": {}, \"stale\": {}, \"orphan\": {} }},\n  \
         \"state\": {{\n  {}\n  }},\n  \"note\": \"El barrido es la red de seguridad de lo que el outbox no \
         cubre: un indice restaurado de un backup viejo, una reindexacion parcial, un borrado manual. Sin el, el \
         outbox garantiza que ningun cambio NUEVO se pierda — pero no arregla los que ya se perdieron.\"",
        round2(now_ms() - t0),
        before_count,
        after_count,
        before_count - after_count,
        bm,
        bs,
        bo,
        after_json
    )
}

// ---------------------------------------------------------------------------
// Las consultas: medir la deriva desde donde la ve el usuario
// ---------------------------------------------------------------------------

fn run_queries(queries: usize) -> (f64, f64) {
    let l = lab().lock().unwrap();
    let db_live: HashMap<&String, &Doc> = l.db.iter().filter(|(_, d)| !d.deleted).collect();
    let (mut hits, mut expected, mut returned) = (0usize, 0usize, 0usize);

    for q in 0..queries {
        let term = TERMS[q % TERMS.len()];
        let esperados: HashSet<&String> = db_live
            .iter()
            .filter(|(_, d)| d.term == term)
            .map(|(k, _)| *k)
            .collect();
        for (id, e) in l.index.iter() {
            if e.term == term {
                returned += 1;
                if esperados.contains(id) {
                    hits += 1;
                }
            }
        }
        expected += esperados.len();
    }
    (
        round2(hits as f64 * 100.0 / expected.max(1) as f64),
        round2(hits as f64 * 100.0 / returned.max(1) as f64),
    )
}

#[allow(clippy::too_many_arguments)]
fn run_scenario(variant: &str, writes: u64, fail_rate: u32, delete_pct: u32, queries: usize) -> String {
    let t0 = now_ms();
    let (silent, retried) = if variant == "drifted" {
        (run_drifted(writes, fail_rate, delete_pct), 0)
    } else {
        let r = run_reconciled(writes, fail_rate, delete_pct);
        let _ = reconcile();
        (0, r)
    };

    let (drift_body, drift_count) = {
        let l = lab().lock().unwrap();
        let d = compute_drift_locked(&l);
        let c = (d.missing.len() + d.stale.len() + d.orphan.len()) as u64;
        (drift_json(&d), c)
    };
    let (recall, precision) = run_queries(queries);

    {
        let mut l = lab().lock().unwrap();
        let s = l.metrics.get_mut(variant).unwrap();
        s.runs += 1;
        s.writes += writes;
        s.silent_failures += silent;
        s.drift_count += drift_count;
        s.outbox_retried += retried;
    }

    let note = if variant == "drifted" {
        "La escritura al indice fallo y el codigo siguio como si nada. La base y el indice no comparten \
         transaccion, asi que la unica forma de enterarse es mirando — y nadie mira, porque la busqueda sigue \
         respondiendo 200."
    } else {
        "El outbox garantiza que ningun cambio nuevo se pierda, el checkpoint impide saltear uno, y el barrido \
         repara lo que los dos primeros no cubren. Deriva final: cero."
    };

    format!(
        "{{\n  \"variant\": \"{}\",\n  \"writes\": {},\n  \"fail_rate_pct\": {},\n  \"delete_pct\": {},\n  \
         \"silent_failures\": {},\n  \"outbox_retried\": {},\n  {},\n  \"queries\": {},\n  \
         \"search_recall_pct\": {},\n  \"search_precision_pct\": {},\n  \"wall_ms\": {},\n  \"note\": \"{}\",\n  \
         \"rust_note\": \"El #[must_use] de Result hace que ignorar la escritura fallida tenga que escribirse, y \
         el #![deny(unused_must_use)] de la cabecera lo convierte en error de compilacion. Go llega parecido con \
         errcheck, que es una herramienta externa; en Python, Java, .NET, Node y PHP no hay nada. Y HashSet da el \
         diff de tres caras sin escribirlo a mano — Rust es el unico stack del lab que tiene las dos piezas.\"",
        variant,
        writes,
        fail_rate,
        delete_pct,
        silent,
        retried,
        drift_body,
        queries,
        recall,
        precision,
        round2(now_ms() - t0),
        note
    )
}

fn index_state(stack: &str) -> String {
    let l = lab().lock().unwrap();
    let d = compute_drift_locked(&l);
    format!(
        "{{\n  \"stack\": \"{}\",\n  {},\n  \"note\": \"`missing` no se encuentra, `stale` se encuentra mal y \
         `orphan` es un fantasma. Las tres se ven igual desde afuera — 'la busqueda anda rara' — y se arreglan \
         distinto.\"",
        stack,
        drift_json(&d)
    )
}

fn diagnostics(stack: &str) -> String {
    let variants = {
        let l = lab().lock().unwrap();
        ["drifted", "reconciled"]
            .iter()
            .map(|name| {
                let s = l.metrics.get(*name).cloned().unwrap_or_default();
                format!(
                    "\"{}\": {{ \"runs\": {}, \"writes\": {}, \"silent_failures\": {}, \"drift_count\": {}, \
                     \"outbox_retried\": {} }}",
                    name, s.runs, s.writes, s.silent_failures, s.drift_count, s.outbox_retried
                )
            })
            .collect::<Vec<_>>()
            .join(",\n    ")
    };
    format!(
        "{{\n  \"stack\": \"{}\",\n  \"case\": \"{}\",\n  \"variants\": {{\n    {}\n  }},\n  \"index\": {} }},\n  \
         \"fidelity\": {{\n    \"real\": \"El diff de tres caras, el outbox con orden y checkpoint, y el barrido \
         de reconciliacion son codigo de verdad, con la primitiva idiomatica de cada runtime.\",\n    \
         \"modelado\": \"El indice de busqueda es un HashMap en memoria, no Elasticsearch. La falla de escritura \
         es deterministica para que el escenario sea reproducible.\",\n    \"honesto\": \"Lo que importa del caso \
         no es el motor de busqueda: es que la base y el indice son dos sistemas sin transaccion comun.\"\n  }},\n  \
         \"interpretation\": {{\n    \"drifted\": \"drift_count > 0 y recall por debajo de 100 con el servicio \
         respondiendo 200 a todo.\",\n    \"reconciled\": \"drift_count = 0, recall y precision en 100.\",\n    \
         \"rust_note\": \"Es el unico stack donde el bug original —ignorar la escritura fallida— no compila sin \
         escribirlo a proposito.\"\n  }}",
        stack, CASE_NAME, variants, index_state(stack)
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

    let writes = clamp(query_int(&q, "writes", 2000), 10, 200000) as u64;
    let fail_rate = clamp(query_int(&q, "fail_rate", 8), 0, 100) as u32;
    let delete_pct = clamp(query_int(&q, "delete_pct", 5), 0, 50) as u32;
    let queries = clamp(query_int(&q, "queries", 200), 1, 5000) as usize;

    let mut status = 200;
    let body_core = match path.as_str() {
        "/" | "/index" => format!(
            "{{\n  \"lab\": \"Problem-Driven Systems Lab\",\n  \"case\": \"{}\",\n  \"stack\": \"{}\",\n  \
             \"goal\": \"Mostrar que una busqueda que responde 200 puede estar respondiendo mal, y que la unica \
             forma de saberlo es comparar los dos lados a proposito.\",\n  \"rust_specific\": \"#[must_use] sobre \
             Result: ignorar la escritura fallida no compila sin escribirlo. Y HashSet da el diff de tres caras \
             sin recorrerlo a mano.\",\n  \"routes\": {{\n    \"/health\": \"Estado basico del servicio.\",\n    \
             \"/search-drifted?writes=2000&fail_rate=8\": \"Dual-write: el indice se desincroniza en silencio.\",\n    \
             \"/search-reconciled?writes=2000&fail_rate=8\": \"Outbox + checkpoint + barrido: deriva cero.\",\n    \
             \"/reconcile\": \"Un barrido suelto, para ver que encuentra y que repara.\",\n    \
             \"/index/state\": \"Las tres caras de la deriva y la antiguedad del cambio mas viejo.\",\n    \
             \"/diagnostics/summary\": \"Comparativa entre variantes.\",\n    \"/reset-lab\": \"Vacia la base, el \
             indice, el outbox y las metricas.\"\n  }}",
            CASE_NAME, stack
        ),
        "/health" => format!(
            "{{\n  \"status\": \"ok\",\n  \"stack\": \"{}\",\n  \"case\": \"{}\"",
            stack, CASE_NAME
        ),
        "/search-drifted" => run_scenario("drifted", writes, fail_rate, delete_pct, queries),
        "/search-reconciled" => run_scenario("reconciled", writes, fail_rate, delete_pct, queries),
        "/reconcile" => reconcile(),
        "/index/state" => index_state(stack),
        "/diagnostics/summary" => diagnostics(stack),
        "/reset-lab" => {
            let mut l = lab().lock().unwrap();
            l.reset_data();
            l.metrics.insert("drifted".to_string(), Slot::default());
            l.metrics.insert("reconciled".to_string(), Slot::default());
            "{\n  \"status\": \"reset\",\n  \"message\": \"Base, indice, outbox y metricas reiniciados.\"".to_string()
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
