// Caso 17 — Migracion de esquema sin downtime — stack Rust 1.83.
//
// Blocking: un `ALTER TABLE` toma el lock exclusivo durante toda la migracion.
// Expand-contract: cuatro fases, y el lock se toma y se suelta en cada lote.
//
// Primitiva Rust distintiva — y aca es la mas restrictiva de las siete:
//
//   `std::sync::RwLock` **no tiene deadline de ninguna clase**. Hay `read()`
//   que espera para siempre y `try_read()` que no espera nada. No existe
//   `try_read_for(Duration)` — eso vive en `parking_lot`, que es una crate
//   externa, y este lab compila sin red.
//
//   Java tiene `tryLock(timeout, unit)`, .NET `TryEnterReadLock(ms)`, Python se
//   lo construye con `Condition.wait`, Go lo arma con goroutine y `select`.
//   Rust, en la `std`, no ofrece ninguna de las tres cosas.
//
//   Asi que el deadline del lector se arma con un bucle de `try_read()` y una
//   pausa corta — un spin acotado. Funciona, es honesto, y es peor que las
//   alternativas: consume CPU mientras espera en vez de dormir en el kernel.
//   **Es el unico caso del laboratorio donde la respuesta de Rust es peor que
//   la de los otros seis**, y vale decirlo con el mismo enfasis con el que se
//   dicen sus ventajas en los casos 12, 14 y 16.
//
//   Lo que Rust si aporta, y no es poco: los guards. `RwLockReadGuard` y
//   `RwLockWriteGuard` sueltan el lock en su `Drop`, asi que **no existe el
//   camino de salida que olvida el unlock**. En Go hay que escribir el `defer`,
//   en Java el `finally`, en .NET el `try/finally`. Aca no hay linea que
//   olvidar — igual que en el caso 14.
//
// El tiempo de migracion es un `thread::sleep`: un ALTER TABLE se demora
// esperando I/O del motor, no quemando CPU del proceso de la app.

use std::collections::HashMap;
use std::io::{BufRead, BufReader, Write};
use std::net::{TcpListener, TcpStream};
use std::sync::{Arc, Barrier, LazyLock, Mutex, RwLock};
use std::thread;
use std::time::{Duration, Instant};

const CASE_NAME: &str = "17 - Migracion de esquema sin downtime";
const READ_TIMEOUT_MS: u64 = 120;

struct Table {
    rows: usize,
    has_new_column: bool,
    backfilled: usize,
    old_column_dropped: bool,
    read_from_new_column: bool,
}

static TABLE: LazyLock<RwLock<Table>> = LazyLock::new(|| {
    RwLock::new(Table {
        rows: 20000,
        has_new_column: false,
        backfilled: 0,
        old_column_dropped: false,
        read_from_new_column: false,
    })
});
static PHASE: LazyLock<Mutex<String>> = LazyLock::new(|| Mutex::new("idle".to_string()));

#[derive(Default, Clone)]
struct Slot {
    runs: i64,
    lock_held_ms: f64,
    readers_served: i64,
    readers_failed: i64,
    max_read_wait_ms: f64,
    backfill_batches: i64,
}

static METRICS: LazyLock<Mutex<HashMap<String, Slot>>> = LazyLock::new(|| Mutex::new(fresh_metrics()));

fn fresh_metrics() -> HashMap<String, Slot> {
    let mut m = HashMap::new();
    m.insert("blocking".to_string(), Slot::default());
    m.insert("expand_contract".to_string(), Slot::default());
    m
}

fn set_phase(p: &str) {
    *PHASE.lock().unwrap() = p.to_string();
}

fn get_phase() -> String {
    PHASE.lock().unwrap().clone()
}

fn reset_table(rows: usize) {
    let mut t = TABLE.write().unwrap();
    t.rows = rows;
    t.has_new_column = false;
    t.backfilled = 0;
    t.old_column_dropped = false;
    t.read_from_new_column = false;
    drop(t);
    set_phase("idle");
}

/// El deadline que `std::sync::RwLock` no trae.
///
/// Un spin acotado con `try_read()`: consume CPU mientras espera, en vez de
/// dormir en el kernel como haria un `tryLock(timeout)` de Java. Es la opcion
/// que queda sin salir de la `std`, y es honestamente peor.
fn read_with_deadline(timeout_ms: u64) -> bool {
    let deadline = Instant::now() + Duration::from_millis(timeout_ms);
    loop {
        if let Ok(guard) = TABLE.try_read() {
            let _ = guard.rows;
            return true; // el guard se dropea aca y suelta el lock solo
        }
        if Instant::now() >= deadline {
            return false;
        }
        thread::sleep(Duration::from_micros(200));
    }
}

