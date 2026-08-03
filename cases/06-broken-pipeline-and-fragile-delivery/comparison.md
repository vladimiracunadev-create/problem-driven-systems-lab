# Caso 06 — Comparativa multi-stack: Pipeline roto y entrega frágil (PHP · Python · Node.js · Java · .NET · Go · Rust)

> **TL;DR** — legacy deja el ambiente `degraded`; controlled bloquea en preflight o revierte a la version previa. Lo que separa a los stacks es **si el estado no contemplado es un `else` silencioso o un error de compilacion**.

<!-- nav -->
`🐘 PHP` · `🐍 Python` · `🟢 Node.js` · `☕ Java 21` · `🔵 .NET 8` · `🐹 Go 1.23` · `🦀 Rust 1.83`

**Estructura:** 🎯 el problema → una seccion por stack → ⚖️ tabla de decision → 📊 primitiva por stack → 🏁 veredicto y ranking
<!-- /nav -->


## 🎯 El problema que ambos resuelven

Un pipeline de despliegue hacia dev/staging/prod. La variante legacy detecta los problemas tarde, después de haber mutado el ambiente. La variante controlled valida antes de tocar el ambiente y hace rollback automático si algo falla post-switch.

---

## 🐘 PHP: RuntimeException, class_exists, jerarquía de excepciones nativa

**Runtime:** PHP-FPM. Cada request ejecuta el pipeline completo en un solo proceso. Las excepciones de PHP son objetos con `getMessage()`, `getCode()`, `getTraceAsString()` — herramienta estándar para control de flujo con contexto.

**El fallo legacy en PHP:**
```php
function runLegacyDeployment(array &$env, string $release, string $scenario): array {
    // Muta el ambiente sin validar
    $env['current_release'] = $release;
    $env['health'] = 'deploying';

    // Si el escenario activa un error, falla DESPUÉS de haber mutado
    if ($scenario === 'missing_secret') {
        throw new RuntimeException(
            "Secret 'DB_PASSWORD' not found in environment"
        );
    }
    // El ambiente quedó en estado degraded: current_release fue cambiado
    // pero el deploy no completó
}
```
`RuntimeException` es la excepción genérica de PHP. El problema: se lanza **después** de haber mutado `$env['current_release']`. El ambiente queda inconsistente.

**La corrección en PHP:**
```php
function runControlledDeployment(array &$env, string $release, string $scenario): array {
    // Preflight: valida ANTES de mutar
    if ($scenario === 'missing_secret') {
        if (!class_exists('SecretManager') || !isset($config['db_password'])) {
            throw new DeploymentBlockedError(
                "Preflight failed: missing secret 'DB_PASSWORD'",
                stage: 'preflight'
            );
        }
    }
    // Solo si preflight pasa, mutamos el ambiente
    $previousRelease = $env['current_release'];
    $env['current_release'] = $release;

    // Post-switch: smoke test. Si falla, rollback atómico
    if ($smokeTestFails) {
        $env['current_release'] = $previousRelease;   // rollback
        $env['health'] = 'rollback';
    }
}
```
`class_exists()` e `isset()` son los mecanismos de validación defensiva de PHP. `DeploymentBlockedError` extiende `RuntimeException` con contexto de stage. El rollback es atómico: restaura `$previousRelease` en la misma variable de referencia.

**Jerarquía PHP:**
```php
class DeploymentBlockedError extends RuntimeException {
    public function __construct(string $message, public readonly string $stage) {
        parent::__construct($message);
    }
}
```

---

## 🐍 Python: KeyError nativo, excepciones estructuradas, contextlib

**Runtime:** `ThreadingHTTPServer`. El estado de los ambientes vive en un dict compartido protegido por `threading.Lock`. Las excepciones Python son objetos con atributos libremente definibles.

**El fallo legacy en Python:**
```python
def run_legacy_deployment(env: dict, release: str, scenario: str) -> dict:
    env["current_release"] = release   # Muta sin validar
    env["health"] = "deploying"

    config = scenario_config(scenario)
    if config.get("missing_secret"):
        # KeyError si la clave no existe — fallo no controlado
        secret = config["required_secrets"]["DB_PASSWORD"]  # KeyError aquí
```
Si `"required_secrets"` no existe en `config`, Python lanza `KeyError` de forma nativa. El ambiente ya fue mutado. El `except Exception` genérico captura el error pero no hace rollback.

