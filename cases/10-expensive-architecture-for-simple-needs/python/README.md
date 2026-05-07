# 💸 Caso 10 — Python 3.12 con comparacion complex vs right-sized

> Implementacion operativa del caso 10 para contrastar sobrearquitectura contra una solucion proporcional.

## 🎯 Que resuelve

Modela decisiones de arquitectura sobre necesidades acotadas:

- `feature-complex` reparte el problema entre demasiadas capas y coordinaciones en memoria;
- `feature-right-sized` resuelve el mismo caso con acceso directo, sin capas intermedias y con una fraccion del costo computacional.

## 💼 Por que importa

Este caso deja visible que decir "no" a complejidad innecesaria tambien es una habilidad de arquitectura. El riesgo no es solo pagar mas: tambien se retrasa delivery, se multiplican los puntos de falla y se hace mas dificil de mantener. La complejidad correcta es la proporcional al problema.

## 🔬 Analisis Tecnico de la Implementacion (Python)

La sobre-arquitectura tiene un costo fisico medible en Python: cada capa adicional de serializacion, deserializacion e indirection agrega latencia de CPU aunque no haya I/O.

- **Complejidad Innecesaria (`complex`):** La funcion `run_complex_feature()` simula el overhead de una arquitectura multi-capa mediante un bucle de **hidratacion profunda**. En cada "hop" (event bus, rule engine, ORM, serializer), itera sobre miles de entidades ejecutando `json.dumps()` seguido de `json.loads()` y una conversion de dict a objeto con `type("Entity", (), item)()`. Esta redundancia de complejidad `O(N * hops)` consume CPU del worker sin producir ningun valor adicional respecto al resultado final. El `elapsed_ms` crece linealmente con el numero de capas configuradas, y `services_touched` registra cuantos "servicios" fueron involucrados en una operacion que podria resolverse en microsegundos.

- **Diseno Proporcional (`right_sized`):** Aplica acceso directo con **complejidad asintótica `O(1)`**. La funcion `run_right_sized_feature()` resuelve el valor del feature con un lookup directo: `FEATURE_STORE.get(feature_key, {}).get(context, default_value)`. Sin serializar, sin deserializar, sin iterar entidades, sin capas de coordinacion. El resultado es el mismo valor de negocio con una latencia de microsegundos en lugar de milisegundos. `services_touched` registra 1 en lugar de N, y `lead_time_ms` refleja el costo real de la operacion sin overhead arquitectonico.

## 🧱 Servicio

- `app` → API Python 3.12 con comparacion de latencia, servicios tocados y lead time entre ambos enfoques.

## 🚀 Arranque

```bash
docker compose -f compose.yml up -d --build
```

Puerto local: `840`.

## Como consumir (dos opciones)

**Hub Python (recomendado, 8200 en `compose.python.yml`):** este caso queda servido en `http://localhost:8200/10/...` junto a los otros 11 casos.

**Modo aislado (8310 en este `compose.yml`):** levanta solo este caso, util cuando la medicion necesita procesar limpio (sin otros casos compartiendo runtime).

## 🔎 Endpoints

```bash
curl http://localhost:8200/10/
curl http://localhost:8200/10/health
curl "http://localhost:8200/10/feature-complex?feature=dark_mode&user_id=42&context=mobile"
curl "http://localhost:8200/10/feature-right-sized?feature=dark_mode&user_id=42&context=mobile"
curl http://localhost:8200/10/architecture/state
curl "http://localhost:8200/10/decisions?limit=10"
curl http://localhost:8200/10/diagnostics/summary
curl http://localhost:8200/10/metrics
curl http://localhost:8200/10/metrics-prometheus
curl http://localhost:8200/10/reset-lab
```

## 🧪 Escenarios utiles

- `basic_crud` → deja claro el descalce entre complejidad y necesidad real.
- `small_campaign` → muestra costo extra por una solucion innecesariamente distribuida.
- `audit_needed` → ayuda a ver que auditable no significa obligatoriamente complejo.
- `seasonal_peak` → hace visible como la coordinacion excesiva puede fallar en momentos criticos.

## 🧭 Que observar

- como cambia `elapsed_ms` entre `feature-complex` y `feature-right-sized` para el mismo feature;
- cuantos `services_touched` y `hops` registra cada modo;
- si el resultado de negocio (el valor del feature) es identico entre ambos modos;
- como evoluciona `complexity_ratio` en `/diagnostics/summary` con llamadas repetidas.

## ⚖️ Nota de honestidad

No pretende reemplazar un analisis financiero real ni una plataforma distribuida completa. Si reproduce el trade-off central: complejidad operacional versus adecuacion real al problema de negocio. Un feature flag es un lookup de configuracion; no necesita event sourcing ni pipeline de serializacion.
