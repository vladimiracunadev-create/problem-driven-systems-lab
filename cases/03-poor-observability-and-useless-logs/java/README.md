# ☕ Caso 03 — Java 25.0.5

<!-- nav-stack -->
[⬅️ Caso 03](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [☕ Perfil de Java](../../../docs/languages/java.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack Java operativo del caso 03. Contraste entre logs opacos (`println` sin contexto) vs estructurados con correlation ID propagado.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `ScopedValue<RequestContext>` | Contexto de correlation ID inmutable y acotado al handler. API final desde JDK 25, sin preview flags ni limpieza manual. |
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
RequestContext ctx = new RequestContext(corrId, "checkout-observable", Instant.now().toString());
ScopedValue.where(CTX, ctx).run(() ->
    structuredLog("error", "checkout_failed", Map.of(
        "total", String.valueOf(total),
        "reason", "exceeds_limit",
        "limit", "500")));
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

## Por que `ScopedValue` y no `ThreadLocal`

`ScopedValue` quedo final en JDK 25. La vinculacion existe solo durante la llamada a
`ScopedValue.where(...).call(...)`, es inmutable y el runtime restaura el alcance
anterior al salir, incluso si hay una excepcion. No existe un `remove()` que olvidar ni
un valor que pueda quedar pegado al siguiente request cuando el pool reutiliza el
thread. `ThreadLocal` queda documentado como la alternativa historica y mutable que
este caso ya no recomienda.