**La corrección en Python:**
```python
class DeploymentBlocked(Exception):
    def __init__(self, message: str, stage: str):
        super().__init__(message)
        self.stage = stage

def run_controlled_deployment(env: dict, release: str, scenario: str) -> dict:
    config = scenario_config(scenario)

    # Preflight: .get() con default None — nunca lanza KeyError
    if config.get("missing_secret"):
        secret = config.get("required_secrets", {}).get("DB_PASSWORD")
        if not secret:
            raise DeploymentBlocked("Preflight: missing DB_PASSWORD", stage="preflight")

    previous = env.get("current_release")
    env["current_release"] = release

    if _smoke_test_fails(scenario):
        env["current_release"] = previous   # rollback atómico
        env["health"] = "rollback"
        raise DeploymentBlocked("Smoke test failed, rolled back", stage="smoke_test")
```
`.get()` nunca lanza `KeyError` — retorna `None` si la clave no existe. `DeploymentBlocked` lleva el `stage` donde falló. El rollback es inmediato sobre el dict compartido.

---

## 🟢 Node.js: AbortController + AbortSignal cooperativo, cancelacion nativa

**Runtime:** Node.js 22 single-thread con event loop. El servidor http vive como un proceso largo, exactamente como Python. Cada request engancha un `AbortController` cuyo `signal` se propaga por todos los pasos asincronicos del pipeline.

**El AbortSignal por paso:**
```javascript
const stepDelay = async (signal, baseMs) => {
  const elapsed = baseMs + Math.floor(Math.random() * 17) + 8;
  await new Promise((resolve, reject) => {
    const t = setTimeout(resolve, elapsed);
    signal.addEventListener('abort', () => {
      clearTimeout(t);
      reject(new Error('pipeline_aborted'));
    }, { once: true });
  });
  return elapsed;
};
```

**El handler engancha cancelacion cuando el cliente cierra:**
```javascript
const ac = new AbortController();
const onClose = () => ac.abort();
req.once('close', onClose);
try {
  result = await runControlledDeployment(environment, release, scenario, ac.signal);
} finally {
  req.removeListener('close', onClose);
}
```

Si el cliente desconecta o si pones un timeout encima (`setTimeout(()=>ac.abort(), 5000)`), los pasos restantes nunca se ejecutan — el `signal` se propaga por toda la cadena async sin polling de un flag global. Es el equivalente Node-nativo de un cancellation token: una primitiva del estandar (`AbortController` viene de la spec WHATWG/DOM), no una libreria.

**Preflight + rollback en Node:**
```javascript
let validationBlocked = false;
try {
  if (scenario === 'missing_secret') getSecretReal('DB_PASSWORD');
  else if (scenario === 'migration_risk') throw new Error('Migration pre-flight checksum missed');
} catch (e) {
  validationBlocked = true;
}
if (validationBlocked) return buildResult(409, { ...preflight_blocked }, ctx);

// Si el smoke falla post-switch, rollback atomico
if (scenario === 'failing_smoke') {
  env.current_release = previousRelease;
  env.health = 'healthy';
}
```

---

## ☕ Java 21: `record` types inmutables + `ConcurrentHashMap` por ambiente + state machine como guards

**Runtime:** JVM con thread pool. Los `record` types (`EnvState`, `Deployment`) son inmutables — cada deploy crea una nueva instancia, no muta la anterior. Esto descarta una clase entera de bugs de concurrencia.

**Motor de estado:** `ConcurrentHashMap<String, EnvState>` por ambiente (`staging`, `prod`). Lectura paralela sin lock; escrituras atomicas con `put`. El historial es un `Deque<Deployment>` sincronizado con cap=30.

**El fallo legacy en Java:**
```java
if (isBadScenario(scenario)) {
    environments.put(env, new EnvState(env, version, "degraded"));   // queda roto
    legacyBroken.increment();
    return /* "deployed_but_broken" */;
}
```
Sin preflight, sin smoke. Si el scenario es `secret_drift` o `breaking_change`, el ambiente queda `degraded` con la nueva version. El siguiente deploy heredara este estado.

