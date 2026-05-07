# 🔐 SECURITY

> **TL;DR.** Este laboratorio está diseñado para correr en **localhost**. El código aplica defensas razonables (validación por allowlist, prepared statements, paths fijos, sin shell exec), pero **no implementa autenticación ni rate limiting** porque ese no es su propósito. Exponerlo en LAN o Internet sin un reverse proxy con auth es un error de despliegue, no del código.

---

## 🧭 Modelo de amenaza explícito

La postura de seguridad cambia drásticamente según dónde corra el lab. Esta es la frontera honesta:

| Escenario | Riesgo realista | Acción esperada |
|---|---|---|
| **Localhost only** (default — `docker compose up` en tu máquina) | Bajo. El atacante necesita acceso físico o ya está dentro. | Ninguna. Es el caso de uso pensado. |
| **LAN corporativa / VM con puerto expuesto en `0.0.0.0`** | Medio. Cualquier máquina del segmento puede llamar `/reset-lab`, `/share-knowledge`, generar ruido en métricas, intentar DoS del event loop (caso 11). | Limitar exposición o agregar reverse proxy con auth básica (nginx, caddy, traefik). |
| **Expuesto a Internet sin proxy con auth** | **Alto/Crítico.** Sin auth, sin rate limiting, sin TLS. No hay datos sensibles que exfiltrar, pero sí superficie para abuso (DoS, llenar disco con state JSON, contaminar telemetría). | **No lo hagas.** Si lo necesitás, ver checklist al final. |

---

## ✅ Lo que el lab garantiza (defensas activas)

Estas están en el código y funcionan hoy. Verificadas por revisión manual:

| Vector típico | Cómo se defiende | Dónde mirar |
|---|---|---|
| **SQL injection** | PDO prepared statements en los casos `01`/`02` PHP. Los `IN(...)` se construyen con `placeholderList()` que solo emite `?, ?, ?` count-based; los IDs vienen siempre de `(int) $row['customer_id']` (server-side, nunca del request). | [`cases/01-api-latency-under-load/php/app/bootstrap.php:105`](cases/01-api-latency-under-load/php/app/bootstrap.php:105) |
| **Inyección de scenario / consumer / domain** | Allowlist estricto en los 12 casos: `if (!ALLOWED.includes(x)) x = default`. Equivalente con `in_array(...)` en PHP. | Todos los casos |
| **Validación de release / SKU** | Regex allowlist: `/^[A-Za-z0-9._-]{3,32}$/`, `/^[A-Z0-9-]{4,20}$/`. | Casos `06` y `09` |
| **Numeric clamping** | `clampInt(value, min, max)` en TODOS los enteros que llegan por query params (rows, accounts, orders, limit, etc.). | Todos los casos |
| **Path traversal en storage** | `STORAGE_DIR = path.join(os.tmpdir(), 'pdsl-caseXX-node')` — string fijo, sin user input. Idem en Python (`tempfile.gettempdir()`) y PHP (`sys_get_temp_dir()`). | Todos los casos |
| **Path traversal en hub** | `caseId` validado contra el `CASES` whitelist antes de armar la URL al backend interno. `subPath` se URL-decoded por `new URL()` y los `..` colapsan antes del routing. | [`node-dispatcher/app/main.js`](node-dispatcher/app/main.js), [`python-dispatcher/app/main.py`](python-dispatcher/app/main.py) |
| **HTTP header injection (CRLF)** | `http.get` de Node y `urllib.request.urlopen` de Python rechazan caracteres CRLF crudos en URLs como protección nativa del runtime. | Dispatchers |
| **Subprocess spawning con input** | Los dispatchers spawnean **paths fijos** del `CASES` dict al arrancar el proceso. El `caseId` del usuario solo selecciona del dict, nunca se concatena en un path ni en un argv. | [`node-dispatcher/app/main.js:54-67`](node-dispatcher/app/main.js:54), Python idem |
| **JSON.parse de state corrupto** | `try { ... } catch (_e) { return initialState(); }` — falla cerrada hacia un estado seguro conocido en lugar de propagar el error. | Todos los casos |
| **Prototype pollution en lookup de cases** | `Object.prototype.hasOwnProperty.call(CASES, caseId)` evita que `__proto__` o `constructor` matcheen como case_id válido. | Hub Node |
| **IDs impredecibles** | `crypto.randomBytes(4).toString('hex')` (Node), `random_bytes()` (PHP), `secrets.token_hex()` (Python) para todos los `deployment_id`/`flow_id`/`incident_id`. No se pueden enumerar IDs externos. | Todos los casos |
| **SSRF en `probe.php`** | La URL del probe se construye desde `cases.json` (server-side, en disco), no desde input del cliente. El cliente solo selecciona `case_id` + `stack` del catálogo conocido. | [`portal/app/probe.php:71-75`](portal/app/probe.php:71) |
| **Cancelación de pipeline** | Caso `06` Node: `AbortSignal` propaga la cancelación del cliente — los pasos restantes nunca se ejecutan, evitando trabajo desperdiciado y race conditions. | [`cases/06-broken-pipeline-and-fragile-delivery/node/app/server.js`](cases/06-broken-pipeline-and-fragile-delivery/node/app/server.js) |
| **Sin `eval` / `Function()` / `exec` con input** | Verificado por grep en todos los stacks. Cero código dinámico desde request. | grep manual |
| **Sin shell exec con input** | `spawn(node, [server.js])` con args fijos, sin `shell: true`. Ningún `popen`/`os.system` recibe partes de query. | Dispatchers |