#[derive(Default)]
struct ReaderResult {
    served: i64,
    failed: i64,
    waits: Vec<f64>,
}

fn reader(gate: &Barrier, stop_at: Instant) -> ReaderResult {
    gate.wait();
    let mut res = ReaderResult::default();
    while Instant::now() < stop_at {
        let t0 = Instant::now();
        let ok = read_with_deadline(READ_TIMEOUT_MS);
        res.waits.push(ms_since(t0));
        if ok {
            res.served += 1;
        } else {
            res.failed += 1;
        }
        thread::sleep(Duration::from_millis(2));
    }
    res
}

// ---------- variante blocking ----------

fn migrate_blocking(rows: usize, ms_per_1k: u64) -> (f64, i64) {
    reset_table(rows);
    set_phase("expand");
    let duration = Duration::from_millis((rows as u64 / 1000) * ms_per_1k);

    let t0 = Instant::now();
    // El write guard se toma UNA vez y se suelta al final — cuando sale de
    // alcance, no cuando alguien se acuerda.
    {
        let mut t = TABLE.write().unwrap();
        thread::sleep(duration);
        t.has_new_column = true;
        t.backfilled = rows;
        t.old_column_dropped = true;
        t.read_from_new_column = true;
    }
    let held = ms_since(t0);
    set_phase("done");
    (held, 1)
}

// ---------- variante expand-contract ----------

fn migrate_expand_contract(rows: usize, ms_per_1k: u64, batch_size: usize, pause_ms: u64) -> (f64, i64) {
    reset_table(rows);
    let total_ms = (rows as f64 / 1000.0) * ms_per_1k as f64;
    let mut held = 0.0;
    let mut batches = 0i64;

    // 1. EXPAND — columna nullable: metadata, instantaneo.
    set_phase("expand");
    let t0 = Instant::now();
    {
        let mut t = TABLE.write().unwrap();
        t.has_new_column = true;
    }
    held += ms_since(t0);

    // 2. BACKFILL — por lotes, soltando el lock entre cada uno.
    set_phase("backfill");
    let mut done = 0usize;
    let per_batch_ms = total_ms * (batch_size as f64 / rows.max(1) as f64);
    while done < rows {
        let chunk = batch_size.min(rows - done);
        let t0 = Instant::now();
        {
            let mut t = TABLE.write().unwrap();
            thread::sleep(Duration::from_millis(per_batch_ms.max(1.0) as u64));
            t.backfilled += chunk;
        }
        held += ms_since(t0);
        done += chunk;
        batches += 1;
        // La pausa entre lotes es lo que le devuelve el motor a la app.
        if pause_ms > 0 {
            thread::sleep(Duration::from_millis(pause_ms));
        }
    }

    // 3. SWITCH — feature flag. No toca datos: reversible en un segundo.
    set_phase("switch");
    {
        let mut t = TABLE.write().unwrap();
        t.read_from_new_column = true;
    }

    // 4. CONTRACT — recien ahora se borra la vieja.
    set_phase("contract");
    let t0 = Instant::now();
    {
        let mut t = TABLE.write().unwrap();
        t.old_column_dropped = true;
    }
    held += ms_since(t0);
    set_phase("done");
    (held, batches)
}

// ---------- orquestacion ----------

