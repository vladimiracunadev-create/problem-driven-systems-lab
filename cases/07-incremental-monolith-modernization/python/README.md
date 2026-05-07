# 🏗️ Caso 07 — Python 3.12 con modernizacion incremental comparada

> Implementacion operativa del caso 07 para contrastar un cambio sobre un monolito acoplado contra una ruta strangler con migracion gradual.

## 🎯 Que resuelve

Modela cambios sobre un dominio critico con dos enfoques:

- `change-legacy` toca demasiados modulos y mantiene alto el blast radius;
- `change-strangler` usa ACL, contratos y migracion progresiva por consumidor.

## 💼 Por que importa

La modernizacion incremental no es solo una preferencia arquitectonica: es una forma de bajar riesgo real mientras el negocio sigue operando. Un cambio que toca 8 modulos puede romperse en cualquiera de ellos; un cambio que cruza por un ACL aislado solo puede romperse en la frontera controlada.

## 🔬 Analisis Tecnico de la Implementacion (Python)

El acoplamiento de un monolito se modela en Python como dependencias directas entre dicts compartidos, donde una mutacion en cualquier punto puede romper todos los consumidores activos.

- **Impacto Expandido (`legacy`):** La funcion `run_legacy_change()` accede directamente a multiples modulos del estado compartido mediante referencias a claves del mismo `dict` global: `monolith["billing"]`, `monolith["inventory"]`, `monolith["notifications"]`, etc. Al simular que un equipo elimina un modulo (`del monolith["shared_session"]`), cualquier codigo posterior que intente acceder a esa clave lanza un `KeyError` inmediato sin posibilidad de aislamiento. El `modules_touched` registra todos los modulos afectados por cada cambio, haciendo visible el blast radius: un cambio de precio puede tocar 6-8 modulos distintos.

- **Progresion por Consumidor (`strangler`):** Aplica el patron **Anti-Corruption Layer (ACL)** mediante un objeto mediador `billing_adapter` que encapsula el acceso al dominio de precios. El progreso de la migracion se gestiona por consumidor en `state["migration"]["consumers"][consumer]`, permitiendo que Python desvie el trafico hacia el nuevo modulo solo para consumidores que ya pasaron el contrato de migracion. Los consumidores no migrados siguen usando el camino legacy sin regresion. `acl_translations` registra cuantas traducciones de contrato realizo el ACL en cada llamada, haciendo visible el costo de la capa de compatibilidad.

## 🧱 Servicio

- `app` → API Python 3.12 con progreso por consumidor, blast radius y metricas de cobertura del modulo extraido.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `837` (modo aislado, ver opciones abajo).

## Como consumir (dos opciones)

**Hub Python (recomendado, 8200 en `compose.python.yml`):** este caso queda servido en `http://localhost:8200/07/...` junto a los otros 11 casos.

**Modo aislado (837 en este `compose.yml`):** levanta solo este caso, util cuando la medicion necesita procesar limpio (sin otros casos compartiendo runtime).

## 🔎 Endpoints

```bash
curl http://localhost:8200/07/
curl http://localhost:8200/07/health
curl "http://localhost:8200/07/change-legacy?product_id=P-001&new_price=99.99&reason=promo"
curl "http://localhost:8200/07/change-strangler?product_id=P-001&new_price=99.99&reason=promo"
curl http://localhost:8200/07/migration/state
curl "http://localhost:8200/07/flows?limit=10"
curl http://localhost:8200/07/diagnostics/summary
curl http://localhost:8200/07/metrics
curl http://localhost:8200/07/metrics-prometheus
curl http://localhost:8200/07/reset-lab
```

## 🧪 Escenarios utiles

- `billing_change` → cambio frecuente con alto acoplamiento en legacy.
- `shared_schema` → evidencia por que el ACL importa en una transicion.
- `parallel_work` → muestra el costo de coordinar todo el monolito frente a una frontera mas clara.

## 🧭 Que observar

- cuantos modulos toca cada enfoque (`modules_touched` en legacy vs `acl_translations` en strangler);
- como cambia el `blast_radius_score` entre ambos modos;
- si sube el progreso por consumidor (`consumers_migrated`) cuando se usa la ruta incremental;
- como evoluciona `migration_pct` en `/migration/state` con llamadas repetidas a strangler.

## ⚖️ Nota de honestidad

No reemplaza un monolito real ni un programa completo de replatforming. Si reproduce lo importante para discutir modernizacion segura: acoplamiento, ACL, migracion por consumidor y reduccion gradual del radio de impacto.
