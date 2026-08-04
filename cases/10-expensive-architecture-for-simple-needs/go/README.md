# 🐹 Caso 10 — Go 1.23

<!-- nav-stack -->
[⬅️ Caso 10](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🐹 Perfil de Go](../../../docs/languages/go.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Go operativo del caso 10. N hops con serializacion en cada uno vs un lookup directo.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `strings.Builder` | Construccion de buffers sin copias intermedias. `String()` no copia el buffer final. |
| `map[string]int64` de solo lectura | El "right-sized". Se llena una vez al arrancar, asi que no necesita lock. |
| `sync/atomic` | Contadores por variante. |

## Contraste

**Complex** — el payload viaja por N servicios y cada uno lo serializa:
```go
for h := 0; h < hops; h++ {
    var hop strings.Builder
    hop.Grow(2048)
    for i := 0; i < 200; i++ { hop.WriteByte(byte('A' + (i % 26))) }
    payload.WriteString(hop.String())
}
// cost_usd_month_est = hops * 25 · lead_time_days = hops * 2
```

**Right-sized** — un lookup:
```go
value, found := directStore[key]
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
docker compose -f compose.go.yml up -d --build
curl "http://127.0.0.1:8600/10/feature-complex?key=feature-1&hops=8"
curl "http://127.0.0.1:8600/10/feature-right-sized?key=feature-1"
```

## Por que este caso no compara milisegundos entre lenguajes

El costo aca es CPU puro: construir y recorrer buffers. `strings.Builder` garantiza cero copias al convertir a string (reinterpreta el buffer interno); el `toString()` de Java copia el array. Por eso el numero absoluto de Go sale sistematicamente mas bajo que el de Java o .NET para el mismo trabajo nominal.

Eso **no** significa que Go sea el stack correcto para este caso. Significa que comparar `elapsed_ms` entre stacks aca no dice nada util.

Lo que si es comparable, y es el punto del caso, es **la forma de la curva dentro de cada stack**: lineal en `hops` para la variante compleja, constante para la right-sized. La sobrearquitectura se paga en pendiente, no en constante — y esa pendiente es identica en los seis lenguajes.