---

## ⚠️ Lo que el lab NO garantiza (frontera honesta)

Estas son ausencias **intencionales** (es un lab, no un servicio productivo) o limitaciones reconocidas:

### Hallazgos altos si se expone más allá de localhost

| # | Hallazgo | Impacto si se expone | Mitigación |
|---|---|---|---|
| **A1** | **Sin autenticación.** Ningún endpoint valida `Authorization`, cookie, CSRF token ni IP de origen. Cualquier cliente que alcance el host puede invocar `/reset-lab`, `/deploy-controlled`, `/share-knowledge`, etc. | Wipeo de telemetría, falsificación de deployments simulados, escrituras repetidas en `/tmp`. | Reverse proxy con auth básica/JWT/SSO delante de los hubs. O middleware `if (req.headers['x-pdsl-token'] !== process.env.LAB_TOKEN) return 401`. |
| **A2** | **DoS por bloqueo intencional del event loop (caso `11` Node).** El `blockEventLoop(ms)` hace `while (Date.now() < end) {}` sincrónico hasta 900ms por request. Es **pedagógico** (la gracia del caso es mostrar el bloqueo), pero permite DoS si se combina con concurrencia. | 10 requests concurrentes ≈ 9 segundos de unresponsiveness para los 12 casos del hub Node `:8300`. | Si hay carga concurrente real, usar el `compose.yml` per-case del caso 11 (`:8211`) en lugar del hub. Reducir el cap a 200ms. |

### Hallazgos medios

| # | Hallazgo | Impacto | Mitigación |
|---|---|---|---|
| **M1** | **Mutaciones aceptan cualquier verbo HTTP.** Los handlers comparan solo `uri === '/reset-lab'`, ignorando `req.method`. `DELETE /reset-lab` ejecuta lo mismo que `GET`. | Rompe la convención REST y reduce defensa en profundidad — un WAF que filtre solo `POST /reset-lab` no protegería. | Validar método: `(req.method === 'POST' && uri === '/reset-lab')` para mutaciones. |
| **M2** | **Reflejo del header `Host` en `probe.php`.** El header se inserta sin sanitizar en el `target_url` que vuelve en el JSON. No es SSRF (la fetch real usa `PDSL_PROBE_HOST` env), pero el valor reflejado podría usarse para phishing por copy-paste si la UI lo renderiza sin escape. | Bajo. | Validar `$_SERVER['HTTP_HOST']` contra allowlist (`localhost`, `127.0.0.1`, `host.docker.internal`). |
| **M3** | **Sin rate limiting ni throttling.** Un cliente puede spamear cualquier endpoint. En particular, caso `05` `/batch-legacy?documents=200&payload_kb=512` infla `legacyRetained` hasta `LEGACY_HARD_CAP=2000` × payload (~120 MB en RAM). | DoS local; saturación de memoria del contenedor. | `nginx limit_req` delante del hub PHP (ya hay nginx en `compose.root.yml`). Para Python/Node, middleware basado en `Map<ip, [timestamps]>`. |
| **M4** | **Estado en `/tmp` sin atomicidad.** Dos requests concurrentes que muten el mismo `state.json` pueden corromperlo en Python/PHP. En Node es seguro entre `await`s (single-thread). | Pérdida silenciosa de estado del lab — el `try/catch` cae a `initialState()`, así que no escala a otra cosa. | Escribir a `state.json.tmp` y `rename` atómico. |

### Observaciones / hallazgos bajos

