# 🐹 Caso 17 — Go 1.23

<!-- nav-stack -->
[⬅️ Caso 17](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐹 Perfil de Go](../../../docs/languages/go.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Go del caso 17. Un `ALTER TABLE` bloqueante contra expand-contract, con lectores golpeando la tabla mientras tanto.

## `sync.RWMutex`: lo más simple del set, con una carencia concreta

`RLock`/`RUnlock` para lectores, `Lock`/`Unlock` para el escritor. Cuatro métodos, cero configuración. Y **sin hambruna de escritor**: un escritor bloqueado impide que entren lectores nuevos, que es lo que Java necesita pedir con el flag de equidad y Python resuelve con una bandera a mano.

**Pero no tiene `RLock` con timeout.** Go trae `TryRLock()` desde 1.18, que devuelve inmediatamente sin esperar nada; no hay forma de decir «esperá hasta 120 ms y después rendite».

Así que el deadline del lector hay que armarlo:

```go
got := make(chan struct{})
go func() { rw.RLock(); close(got) }()
select {
case <-got:      return true
case <-timer.C:  go func() { <-got; rw.RUnlock() }(); return false
}
```

## Y ahí aparece el detalle que solo se ve escribiéndolo

**El lector se rindió; su goroutine no.** La goroutine que quedó esperando el `RLock` sigue ahí hasta que el lock se suelte — por eso hay que dejarle una segunda goroutine que lo libere cuando llegue.

En una migración larga eso es una **fuga de goroutines proporcional al tráfico**. Funciona, es idiomático, y tiene un costo que ninguna de las otras implementaciones paga.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `sync.RWMutex` | El lock. Simple, y sin hambruna de escritor. |
| goroutine + `select` + `time.NewTimer` | El deadline que `RWMutex` no trae. |
| `chan struct{}` cerrado | La largada común de los lectores. |

## Las cuatro fases, y por qué ese orden

1. **Expand** — agregar la columna nullable. Es metadata: instantáneo.
2. **Backfill** — rellenar por lotes, soltando el lock entre cada uno.
3. **Switch** — un feature flag cambia lecturas y escrituras a la columna nueva.
4. **Contract** — recién ahora, en un despliegue posterior, se borra la vieja.

**El switch va antes del contract** porque el flag es lo único reversible en un segundo. Si se borra la columna vieja primero, volver atrás requiere otra migración — y a esa altura ya no hay a dónde volver.

## Rutas

| Ruta | Qué muestra |
|---|---|
| `/health` | liveness |
| `/migrate-blocking?rows=20000&readers=8` | `readers_failed` > 0 y `longest_single_lock_ms` = la migración entera |
| `/migrate-expand-contract?rows=20000&readers=8&batch=2000&pause_ms=5` | `readers_failed` = 0 y el lock más largo = un lote |
| `/migration/state` | fase actual, progreso del backfill y estado del feature flag |
| `/backfill?batch=2000` | un lote suelto, para ver el efecto de a uno |
| `/diagnostics/summary` | acumulado por variante |
| `/reset-lab` | vuelve la tabla al esquema viejo |

**Parámetros:** `rows` (1k–500k), `readers` (1–64 lectores concurrentes), `ms_per_1k` (costo de migrar mil filas), `batch` (tamaño de lote), `pause_ms` (pausa entre lotes).

## Hub

```bash
docker compose -f compose.go.yml up -d --build
curl "http://127.0.0.1:8600/17/migrate-blocking?rows=20000&readers=8"
curl "http://127.0.0.1:8600/17/migrate-expand-contract?rows=20000&readers=8&batch=2000&pause_ms=5"
curl "http://127.0.0.1:8600/17/migration/state"
```

## Lo que ningún stack cambia

`lock_held_ms` es prácticamente el mismo en las dos variantes. **El trabajo no desaparece: se reparte.**

Lo que decide si la aplicación se cae no es el tiempo total sino `longest_single_lock_ms` — y esa es la métrica que casi nunca está en el plan de migración.
