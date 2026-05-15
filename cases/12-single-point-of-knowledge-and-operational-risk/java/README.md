# Caso 12 — Java 21

Stack Java operativo del caso 12. `Optional<T>` + chaining seguro como runbook codificado.

## Primitivas nativas

| Primitiva | Rol |
|---|---|
| `Optional<T>` + `map`/`flatMap`/`orElse` | Runbook codificado: el lenguaje obliga a manejar el caso "owner ausente". Espejo del optional chaining `?.` de Node. |
| `record Owner/Incident` | Inmutables — auditable, copy-on-update. |
| `AtomicInteger coverage / busFactor` | Metricas observables actualizables thread-safe via `/share-knowledge`. |
| `ConcurrentHashMap<String, Owner>` | Registry de owners thread-safe. |

## Contraste

**Legacy** — acceso ciego a estructura anidada:
```java
Owner owner = pickOwnerLegacy(scenario);     // null si owner_absent
String script = owner.runbook().get(...);    // NPE
String executed = script.toUpperCase();       // NPE en cadena
// → catch: mttr 120 min, crashed
```

**Distributed** — `Optional` + chaining defensivo:
```java
Optional<Owner> ownerOpt = pickOwnerDistributed(scenario);   // empty si ausente
Optional<String> scriptOpt = ownerOpt.map(o -> o.runbook().get(runbookKey));
String script = scriptOpt.orElse(null);
// degradacion controlada: usa runbook compartido por equipo → mttr 35-50 min
```

## Rutas

| Ruta | Que muestra |
|---|---|
| `/health` | liveness |
| `/incident-legacy?scenario=owner_absent&runbook=db_failover` | crashed:NullPointerException, mttr=120 |
| `/incident-distributed?scenario=owner_absent&runbook=db_failover` | handled via team runbook, mttr=35-50 |
| `/share-knowledge?owner=bob&runbook=db_failover` | coverage sube +15, bus_factor +1 |
| `/incidents` | historial reciente (max 30) |
| `/diagnostics/summary` | contraste + coverage + bus_factor |

## Hub

```
docker compose -f compose.java.yml up -d --build
# Legacy crashea
curl "http://127.0.0.1:8400/12/incident-legacy?scenario=owner_absent&runbook=db_failover"
# Distributed degrada controlado
curl "http://127.0.0.1:8400/12/incident-distributed?scenario=owner_absent&runbook=db_failover"
# Compartir conocimiento sube bus_factor
curl "http://127.0.0.1:8400/12/share-knowledge?owner=bob&runbook=db_failover"
curl http://127.0.0.1:8400/12/diagnostics/summary    # coverage 45, bus_factor 2
```

## Modo aislado

Puerto `8412`.

## Por que `Optional` y no null checks manuales

Es la misma decision que `?.` en Node, `?` en Kotlin, `??` en C#: **codificar la posibilidad de ausencia en el sistema de tipos**, no en disciplina del developer. Un `Optional<Owner>` obliga a tomar postura ante el caso vacio; un `Owner owner` no. El crash del legacy no es una falla de Java — es una falla de **no usar las herramientas que Java ya ofrece**.