fn run_migration(variant: &str, rows: usize, readers: usize, ms_per_1k: u64, batch_size: usize, pause_ms: u64) -> String {
    let budget_ms = (rows as f64 / 1000.0) * ms_per_1k as f64
        + (rows as f64 / batch_size.max(1) as f64) * pause_ms as f64
        + 400.0;
    let stop_at = Instant::now() + Duration::from_millis(budget_ms as u64);
    let gate = Arc::new(Barrier::new(readers + 1));

    let handles: Vec<_> = (0..readers)
        .map(|_| {
            let gate = Arc::clone(&gate);
            thread::spawn(move || reader(&gate, stop_at))
        })
        .collect();

    let started = Instant::now();
    gate.wait();
    let (held, batches) = if variant == "blocking" {
        migrate_blocking(rows, ms_per_1k)
    } else {
        migrate_expand_contract(rows, ms_per_1k, batch_size, pause_ms)
    };
    let migration_ms = ms_since(started);

    let results: Vec<ReaderResult> = handles
        .into_iter()
        .map(|h| h.join().unwrap_or_default())
        .collect();
    let wall_ms = ms_since(started);

    let served: i64 = results.iter().map(|r| r.served).sum();
    let failed: i64 = results.iter().map(|r| r.failed).sum();
    let mut waits: Vec<f64> = results.iter().flat_map(|r| r.waits.clone()).collect();
    waits.sort_by(|a, b| a.partial_cmp(b).unwrap());
    let max_wait = waits.last().copied().unwrap_or(0.0);

    {
        let mut m = METRICS.lock().unwrap();
        let s = m.entry(variant.to_string()).or_default();
        s.runs += 1;
        s.lock_held_ms += held;
        s.readers_served += served;
        s.readers_failed += failed;
        if max_wait > s.max_read_wait_ms {
            s.max_read_wait_ms = max_wait;
        }
        s.backfill_batches += batches;
    }

    let (phase, backfilled, rows_total) = {
        let t = TABLE.read().unwrap();
        (get_phase(), t.backfilled, t.rows)
    };

    let longest = if variant == "blocking" { held } else { held / batches.max(1) as f64 };
    let note = if variant == "blocking" {
        "Un solo lock exclusivo tomado durante toda la migracion: los lectores esperan lo que dure, y los que tienen timeout fallan. Es el ALTER TABLE que devuelve 503 durante veinte minutos."
    } else {
        "Expand, backfill por lotes con pausa, switch por feature flag y contract. El lock se toma y se suelta en cada lote, asi que ningun lector espera mas que un lote."
    };

    format!(
        r#"{{"variant":"{}","rows_total":{},"readers":{},"phase":"{}","lock_held_ms":{},"longest_single_lock_ms":{},"readers_served":{},"readers_failed":{},"availability_pct":{},"p99_read_wait_ms":{},"max_read_wait_ms":{},"read_timeout_ms":{READ_TIMEOUT_MS},"backfill_batches":{},"backfill_progress_pct":{},"migration_ms":{},"wall_ms":{},"note":"{}"}}"#,
        escape(variant), rows_total, readers, escape(&phase),
        round2(held), round2(longest), served, failed,
        round2(served as f64 * 100.0 / (served + failed).max(1) as f64),
        percentile(&waits, 99), round2(max_wait), batches,
        round2(backfilled as f64 * 100.0 / rows_total.max(1) as f64),
        round2(migration_ms), round2(wall_ms), note
    )
}

// ---------- rutas ----------

fn migration_state() -> String {
    let t = TABLE.read().unwrap();
    format!(
        r#"{{"phase":"{}","phases":["idle","expand","backfill","switch","contract","done"],"rows_total":{},"has_new_column":{},"backfilled":{},"backfill_progress_pct":{},"old_column_dropped":{},"read_from_new_column":{},"read_timeout_ms":{READ_TIMEOUT_MS},"note":"El feature flag read_from_new_column es lo unico reversible en un segundo. Por eso el switch va antes del contract, y no al reves."}}"#,
        escape(&get_phase()), t.rows, t.has_new_column, t.backfilled,
        round2(t.backfilled as f64 * 100.0 / t.rows.max(1) as f64),
        t.old_column_dropped, t.read_from_new_column
    )
}