**La correccion en Java:**
```java
EnvState before = environments.get(env);
if (scenario.equals("missing_artifact") || scenario.equals("secret_drift_detected")) {
    return /* blocked_in_preflight */;   // no toca environments
}
if (isBadScenario(scenario)) {
    controlledRollbacks.increment();
    return /* rolled_back_to_<before.version> */;   // environments[env] queda en before
}
environments.put(env, new EnvState(env, version, "healthy"));   // promote solo si smoke OK
```
Tres ramas explicitas: preflight bloquea (sin tocar estado), smoke falla (rollback al `before.version`), todo OK (promote). El historial registra las 3 con `Deployment` records — auditable.

**Por que `record` aqui:** `EnvState` y `Deployment` son value objects. `equals/hashCode/toString` auto-generados. Serializan directo a JSON sin mappers. Y siendo inmutables, el snapshot que captura `before` en preflight se mantiene aunque otro thread haga `environments.put()` paralelo.

---

## 🔵 .NET 8: record types + ConcurrentDictionary + rollback automatico

**Runtime:** .NET 8 sobre `HttpListener`. `ThreadPool` despachando handlers concurrentes. Estado por ambiente compartido entre threads → `ConcurrentDictionary` evita lock global.

**El fallo legacy en C#:**
```csharp
if (IsBadScenario(scenario)) {
    environments[env] = new EnvState(env, version, "degraded");
    Interlocked.Increment(ref legacyBroken);
    return /* "deployed_but_broken" */;
}
```
Empuja `version` y marca degraded — pero el ambiente quedo apuntando al binario nuevo. Si el cliente reintenta sin reset, sigue roto.

**La correccion en C#:**
```csharp
var before = environments.GetValueOrDefault(env, new EnvState(env, "v1.0.0", "healthy"));
if (scenario is "missing_artifact" or "secret_drift_detected") {
    Interlocked.Increment(ref controlledBlocked);
    return /* blocked_in_preflight */;   // no toca environments
}
if (IsBadScenario(scenario)) {
    Interlocked.Increment(ref controlledRollbacks);
    return /* rolled_back_to_<before.Version> */;   // environments[env] queda en before
}
environments[env] = before with { Version = version, Health = "healthy" };   // promote solo si smoke OK
```
Tres ramas explicitas — mismo patron que Java. `with`-expression sobre `record EnvState` es C# moderno (9+) para "clonar con cambios" sin mutar el original.

