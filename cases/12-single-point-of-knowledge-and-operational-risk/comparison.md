# Caso 12 — Comparativa multi-stack: Punto único de conocimiento y riesgo operacional (PHP · Python · Node.js · Java)

## El problema que ambos resuelven

Un sistema de respuesta a incidentes donde el conocimiento crítico está concentrado en una sola persona (héroe). La variante legacy depende de que esa persona esté disponible. La variante distributed combina runbooks documentados, personas de backup y simulacros de incidentes para resolver sin el héroe.

---

## PHP: declare(strict_types), ErrorException, Null Coalescing, tipado defensivo

**Runtime:** PHP-FPM. El conocimiento tribal en PHP se modela como accesos a estructuras de datos implícitas sin validación. El tipado estricto de PHP 8 hace los errores más visibles y los contratos más explícitos.

**El fallo legacy en PHP — conocimiento implícito:**
```php
declare(strict_types=1);   // El tipado estricto activa TypeError en mismatches

function resolveIncidentLegacy(array $opaqueData, string $domain): array {
    // Acceso sin validación a estructura implícita — falla si no existe
    $active = $opaqueData['config']['system'][2]['is_active'];
    // Si la estructura no tiene ese path: PHP 8 lanza TypeError o Warning
    // Código que solo entiende quien lo escribió originalmente

    if (!isset($heroMap[$domain])) {
        throw new \RuntimeException("No hay experto para el dominio: $domain");
    }
    $hero = $heroMap[$domain];
    if (!$hero['available']) {
        return ['escalated' => true, 'mttr_min' => 180, 'reason' => 'hero_absent'];
    }
}
```
`$opaqueData['config']['system'][2]['is_active']` es un acceso con conocimiento tribal: solo el autor sabe qué estructura tiene. `declare(strict_types=1)` hace que un tipo incorrecto lance `TypeError` en lugar de hacer coerción silenciosa. El resultado es un sistema frágil que falla ruidosamente cuando el conocimiento no está documentado.

**La corrección en PHP — defensive typing + Null Coalescing:**
```php
function resolveIncidentDistributed(array $data, string $domain, array $knowledge): array {
    $readinessScore = calculateReadinessScore($knowledge);

    // Null Coalescing para acceso defensivo — nunca lanza TypeError
    $active = $data['system'][2]['is_active'] ?? false;
    $runbook = $knowledge['runbooks'][$domain] ?? null;
    $backup  = $knowledge['backup_people'][$domain] ?? [];

    if ($readinessScore >= 60 && $runbook !== null) {
        return [
            'resolved_without_hero' => true,
            'mttr_min' => (int)(45 * (1 - $readinessScore / 100)),
            'path' => 'runbook + backup',
        ];
    }
    return ['escalated' => true, 'mttr_min' => 180];
}

function calculateReadinessScore(array $knowledge): float {
    $runbookScore = $knowledge['runbook_score'] ?? 0;
    $backupPeople = count($knowledge['backup_people'] ?? []);
    $drillScore   = $knowledge['drill_score'] ?? 0;
    return $runbookScore * 0.45 + ($backupPeople + 1) * 18 + $drillScore * 0.25;
}
```
`??` en cada acceso garantiza que nunca se lanza `TypeError` o `Warning` por clave ausente. La fórmula de `readinessScore` convierte el conocimiento distribuido en un score numérico que determina si se puede resolver sin el héroe.

---

## Python: acceso con .get(), operador or, score como función pura

**Runtime:** `ThreadingHTTPServer`. El estado de conocimiento vive en un dict de módulo compartido entre hilos. `threading.Lock` protege las escrituras concurrentes.

**El fallo legacy en Python — conocimiento tribal:**
```python
def resolve_incident_legacy(opaque_data: dict, domain: str) -> dict:
    # Acceso con conocimiento implícito — falla si la estructura no existe
    active = opaque_data["config"]["system"][2]["is_active"]
    # → KeyError si cualquier nivel del path no existe
    # Solo el autor original sabe cómo está estructurado esto

    hero = HERO_MAP.get(domain)
    if not hero or not hero.get("available", False):
        return {"escalated": True, "mttr_min": 180, "reason": "hero_absent"}
```
`opaque_data["config"]["system"][2]["is_active"]` lanza `KeyError` en cualquier nivel si la clave no existe. En PHP el error es `TypeError` o `Warning`; en Python es `KeyError` — ambos igual de opacos para quien no conoce la estructura.

**La corrección en Python — .get() + función pura:**
```python
def readiness_score(knowledge: dict) -> float:
    """Convierte conocimiento distribuido en score numérico. Función pura."""
    runbook  = knowledge.get("runbook_score", 0)
    backups  = len(knowledge.get("backup_people", []))
    drills   = knowledge.get("drill_score", 0)
    return runbook * 0.45 + (backups + 1) * 18 + drills * 0.25

def resolve_incident_distributed(data: dict, domain: str, knowledge: dict) -> dict:
    score = readiness_score(knowledge)

    # .get() con defaults en cada nivel — nunca lanza KeyError
    active  = data.get("system", [{}]*3)[2].get("is_active", False)
    runbook = knowledge.get("runbooks", {}).get(domain)
    backup  = knowledge.get("backup_people", [])

    if score >= 60 and runbook is not None:
        return {
            "resolved_without_hero": True,
            "mttr_min": int(45 * (1 - score / 100)),
            "path": "runbook + backup",
        }
    return {"escalated": True, "mttr_min": 180}
```
`readiness_score()` es una función pura (sin efectos secundarios) que convierte el estado de conocimiento en un número. `.get()` con defaults en cada nivel garantiza que nunca se lanza `KeyError`. La fórmula es idéntica a PHP (`runbook * 0.45 + (backups + 1) * 18 + drills * 0.25`).

