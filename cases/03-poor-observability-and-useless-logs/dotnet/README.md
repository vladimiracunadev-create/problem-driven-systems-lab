# 🔵 Caso 03 — .NET 8

<!-- nav-stack -->
[⬅️ Caso 03](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md) · [🔵 Perfil de .NET](../../../docs/languages/dotnet.md) · [🧬 Todos los perfiles](../../../docs/languages/README.md)
<!-- /nav-stack -->

Stack .NET operativo del caso 03. Contraste entre logs opacos (`Console.WriteLine` sin contexto) vs estructurados con correlation ID propagado por el pipeline async.

## Primitivas .NET nativas

| Primitiva | Rol |
|---|---|
| `AsyncLocal<RequestContext>` | Contexto de correlation ID que fluye por `await` sin propagar manualmente. Equivalente moderno del `ThreadLocal` Java en codigo async. |
| `Guid.NewGuid()` | Generacion de `correlation_id` por request. |
| `System.Text.Json` (BCL) | Logs estructurados serializados sin libreria externa. |
| `record RequestContext` | Snapshot inmutable del contexto. |

## Contraste

**Legacy** — log sin contexto:
```csharp
Console.WriteLine("[INFO] processing checkout");
if (total > 500) {
    Console.WriteLine("[ERROR] checkout failed");  // sin id, sin total, sin razon
}
```

**Observable** — correlation ID + campos estructurados:
```csharp
CTX.Value = new RequestContext(corrId, "checkout-observable", DateTime.UtcNow.ToString("o"));
StructuredLog("error", "checkout_failed", new Dictionary<string,string> {
    ["total"]  = total.ToString(),
    ["reason"] = "exceeds_limit",
    ["limit"]  = "500"
});
// → {"ts":"...","level":"error","event":"checkout_failed","correlation_id":"<guid>",
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
docker compose -f compose.dotnet.yml up -d --build
curl "http://127.0.0.1:8500/03/checkout-observable?total=600"
curl http://127.0.0.1:8500/03/logs
```

## Modo aislado

```
docker compose -f cases/03-poor-observability-and-useless-logs/dotnet/compose.yml up -d --build
curl http://127.0.0.1:853/health
```

## Por que `AsyncLocal<T>` y no `ThreadLocal<T>`

`ThreadLocal<T>` no sobrevive a un `await` que retoma en otro thread del `ThreadPool`. `AsyncLocal<T>` es la primitiva que el CLR sigue por `ExecutionContext` a traves de cada `await`. Es exactamente el equivalente del `ScopedValue` JDK21 o de `contextvars` en Python — codifica "el contexto sigue al flujo logico, no al thread fisico". Para un pipeline HTTP async es la unica opcion correcta.