fn backfill_step(batch_size: usize, ms_per_1k: u64) -> String {
    let (rows, done, has_col) = {
        let t = TABLE.read().unwrap();
        (t.rows, t.backfilled, t.has_new_column)
    };
    if !has_col {
        return r#"{"status":"skipped","reason":"la columna nueva todavia no existe: falta la fase expand"}"#.to_string();
    }
    if done >= rows {
        return format!(r#"{{"status":"complete","backfilled":{done},"rows_total":{rows}}}"#);
    }
    let chunk = batch_size.min(rows - done);
    let t0 = Instant::now();
    let now = {
        let mut t = TABLE.write().unwrap();
        let per = (rows as f64 / 1000.0) * ms_per_1k as f64 * (chunk as f64 / rows.max(1) as f64);
        thread::sleep(Duration::from_millis(per.max(1.0) as u64));
        t.backfilled += chunk;
        t.backfilled
    };
    format!(
        r#"{{"status":"batch_done","batch_size":{},"lock_held_ms":{},"backfilled":{},"rows_total":{},"backfill_progress_pct":{}}}"#,
        chunk, round2(ms_since(t0)), now, rows,
        round2(now as f64 * 100.0 / rows.max(1) as f64)
    )
}

fn variant_json(name: &str, s: &Slot) -> String {
    format!(
        r#""{}":{{"runs":{},"lock_held_ms":{},"readers_served":{},"readers_failed":{},"max_read_wait_ms":{},"backfill_batches":{}}}"#,
        escape(name), s.runs, round2(s.lock_held_ms), s.readers_served,
        s.readers_failed, round2(s.max_read_wait_ms), s.backfill_batches
    )
}

fn diagnostics() -> String {
    let m = METRICS.lock().unwrap();
    let b = m.get("blocking").map(|s| variant_json("blocking", s)).unwrap_or_default();
    let e = m.get("expand_contract").map(|s| variant_json("expand_contract", s)).unwrap_or_default();
    drop(m);
    format!(
        r#"{{"stack":"{}","case":"{CASE_NAME}","variants":{{{},{}}},"migration":{},"interpretation":{{"blocking":"readers_failed > 0 y max_read_wait_ms = la duracion entera de la migracion: la app estuvo caida todo ese tiempo aunque el proceso siguiera vivo.","expand_contract":"readers_failed = 0 y max_read_wait_ms = lo que dura UN lote. El trabajo total es el mismo; lo que cambia es como se reparte.","rust_note":"La std no trae RwLock con deadline: solo read() infinito o try_read() instantaneo. El timeout se arma con un spin acotado, que consume CPU en vez de dormir — es el unico caso del lab donde la respuesta de Rust es peor que la de los otros seis. A cambio, los guards sueltan el lock en su Drop: no existe el camino de salida que olvida el unlock."}}}}"#,
        escape(&stack()), b, e, migration_state()
    )
}

fn stack() -> String {
    std::env::var("APP_STACK").unwrap_or_else(|_| "Rust 1.83".to_string())
}

fn route(path: &str, params: &HashMap<String, String>) -> (u16, String) {
    let rows = params.get("rows").and_then(|v| v.parse::<usize>().ok()).unwrap_or(20000).clamp(1000, 500000);
    let readers = params.get("readers").and_then(|v| v.parse::<usize>().ok()).unwrap_or(8).clamp(1, 64);
    let ms_per_1k = params.get("ms_per_1k").and_then(|v| v.parse::<u64>().ok()).unwrap_or(20).clamp(1, 200);
    let batch = params.get("batch").and_then(|v| v.parse::<usize>().ok()).unwrap_or(2000).clamp(100, 100000);
    let pause_ms = params.get("pause_ms").and_then(|v| v.parse::<u64>().ok()).unwrap_or(5).clamp(0, 200);

    match path {
        "/" | "/index" => (
            200,
            format!(
                r#"{{"case":"{CASE_NAME}","stack":"{}","rust_specific":"La std no trae RwLock con deadline: el timeout del lector es un spin acotado sobre try_read(). A cambio, los guards sueltan el lock en su Drop.","routes":["/health","/migrate-blocking?rows=20000&readers=8","/migrate-expand-contract?rows=20000&readers=8&batch=2000&pause_ms=5","/migration/state","/backfill?batch=2000","/diagnostics/summary","/reset-lab"]}}"#,
                escape(&stack())
            ),
        ),
        "/health" => (
            200,
            format!(r#"{{"status":"ok","stack":"{}","case":"{CASE_NAME}"}}"#, escape(&stack())),
        ),
        "/migrate-blocking" => (200, run_migration("blocking", rows, readers, ms_per_1k, batch, pause_ms)),
        "/migrate-expand-contract" => (200, run_migration("expand_contract", rows, readers, ms_per_1k, batch, pause_ms)),
        "/migration/state" => (200, migration_state()),
        "/backfill" => (200, backfill_step(batch, ms_per_1k)),
        "/diagnostics/summary" => (200, diagnostics()),
        "/reset-lab" => {
            reset_table(rows);
            *METRICS.lock().unwrap() = fresh_metrics();
            (200, r#"{"status":"reset","message":"Tabla, fase y metricas reiniciadas."}"#.to_string())
        }
        _ => (404, format!(r#"{{"error":"Ruta no encontrada","path":"{}"}}"#, escape(path))),
    }
}

// ---------- capa HTTP minima ----------

fn main() {
    let port: u16 = std::env::var("PORT").ok().and_then(|p| p.parse().ok()).unwrap_or(8080);
    let listener = TcpListener::bind(("0.0.0.0", port)).expect("bind");
    println!("[case17-rust] listening on {port}");
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

fn percentile(sorted: &[f64], pct: usize) -> f64 {
    if sorted.is_empty() {
        return 0.0;
    }
    let idx = ((pct * sorted.len() + 99) / 100).saturating_sub(1).min(sorted.len() - 1);
    round2(sorted[idx])
}

fn escape(v: &str) -> String {
    v.replace('\\', "\\\\").replace('"', "\\\"")
}
