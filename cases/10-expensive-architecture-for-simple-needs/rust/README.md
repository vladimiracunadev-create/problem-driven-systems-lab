# 🦀 Caso 10 — Rust 1.83

<!-- nav-stack -->
[⬅️ Caso 10](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🦀 Perfil de Rust](../../../docs/languages/rust.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Rust operativo del caso 10. N hops con serializacion en cada uno vs un lookup directo.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `String::with_capacity` + `push_str` | Construccion de buffers sin realocaciones intermedias. |
| `LazyLock<HashMap<String,i64>>` | El "right-sized": se construye una vez, solo se lee, no necesita lock. |
| `AtomicI64` | Contadores por variante. |

## Contraste

**Complex** — el payload viaja por N servicios y cada uno lo serializa:
```rust
for h in 0..hops {
    let mut hop = String::with_capacity(2048);
    for i in 0..200 { hop.push((b'A' + (i % 26)) as char); }
    payload.push_str(&hop);
}
// cost_usd_month_est = hops * 25 · lead_time_days = hops * 2
```

**Right-sized** — un lookup:
```rust
let value = DIRECT_STORE.get(key).copied();
// cost_usd_month_est = 3 · lead_time_days = 1
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/feature-complex?key=feature-1&hops=8` | `payload_bytes`, coste estimado y lead time crecientes con `hops` |
| `/feature-complex?...&hops=25` | `internal_timeout` — la sobrearquitectura se cae sola |
| `/feature-right-sized?key=feature-1` | mismo resultado, coste constante |
| `/decisions` | los ADR que justifican no sobrearquitecturar todavia |
| `/diagnostics/summary` | llamadas y timeouts por variante |
| `/reset-lab` | reinicia contadores |

## Hub

```
docker compose -f compose.rust.yml up -d --build
curl "http://127.0.0.1:8700/10/feature-complex?key=feature-1&hops=8"
curl "http://127.0.0.1:8700/10/feature-right-sized?key=feature-1"
```

## Por que este caso no compara milisegundos entre lenguajes

El costo aca es CPU puro. Rust lo paga con `String::with_capacity` + `push_str`, sin asignaciones intermedias ocultas y sin GC que despues recoja la basura generada. Es previsible que el numero absoluto salga entre los mas bajos de los siete stacks.

Y por eso mismo vale repetir lo que dice el caso en todos los lenguajes: **comparar `elapsed_ms` entre stacks aca no dice nada util.**

Lo comparable es la forma de la curva **dentro** de cada stack: lineal en `hops` para la variante compleja, constante para la right-sized. Esa pendiente es identica en los siete lenguajes, porque la sobrearquitectura no es un problema de runtime sino de diseño. Un lenguaje rapido no arregla ocho saltos de red que no hacian falta — solo hace que tarden menos en no hacer falta.

Verificado: con `hops=8`, `payload_bytes` da **1719**, exactamente el mismo valor que el stack Go. El trabajo nominal es identico; lo que cambia es cuanto cuesta hacerlo.
