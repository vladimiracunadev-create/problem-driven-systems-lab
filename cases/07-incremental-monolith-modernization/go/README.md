# Caso 07 — Go 1.23

Stack Go operativo del caso 07. Cambio acoplado en el monolito vs strangler con tabla de routing por consumer.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `type handlerFunc func(request) response` | La firma **es** el tipo. Sin `Function<,>` de Java ni `Func<,>` de C#. |
| `map[string]handlerFunc` | Tabla de routing mutable en runtime. Registrar una migracion es una linea. |
| `sync.RWMutex` | Lecturas concurrentes sin estorbarse; escritura exclusiva solo al registrar una migracion. |

## Contraste

**Legacy** — el cambio toca el `shared_schema` y propaga a los 4 modulos:
```go
"blast_radius_score": 4,
"risk_score":         8,
```

**Strangler** — la tabla decide, el monolito no se toca:
```go
routingTable["billing:change"] = func(req request) response {
    return response{Result: "ok-new-module", RoutedTo: "new-billing-svc", BlastRadiusScore: 1, RiskScore: 1}
}

if handler, ok := lookupHandler(consumer + ":" + op); ok {
    r := handler(request{...})     // modulo nuevo
} else {
    // fallback al monolito, acotado por ACL: blast radius 2 en vez de 4
}
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/change-legacy?consumer=billing&op=change` | `blast_radius_score: 4`, `risk_score: 8` |
| `/change-strangler?consumer=billing&op=change` | `routed_to: new-billing-svc`, blast radius `1` |
| `/change-strangler?consumer=orders&op=change` | `routed_to: legacy-monolith`, blast radius `2` (aun no migrado) |
| `/flows` | progreso de migracion por modulo + tamaño de la tabla |
| `/diagnostics/summary` | llamadas por variante y cuantas fueron al modulo nuevo |
| `/reset-lab` | reinicia contadores |

## Hub

```
docker compose -f compose.go.yml up -d --build
curl "http://127.0.0.1:8600/07/change-strangler?consumer=billing&op=change"
curl "http://127.0.0.1:8600/07/change-strangler?consumer=orders&op=change"
```

## Funciones como tipo, y por que importa en un strangler

El corazon de un strangler es el **ACL** — la capa que traduce el contrato viejo al nuevo mientras conviven. Un ACL es, literalmente, una funcion que envuelve a otra.

En Java eso se escribe `Function<Request, Response>`; en C#, `Func<Request, Response>`. Ambos son tipos genericos de biblioteca envolviendo el concepto. En Go el tipo se declara con la firma:

```go
type handlerFunc func(request) response
```

La consecuencia practica es donde falla el error. Registrar un handler con la firma equivocada es un error de compilacion **en el punto de registro** — cuando escribis la migracion, no cuando el primer request del consumer migrado llega a produccion un martes.

`sync.RWMutex` en vez de `sync.Map` porque el patron de acceso es asimetrico: se lee en cada request y se escribe una vez por migracion. RWMutex deja entrar a todos los lectores en paralelo; `sync.Map` esta optimizado para el caso contrario.
