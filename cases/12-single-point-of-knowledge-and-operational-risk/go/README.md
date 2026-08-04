# 🐹 Caso 12 — Go 1.23

<!-- nav-stack -->
[⬅️ Caso 12](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐹 Perfil de Go](../../../docs/languages/go.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Go operativo del caso 12. Incidente con owner ausente que revienta vs runbook codificado que degrada de forma controlada.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| comma-ok (`v, ok := m[k]`) | La ausencia esta en el **tipo de retorno**. No se puede usar el valor sin recibir tambien el booleano. |
| `recover()` en un `defer` | Ultimo recurso: impide que un panic de un incidente tumbe el proceso entero. |
| `sync.Mutex` + `sync/atomic` | Estado de owners/incidentes y contadores de coverage y bus factor. |

## Contraste

**Legacy** — acceso ciego. `pickOwnerLegacy` devuelve `*owner` y **nada obliga a comprobarlo**:
```go
o := pickOwnerLegacy(scenario)   // nil si el owner esta ausente
script := o.Runbook[runbookKey]  // panic: nil pointer dereference
```

El `recover()` es lo unico que evita que el proceso muera:
```go
defer func() {
    if rec := recover(); rec != nil {
        atomic.AddInt64(&legacyCrashed, 1)
        result = map[string]any{"status": "crashed", "reason": fmt.Sprintf("panic: %v", rec), ...}
    }
}()
```

**Distributed** — la ausencia viaja en la firma:
```go
func pickOwnerDistributed(scenario string) (*owner, bool)

o, hasOwner := pickOwnerDistributed(scenario)
if hasOwner {
    if s, ok := o.Runbook[runbookKey]; ok { script = s }
}
// script vacio → runbook compartido del equipo. Sin panic posible.
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
docker compose -f compose.go.yml up -d --build
curl "http://127.0.0.1:8600/12/incident-legacy?scenario=owner_absent"
curl "http://127.0.0.1:8600/12/share-knowledge?owner=bob"
curl "http://127.0.0.1:8600/12/incident-distributed?scenario=owner_absent"
```

## comma-ok en vez de `Optional<T>`

Java resuelve esto con `Optional<T>` y encadenamiento `map/flatMap/orElse`; Node con optional chaining `?.`. Go no tiene ninguna de las dos, y la ausencia es el punto.

Go codifica "puede no haber valor" en el **tipo de retorno**: `script, ok := owner.Runbook[key]`. No hay forma de obtener `script` sin obtener tambien `ok` — el chequeo queda en el sitio de uso, no escondido detras de un metodo encadenado. Un `Optional` mal usado (`.get()` sin `isPresent()`) compila perfectamente y explota en runtime; aca la ausencia es parte de la asignacion.

Lo que Go **no** evita es el modo de falla real: un puntero nil desreferenciado hace panic, igual que un NPE en Java. La variante legacy lo demuestra a proposito. La leccion operativa es que `recover()` en un `defer` es el equivalente del catch de ultimo recurso — y que un incidente mal manejado no deberia poder llevarse el proceso puesto.