**Notas idiomaticas vs los otros stacks:**
- `record EnvState(string Name, string Version, string Health)` es 1:1 con el `record` Java.
- `is "missing_artifact" or "secret_drift_detected"` (C# 9 pattern matching) reemplaza `scenario.equals(...) || ...` Java o `if scenario in {...}` Python.
- `Interlocked.Increment(ref counter)` es el equivalente directo del `LongAdder` Java.
- `ConcurrentDictionary<string, EnvState>` reemplaza el `ConcurrentHashMap<String, EnvState>` Java.
- A diferencia de Node, .NET no tiene `AbortController` built-in con propagacion automatica desde HTTP, pero `CancellationToken` cumple el mismo rol cuando se pasa explicito desde el handler.

---

---

## 🐹 Go 1.23: mutex sobre estructura explicita, no `sync.Map`

Go tiene `sync.Map`, el analogo directo del `ConcurrentHashMap` que usa Java aca. **No se usa, a proposito.**

La seccion critica de este caso no es "leer o escribir una clave": es **leer la version actual, decidir si promover o revertir, y escribir el resultado**. Eso es una transaccion logica. Un mapa concurrente la haria segura por operacion y aun asi incorrecta en conjunto — otro deploy puede colarse entre el read y el write, y el rollback revertiria a una version que ya no era la vigente.

```go
stateMu.Lock()
defer stateMu.Unlock()
before := environs[env]            // leer
if isBadScenario(scenario) { ... } // decidir
environs[env] = envState{...}      // escribir
```

El mutex hace visible que el invariante es la secuencia completa. Es el mismo razonamiento que hace que `ConcurrentHashMap` tampoco alcance en Java; la diferencia es que en Go la estructura no sugiere lo contrario.

---

## 🦀 Rust 1.83: los estados del pipeline son un `enum`, y el `match` es exhaustivo

En Java, .NET, Go y Node el resultado de este pipeline es un **string** (`"rolled_back"`, `"promoted"`). Agregar un estado nuevo —digamos `canary`— no rompe nada: cae al `else` de algun `if` y se comporta como si fuera otra cosa.

```rust
enum DeployOutcome {
    Deployed,
    DeployedButBroken,
    BlockedInPreflight { current_version: String },
    RolledBack { to_version: String },
    Promoted,
}
```

El `match` que construye la respuesta es exhaustivo. Si mañana alguien agrega `DeployOutcome::Canary`, **todos los `match` que no la contemplen dejan de compilar** — el compilador enumera los sitios a revisar.

Para una maquina de estados de deploy esa diferencia no es estetica: el estado no contemplado es precisamente el que deja produccion a medio camino.

## ⚖️ Diferencias de decision, no de correccion

> Los siete stacks implementan el **mismo algoritmo**. Esta tabla contrasta como lo expresa cada uno.

| Aspecto | PHP | Python | Node.js | Java | .NET | Go | Rust | Razon |
|---|---|---|---|---|---|---|---|---|
| Tipo del resultado | string | string | string | string (con `record` para el estado) | string | string | **`enum` con datos asociados** | Solo Rust convierte el resultado en un tipo cerrado. |
| Estado nuevo no contemplado | cae al `else` | cae al `else` | cae al `else` | cae al `else` | cae al `else` | cae al `else` | **no compila** | El estado olvidado es el que deja produccion a medio camino. |
| Seccion critica | disco entre procesos | lock explicito | single-thread | `ConcurrentHashMap` | `ConcurrentDictionary` | **`sync.Mutex` sobre la transaccion completa** | `Mutex<Option<State>>` | Un mapa concurrente es seguro por operacion y aun asi incorrecto en conjunto. |
| Rollback | releer version previa | idem | idem | `record` inmutable | `with`-expression | copia del valor previo | variante `RolledBack { to_version }` | En Rust la version a la que se revierte viaja dentro del propio resultado. |

**El patron que los siete stacks demuestran es idéntico** (y estos tres, en detalle): validar antes de mutar, rollback si el post-switch falla. Lo distintivo de Node: el `AbortSignal` propagado convierte la cancelacion del cliente en cancelacion del pipeline sin codigo de glue — la primitiva ya existe en el lenguaje.

---

## 📊 Primitiva central por stack

> Los siete stacks resuelven el mismo problema. Lo que cambia es la primitiva y donde duele.

| Stack | Primitiva central en este caso |
|---|---|
| PHP | estado en disco/DB entre procesos |
| Python | `dict` protegido por lock |
| Node.js | objeto en memoria, single-thread |
| Java 21 | `ConcurrentHashMap<String,EnvState>` + `record` |
| .NET 8 | `ConcurrentDictionary` + maquina de estados |
| Go 1.23 | `sync.Mutex` sobre la **transaccion completa**, no `sync.Map` por operacion |
| Rust 1.83 | **`enum` con datos asociados + `match` exhaustivo: agregar variante rompe la compilacion** |

---

## 🏁 Veredicto: que stack resuelve mejor **este** problema

> ⚠️ **Ranking de fit, no de calidad de lenguaje.** Mide que tan directamente las primitivas nativas del runtime expresan la solucion de *este* caso concreto. El orden cambia — a veces se invierte — de un caso a otro: leer varios rankings juntos dice mas que cualquiera por separado.

| | Stack | Por que |
|---|---|---|
| 🥇 | **Rust 1.83** | Los estados son un `enum` y el `match` es exhaustivo: agregar `Canary` mañana **rompe la compilacion** de todo sitio que no lo contemple. |
| 🥈 | **Java 21 / .NET 8** | `record` types inmutables y `with`-expressions para el rollback. Buen modelado; el resultado sigue siendo un string. |
| 🥉 | **Go 1.23** | `sync.Mutex` sobre la transaccion completa es la decision correcta —un mapa concurrente seria seguro por operacion e incorrecto en conjunto— pero el resultado tambien es un string. |
| 4º | **Python 3.12 / Node.js 22** | Estado en memoria con lock o single-thread. Correcto y sin red de seguridad de tipos. |
| 6º | **PHP 8.3** | Estado en disco entre procesos aislados: funciona, pero la transaccion logica no esta protegida por nada. |

**Lectura honesta:** Un deploy es una maquina de estados. El estado que nadie contemplo es exactamente el que deja produccion a medias — y es el unico caso donde un compilador puede avisarte antes.
