# Caso 03 — Java 21

Stack Java operativo del caso 03. Contraste entre logs opacos (`println` sin contexto) vs estructurados con correlation ID propagado.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `ThreadLocal<RequestContext>` | Contexto de correlation ID propagado durante el handler. Equivalente a `ScopedValue` de JDK 21 sin requerir preview flags. |
| `UUID.randomUUID()` | Generacion de `correlation_id` por request. |
| Estructurado JSON inline | Sin libreria de logging externa: build manual con `StringBuilder` para mantener el lab single-file. |

## Contraste

**Legacy** — log sin contexto:
```java
System.out.println("[INFO] processing checkout");
if (total > 500) {
    System.out.println("[ERROR] checkout failed");  // sin id, sin total, sin razon
}
```

**Observable** — correlation ID + campos estructurados:
```java
CTX.set(new RequestContext(corrId, "checkout-observable", Instant.now().toString()));
structuredLog("error", "checkout_failed", Map.of(
    "total", String.valueOf(total),
    "reason", "exceeds_limit",
    "limit", "500"));
// → {"ts":"...","level":"error","event":"checkout_failed","correlation_id":"<uuid>",
//    "route":"checkout-observable","total":"600.0","reason":"exceeds_limit","limit":"500"}
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/checkout-legacy?total=600` | log opaco a stdout, sin id |
| `/checkout-observable?total=600` | log estructurado + `correlation_id` en respuesta y en `/logs` |
| `/logs` | ultimos 200 logs estructurados (JSON) |
| `/diagnostics/summary` | contraste de requests/errors entre variantes |
| `/reset-lab` | limpia logs y contadores |

## Hub

```
docker compose -f compose.java.yml up -d --build
curl "http://127.0.0.1:8400/03/checkout-observable?total=600"
curl http://127.0.0.1:8400/03/logs
```

## Por que ThreadLocal y no ScopedValue

`ScopedValue` (JDK 21) es la API moderna recomendada por Loom. Aqui se usa `ThreadLocal` porque (a) requiere menos flags de compilacion, (b) el resultado observable es el mismo: contexto propagado dentro del handler, limpiado en `finally`. Para produccion real con `Executors.newVirtualThreadPerTaskExecutor()` la migracion a `ScopedValue` es ~10 lineas.
