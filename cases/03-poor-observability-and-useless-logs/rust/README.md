# Caso 03 — Rust 1.83

Stack Rust operativo del caso 03. Logs opacos vs estructurados con correlation ID propagado.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `struct RequestCtx` prestado por `&` | Contexto de request. Se pasa por referencia, no por almacenamiento ambiente. |
| Lifetimes del borrow checker | Una referencia al contexto **no puede almacenarse** en una estructura de vida mas larga. |
| `Mutex<Vec<String>>` | Buffer de los ultimos 200 eventos servidos por `/logs`. |
| `AtomicI64` | Contadores por variante. |

## Contraste

**Legacy** — log opaco. La funcion **no recibe contexto**, y esa es la señal:
```rust
fn checkout_legacy(total_raw: &str) -> String {   // sin ctx
    println!("[INFO] processing checkout");
    if total > 500.0 {
        println!("[ERROR] checkout failed");       // sin id, sin total, sin causa
    }
}
```

**Observable** — el contexto se crea en el handler y se presta a quien loguea:
```rust
fn structured_log(ctx: &RequestCtx, level: &str, event: &str, fields: &[(&str, &str)])

let ctx = RequestCtx { correlation_id: new_correlation_id(), route: "checkout-observable" };
structured_log(&ctx, "error", "checkout_failed",
    &[("total", &fmt_num(total)), ("reason", "exceeds_limit"), ("limit", "500")]);
// → {"ts":"...","level":"error","event":"checkout_failed","correlation_id":"...",
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
docker compose -f compose.rust.yml up -d --build
curl "http://127.0.0.1:8700/03/checkout-observable?total=600"
curl http://127.0.0.1:8700/03/logs
```

## Referencia prestada vs contexto ambiente

Java propaga con `ThreadLocal`, .NET con `AsyncLocal`, Node con `AsyncLocalStorage`. Los tres son **almacenamiento ambiente**: la funcion lee un valor que alguien dejo en el hilo. Go lo hace explicito pasando `context.Context` como parametro.

Rust va un paso mas: el contexto se presta como `&RequestCtx`, y el borrow checker **impide que esa referencia sobreviva al handler**. Guardarla en una estructura de vida mas larga no compila.

Eso cierra una categoria de bug concreta: en los modelos ambiente, un contexto que sobrevive a su request —porque el thread se reutiliza y nadie limpio el `ThreadLocal`— hace que los logs del usuario siguiente lleven el `correlation_id` del anterior. Es un bug silencioso y desagradable de auditar. Aca no se puede escribir.

**Contrapartida honesta:** `std` no trae logger estructurado. Go tiene `log/slog` en la biblioteca estandar desde 1.21; en Rust el ecosistema usa `tracing` o `log`, y para mantener el caso sin dependencias el JSON se arma con `format!` a mano. Es menos ergonomico y no hay razon para pretender lo contrario.
