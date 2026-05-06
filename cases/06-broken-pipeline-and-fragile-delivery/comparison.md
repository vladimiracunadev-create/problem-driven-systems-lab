# Caso 06 — Comparativa multi-stack: Pipeline roto y entrega frágil (PHP · Python · Node.js)

## El problema que ambos resuelven

Un pipeline de despliegue hacia dev/staging/prod. La variante legacy detecta los problemas tarde, después de haber mutado el ambiente. La variante controlled valida antes de tocar el ambiente y hace rollback automático si algo falla post-switch.

---

## PHP: RuntimeException, class_exists, jerarquía de excepciones nativa

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

## Python: KeyError nativo, excepciones estructuradas, contextlib

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

## Node.js: AbortController + AbortSignal cooperativo, cancelacion nativa

**Runtime:** Node.js 20 single-thread con event loop. El servidor http vive como un proceso largo, exactamente como Python. Cada request engancha un `AbortController` cuyo `signal` se propaga por todos los pasos asincronicos del pipeline.

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

## Diferencias de decisión, no de corrección

| Aspecto | PHP | Python | Node.js | Razon |
|---|---|---|---|---|
| Cancelacion del pipeline | Implicita: el proceso muere por request en FPM | `threading.Event` o flag manual | `AbortController` + `AbortSignal` propagado | Solo Node tiene una primitiva estandar de cancelacion en stdlib. |
| Detección de clave ausente | `class_exists()` + `isset()` | `dict.get()` con default | `Object.prototype.hasOwnProperty.call(o, k)` | PHP/Node validan explicitamente; Python evita el lanzamiento con `.get()`. |
| Jerarquía de excepciones | `extends RuntimeException` con `readonly` | `class DeploymentBlocked(Exception)` | `class DeploymentBlocked extends Error` | Tres formas, mismo objetivo. Node hereda de `Error` global. |
| Rollback | `$env = $previous` (por referencia) | `env["current_release"] = previous` | `env.current_release = previousRelease` | Mismo efecto en los tres. |

**El patron que los tres demuestran es idéntico:** validar antes de mutar, rollback si el post-switch falla. Lo distintivo de Node: el `AbortSignal` propagado convierte la cancelacion del cliente en cancelacion del pipeline sin codigo de glue — la primitiva ya existe en el lenguaje.
