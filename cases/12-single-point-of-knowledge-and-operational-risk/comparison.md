# Caso 12 — Comparativa PHP vs Python: Punto único de conocimiento y riesgo operacional

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

## Diferencias de decisión, no de corrección

| Aspecto | PHP | Python | Razon |
|---|---|---|---|
| Acceso defensivo | `$data['key'] ?? $default` | `data.get("key", default)` | Mismo efecto: valor por defecto si la clave no existe, sin excepción. |
| Tipado estricto | `declare(strict_types=1)` | No existe equivalente directo | PHP 8 tiene tipado estricto opt-in. Python tiene `mypy` / type hints pero no enforcement en runtime por defecto. |
| Función pura de score | Función PHP con `array` | Función Python con `dict` | Mismo concepto: sin efectos secundarios, entrada → salida determinista. |
| Estado compartido | Disco (JSON) | Disco (JSON) + variable de módulo | Ambos persisten en JSON. Python podría usar solo memoria pero usa disco para consistencia entre reinicios. |
| `declare(strict_types=1)` | Activa `TypeError` en mismatches | No aplica | PHP tiene esta opción de lenguaje. Python confía en type hints + herramientas externas como mypy. |

**El concepto es idéntico en ambos:** el conocimiento distribuido (runbooks + personas de backup + simulacros) convierte un sistema frágil que depende del héroe en uno que puede resolver incidentes de forma autónoma. La fórmula de `readinessScore` / `readiness_score` es la misma. La diferencia es que PHP usa `??` mientras Python usa `.get()`, y PHP tiene `declare(strict_types=1)` como contrato de tipado explícito que Python no tiene en runtime.
