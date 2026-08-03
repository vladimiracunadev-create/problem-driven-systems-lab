# Caso 03 — Go 1.23

Stack Go operativo del caso 03. Logs opacos vs estructurados con correlation ID propagado.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `context.Context` | Propagacion del correlation ID con alcance de request. **Parametro explicito**, no almacenamiento ambiente. |
| `ctxKey struct{}` | Clave privada del paquete: nadie de afuera puede colisionar con ella en el `Context`. Convencion Go. |
| `log/slog` | Logger estructurado de la stdlib desde Go 1.21. Unico stack del lab donde el JSON logging no requiere libreria externa. |
| `crypto/rand` | Generacion del `correlation_id`. |

## Contraste

**Legacy** — log sin contexto. Notar que la funcion **no recibe `ctx`**, y esa es la señal:
```go
func checkoutLegacy(totalRaw string) map[string]any {   // sin ctx
    log.Printf("[INFO] processing checkout")
    if total > 500 {
        log.Printf("[ERROR] checkout failed")            // sin id, sin total, sin razon
    }
}
```

**Observable** — el `ctx` viaja como parametro y el logger lo exige:
```go
func structuredLog(ctx context.Context, level, event string, fields map[string]any)

ctx := withRequestContext(parent, requestContext{CorrelationID: corrID, Route: "checkout-observable"})
structuredLog(ctx, "error", "checkout_failed", map[string]any{
    "total": total, "reason": "exceeds_limit", "limit": 500,
})
// → {"ts":"...","level":"error","event":"checkout_failed","correlation_id":"<hex>",
//    "route":"checkout-observable","total":600,"reason":"exceeds_limit","limit":500}
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/checkout-legacy?total=600` | log opaco a stdout, sin id |
| `/checkout-observable?total=600` | log estructurado + `correlation_id` en respuesta y en `/logs` |
| `/logs` | ultimos 200 eventos estructurados |
| `/metrics` · `/diagnostics/summary` | contraste de requests/errors entre variantes |
| `/reset-lab` | limpia logs y contadores |

## Hub

```
docker compose -f compose.go.yml up -d --build
curl "http://127.0.0.1:8600/03/checkout-observable?total=600"
curl http://127.0.0.1:8600/03/logs
```

## Por que `context.Context` y no un ThreadLocal

Java usa `ThreadLocal`, .NET usa `AsyncLocal`, Node usa `AsyncLocalStorage`. Los tres son **contexto ambiente**: la funcion lee un valor que alguien dejo en el hilo, sin declararlo en su firma.

Go va al reves: el contexto es un parametro. Eso tiene una consecuencia operativa concreta —

- Una funcion que no recibe `ctx` **no puede** leer el `correlation_id` por accidente. La ausencia de trazabilidad es visible en la firma, no en un log vacio a las 3 AM.
- Cuando el trabajo salta de goroutine, el `ctx` tiene que pasarse explicitamente. En los modelos ambiente, saltar de thread pierde el contexto **en silencio**: el codigo compila, corre, y los logs simplemente dejan de tener id.

El costo es verbosidad: `ctx` aparece como primer parametro en media base de codigo. El beneficio es que perder la correlacion pasa de ser un bug de runtime a un error de compilacion.