- **Sin TLS.** HTTP plano, apropiado para localhost. Cualquier exposición externa requiere reverse proxy con TLS (caddy/traefik/nginx con Let's Encrypt).
- **Métricas Prometheus sin auth.** `/metrics-prometheus` expone counters, latencias y estado del breaker. Estándar para Prometheus, pero permite reconocimiento. OK localhost-only.
- **Sin headers de seguridad** (`X-Content-Type-Options: nosniff`, `X-Frame-Options`, CSP). Para un API JSON puro tiene bajo impacto, pero el portal y los casos PHP devuelven HTML cuando ven `Accept: text/html` — ahí sí importa.
- **CORS sin configurar.** Default browser-side block. OK para el lab. Si se quiere blindar, agregar `Access-Control-Allow-Origin: null` explícito.
- **`docker logs` filtra info del proceso.** El hub Node imprime PIDs y puertos internos al arrancar. Solo accesible si el atacante ya tiene acceso al host.

---

## 🛡️ Si vas a exponerlo más allá de localhost — checklist mínimo

Antes de hacer `docker compose up` con `0.0.0.0:PORT` en cualquier red no privada, debe estar TODO esto:

- [ ] **Reverse proxy con TLS** delante de los hubs (caddy / traefik / nginx + Let's Encrypt). El lab nunca habla HTTPS por sí mismo.
- [ ] **Autenticación** en el reverse proxy (basic auth, JWT, OIDC, lo que aplique). Mínimo basic auth con credenciales no triviales.
- [ ] **Rate limiting** en el reverse proxy. Sugerencia: 10 req/seg por IP para endpoints de lectura, 1 req/seg para mutaciones.
- [ ] **Bloquear `/reset-lab`** en producción a nivel del proxy si no querés que nadie lo dispare.
- [ ] **Bind explícito** del compose: `127.0.0.1:8100:8080` en lugar de `8100:8080` para que el contenedor no quede expuesto en la interfaz pública del host. (⚠️ Esto rompe `probe.php` del portal — ver nota abajo.)
- [ ] **Headers de seguridad** en el proxy: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Content-Security-Policy: default-src 'self'`.
- [ ] **Logs centralizados** (acceso + errores) para detectar abuso temprano.

### Nota sobre el bind localhost-only

Bindear los hubs a `127.0.0.1` es la forma "correcta" de blindar el host, pero **rompe `probe.php`** del portal: ese mecanismo accede a los hubs vía `host.docker.internal:PORT` desde dentro del contenedor del portal, y `host.docker.internal` no resuelve a `127.0.0.1` desde un contenedor. Para arreglarlo correctamente hay que mover el portal a la misma red Docker que los hubs y resolver por DNS interno (nombre del contenedor) en lugar de puertos del host. **No está implementado todavía.**

---

## 🚨 Reporte responsable

Si encontrás una vulnerabilidad o exposición sensible:

1. **No abras un issue público** con el detalle completo.
2. Usá GitHub Security Advisories (botón "Report a vulnerability" en la pestaña Security del repo) o contactá al responsable del repositorio.
3. Incluí: pasos de reproducción, impacto potencial estimado, recomendación de mitigación si la tenés.

Tiempo de respuesta esperado: **48 horas hábiles** para acuse de recibo, **2 semanas** para confirmación o descarte del hallazgo. Esto es un lab personal, no un proyecto con on-call.

---

## ✅ Versiones soportadas

| Rama | Estado |
| --- | --- |
| `main` | soportada |

No hay versionado semántico todavía. Los cambios de seguridad se documentan en [`CHANGELOG.md`](CHANGELOG.md) bajo `### Security`.

---

## ⚠️ Credenciales locales (sin sorpresas)

Los casos `01` y `02` usan credenciales de PostgreSQL locales en `compose.yml` para reproducibilidad. Eso es aceptable solo porque se trata de un laboratorio local. **Nunca reutilices esos valores en servidores reales** ni los tomes como patrón de producción.

El caso `06` Node tiene un `SECRETS = { API_KEY: '12345' }` hardcoded como **valor de demo** para que `getSecretReal('DB_PASSWORD')` falle de forma reproducible y muestre el comportamiento del pipeline frente a secretos faltantes. **No es un secreto real**; es deliberadamente trivial.

---

## 🧭 Principios de seguridad del repositorio

- **No subir secretos, tokens ni credenciales reales** al repositorio.
- Tratar `.env.example` como referencia y no como lugar para valores sensibles.
- **Docker ayuda a reproducir entornos**, pero no reemplaza el hardening — quien expone un contenedor más allá de localhost asume la responsabilidad del despliegue.
- **Honestidad de madurez sobre apariencia de robustez**: este documento prefiere admitir lo que falta antes que prometer paridad con un servicio productivo.