**`share-knowledge` en Python — sin telemetría de request:**
```python
elif uri == "/share-knowledge":
    # Operación administrativa: no registra latencia en telemetría
    # El MTTR y readiness_score cambian, no el p95 de la ruta
    knowledge = update_knowledge(query)
    payload = {"readiness_score": readiness_score(knowledge), ...}
    skip_store_metrics = True
```
Mismo criterio que PHP: `/share-knowledge` no contamina los percentiles de latencia de las rutas de incidente. Es una operación administrativa, no un flujo de negocio.

---

## Node.js: optional chaining `?.` como runbook codificado

**Runtime:** Node.js 20. La gracia del caso en Node es que el lenguaje tiene un operador (**optional chaining**, ECMAScript 2020) que codifica el runbook directamente — la diferencia entre legacy y distributed se reduce a tres caracteres.

**El fallo legacy en Node:**
```javascript
// Acceso ciego — equivalente a memoria tribal sin validacion
const opaque = {};
const _ = opaque.config.system[2].is_active;
// → TypeError: Cannot read properties of undefined (reading 'config')
```
Idéntico al PHP `Warning`/`TypeError` y al Python `KeyError`: el sistema rompe ruidosamente.

**La correccion distribuida en Node — el runbook **es** el operador:**
```javascript
// El runbook codificado en el lenguaje
const _ = opaque?.config?.system?.[2]?.is_active ?? false;
//             ^^^         ^^^         ^^^^^         ^^
//      "si no existe, default seguro y sigue"
```
`?.` evalua de izquierda a derecha y, si cualquier eslabon es `null`/`undefined`, retorna `undefined` sin lanzar. `??` provee el default. Es la encarnacion **en el lenguaje** de la regla "si no esta documentado, asume el default seguro y reporta" — tres caracteres que codifican una decision operacional.

**El score de readiness:**
```javascript
const readinessScore = (d) =>
  Math.round(((d.runbook_score || 0) * 0.45) + (((d.backup_people || 0) + 1) * 18) + ((d.drill_score || 0) * 0.25));

const shareKnowledge = (domain, activity) => {
  const state = readState();
  const d = state.knowledge.domains[domain];
  if (activity === 'runbook') d.runbook_score = Math.min(100, (d.runbook_score || 0) + 20);
  else if (activity === 'pairing') d.backup_people = Math.min(4, (d.backup_people || 0) + 1);
  else d.drill_score = Math.min(100, (d.drill_score || 0) + 18);
  writeState(state);
};
```
Misma formula que PHP/Python. La diferencia esta en como Node maneja el "si no existe": `||` (similar al `or` de Python — ojo con `0`).

---

## Java 21: `Optional<T>` + `map`/`flatMap`/`orElse` como runbook codificado

**Runtime:** El sistema de tipos obliga a tomar postura ante "owner ausente" cuando devolves `Optional<Owner>` en lugar de `Owner` (que puede ser null). El crash legacy no es falla de Java — es falla de no usar las herramientas que Java ya ofrece.

**El fallo legacy en Java:**
```java
Owner owner = pickOwnerLegacy(scenario);     // null si owner_absent
String script = owner.runbook().get(...);     // NPE
String executed = script.toUpperCase();        // NPE en cadena
// → catch: mttr 120 min, crashed
```

**La correccion en Java:**
```java
Optional<Owner> ownerOpt = pickOwnerDistributed(scenario);   // empty si ausente
Optional<String> scriptOpt = ownerOpt.map(o -> o.runbook().get(runbookKey));
String script = scriptOpt.orElse(null);
// degradacion controlada: usa team runbook → mttr 35-50 min
```

**Por que `Optional` y no null checks manuales:** misma decision que `?.` en Node, `?` en Kotlin, `??` en C#: codificar la posibilidad de ausencia en el sistema de tipos, no en disciplina del developer. `Optional<Owner>` obliga a manejar el caso vacio; `Owner owner` no.

---

## Diferencias de decisión, no de corrección

| Aspecto | PHP | Python | Node.js | Razon |
|---|---|---|---|---|
| Acceso defensivo | `$data['key'] ?? $default` | `data.get("key", default)` | `data?.key ?? default` | Tres formas, mismo efecto. JS combina chaining con coalescing. |
| Acceso anidado profundo | `$d['a']['b']['c'] ?? null` (todavia rompe en niveles intermedios undefined sin isset) | `d.get("a", {}).get("b", {}).get("c")` | `d?.a?.b?.c ?? null` (operador en cada nivel) | Solo Node tiene chaining nativo en cada nivel sin gimnasia. |
| Tipado estricto | `declare(strict_types=1)` | type hints + mypy externo | TypeScript opcional fuera del runtime | Tres aproximaciones. JS puro confia en el chaining defensivo. |
| Funcion pura de score | Funcion PHP con `array` | Funcion Python con `dict` | Arrow function con `||` | Tres formas, misma matematica. |
| Estado compartido | Disco (JSON) | Disco (JSON) + variable de modulo | Disco (JSON) | Persistencia cross-restart en los tres. |

**Lo distintivo de Node:** optional chaining (`?.`) hace el contraste legacy/distributed casi tipografico — la "ausencia de runbook" es visible en el codigo como ausencia del operador. PHP y Python lo aproximan con `??` y `.get()` pero con menos elegancia en estructuras anidadas profundas. La leccion operativa es la misma: **el conocimiento implicito siempre se cobra; el conocimiento codificado en defaults seguros sobrevive a la rotacion**.
