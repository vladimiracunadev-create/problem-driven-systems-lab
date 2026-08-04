# 🦀 Caso 12 — Rust 1.83

<!-- nav-stack -->
[⬅️ Caso 12](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🦀 Perfil de Rust](../../../docs/languages/rust.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Rust operativo del caso 12. Incidente con owner ausente que revienta vs runbook codificado que degrada de forma controlada.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `Option<T>` | La ausencia esta en el tipo de retorno de `pick_owner_*`. |
| Operador `?` | Propaga la ausencia hacia arriba sin escribir un solo `if`. |
| `match` exhaustivo sobre `Option` | Omitir el brazo `None` **no compila**. |
| `.unwrap()` + `panic::catch_unwind` | El atajo que convierte ausencia en panic, y el ultimo recurso que lo contiene. |

## Contraste

**Legacy** — `.unwrap()` es el atajo que convierte la ausencia en panic:
```rust
let owner  = pick_owner_legacy(&scenario_owned).unwrap();  // panic si no hay owner
let script = owner.runbook.get(&key_owned).unwrap();       // panic si no hay runbook
```

Contenido con el ultimo recurso:
```rust
let outcome = panic::catch_unwind(move || { ... });
match outcome {
    Ok((executed, mttr)) => { ... }
    Err(_) => { LEGACY_CRASHED.fetch_add(1, Ordering::Relaxed); ... }
}
```

**Distributed** — el operador `?` propaga la ausencia sin un solo `if`:
```rust
let script: Option<String> = (|| {
    let owner  = pick_owner_distributed(scenario)?;   // None → sale aca
    let script = owner.runbook.get(runbook_key)?;     // None → sale aca
    Some(script.clone())
})();

let (mttr, result) = match script {
    Some(_) => (rand_between(15, 10), "executed_by_primary"),
    None    => (rand_between(35, 15), "owner_absent_handled_via_team_runbook"),
};
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/incident-legacy?scenario=owner_absent&runbook=db_failover` | `status: crashed`, `mttr_min: 120` |
| `/incident-distributed?scenario=owner_absent&runbook=db_failover` | `status: handled`, MTTR mucho menor |
| `/share-knowledge?owner=bob&runbook=db_failover` | sube `coverage` +15 y `bus_factor` +1 |
| `/incidents` | historial de los ultimos 30 incidentes |
| `/diagnostics/summary` | incidentes por variante + coverage y bus factor |
| `/reset-lab` | vuelve a un solo owner y coverage 30 |

## Hub

```
docker compose -f compose.rust.yml up -d --build
curl "http://127.0.0.1:8700/12/incident-legacy?scenario=owner_absent"
curl "http://127.0.0.1:8700/12/share-knowledge?owner=bob"
curl "http://127.0.0.1:8700/12/incident-distributed?scenario=owner_absent"
```

Verificado: la variante legacy devuelve `panic: called Option::unwrap() on a None value`; la distributed devuelve `owner_absent_handled_via_team_runbook`; y `share-knowledge` sube coverage de 30 a 45 y bus factor de 1 a 2.

## `Option<T>` cierra el arco que abren los otros seis stacks

Los siete lenguajes resuelven la misma pregunta —"¿y si no hay owner?"— con herramientas distintas:

| Stack | Herramienta | Se puede ignorar el chequeo? |
|---|---|---|
| PHP / Python | `isset()` / `if x is None` | Si, olvidarlo es un `TypeError` en runtime |
| Node | optional chaining `?.` | Si, `undefined` se propaga en silencio |
| Java | `Optional<T>` | Si — `.get()` sin `isPresent()` compila |
| .NET | nullable reference types | Si, el chequeo es un warning, no un error |
| Go | comma-ok `v, ok := m[k]` | Si — `v, _ := m[k]` es legal |
| **Rust** | **`Option<T>` + `match` exhaustivo** | **No: omitir el brazo `None` no compila** |

Y el operador `?` hace que el camino correcto sea tambien el mas corto de escribir, que es la unica forma de que una convencion sobreviva a un equipo real.

**Lo que Rust NO hace:** impedir el atajo. `.unwrap()` existe, es una palabra, y convierte cualquier ausencia en un panic. Este caso lo usa a proposito en la variante legacy para demostrarlo. Un `.unwrap()` en codigo de produccion es exactamente el mismo olor que un `Optional.get()` sin `isPresent()` — la diferencia es que se puede grepear en una sola pasada, y que ningun linter serio de Rust lo deja pasar sin justificacion.
