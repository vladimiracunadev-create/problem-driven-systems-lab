# Caso 04 — Comparativa PHP vs Python: Timeout chain y retry storms

## El problema que ambos resuelven

Una API de cotización que depende de un proveedor externo inestable. La variante legacy reintenta agresivamente sin límites, amplificando la carga. La variante resilient usa timeout corto, backoff exponencial con jitter, circuit breaker y fallback cacheado.

---

## PHP: socket bloqueante, usleep, circuit breaker con strtotime

**Runtime:** PHP-FPM. Cada worker tiene su propio proceso. Un timeout que bloquea un proceso FPM lo deja inaccesible para otras requests durante toda la espera.

**El fallo legacy en PHP:**
```php
for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    // timeout_ms=360, backoff_base_ms=0 — sin espera entre intentos
    $result = $this->simulateProviderCall($scenario, $timeoutMs);
    if ($result['success']) break;
    // Sin backoff: siguiente intento inmediato
}
```
Para `provider_down`: 4 intentos × 360ms = 1440ms mínimo por request. El proceso FPM queda bloqueado durante ese tiempo. Bajo carga concurrente, el pool de FPM se agota y las nuevas requests esperan en cola.

**La corrección en PHP:**
```php
private function calculateBackoffMs(int $baseMs, int $attempt): int {
    return (int)(($baseMs * (2 ** max(0, $attempt - 1))) + random_int(15, 45));
}

// Circuit breaker: evaluación antes del I/O
if (isset($provider['opened_until']) &&
    strtotime($provider['opened_until']) > time()) {
    return $this->buildFallbackResponse($provider);
}
```
`2 ** n` escala exponencialmente. `random_int(15, 45)` añade jitter para desfasar picos. `strtotime()` convierte el timestamp ISO8601 a Unix para comparación. Si el circuito está abierto, retorna el fallback **antes** de iniciar cualquier I/O.

**Estado del circuit breaker en PHP:** persiste en `/tmp/pdsl-case04-php/dependency_state.json`. PHP no tiene estado compartido entre procesos FPM, así que el estado se persiste en disco y se lee al inicio de cada request.

---

## Python: time.sleep, random.randint, threading.Lock, circuit breaker con time.time

**Runtime:** `ThreadingHTTPServer`. Los hilos comparten estado en memoria. `time.sleep()` libera el GIL, permitiendo que otros hilos progresen durante la espera.

**El fallo legacy en Python:**
```python
for attempt in range(max_attempts):  # max_attempts=4, timeout_ms=360
    result = simulate_provider_call(scenario, timeout_ms)
    if result["success"]:
        break
    # backoff_base_ms=0: siguiente intento inmediato
```
Para `provider_down`: 4 × 0.36s = 1.44s por request. `time.sleep()` libera el GIL durante la espera, pero el hilo sigue ocupado y no puede atender otras requests.

**La corrección en Python:**
```python
def calculate_backoff_ms(base_ms: int, attempt: int) -> int:
    return int(base_ms * (2 ** max(0, attempt - 1)) + random.randint(15, 45))

# Circuit breaker: evaluación con time.time() antes del I/O
if provider.get("opened_until") and time.time() < provider["opened_until"]:
    return build_fallback_response(provider)
```
Misma fórmula de backoff que PHP. La diferencia está en la comparación del circuit breaker: PHP usa `strtotime()` para convertir ISO8601 a Unix; Python almacena directamente el timestamp Unix (`time.time()`) en el JSON y lo compara directamente. Más simple, sin conversión.

**Estado del circuit breaker en Python:** persiste en `/tmp/pdsl-case04-python/dependency_state.json`. A diferencia de PHP, los hilos podrían leer el estado en memoria sin tocar disco, pero la persistencia en JSON garantiza que el estado sobreviva reinicios del servidor.

---

## Diferencias de decisión, no de corrección

| Aspecto | PHP | Python | Razon |
|---|---|---|---|
| Espera (sleep) | `usleep($us)` | `time.sleep(s)` | API diferente, mismo efecto: bloquear el worker durante el timeout. |
| Jitter | `random_int(15, 45)` | `random.randint(15, 45)` | Misma distribución uniforme. Nombres de función casi idénticos. |
| Timestamp CB | `strtotime($isoString) > time()` | `time.time() < provider["opened_until"]` | PHP requiere conversión desde ISO8601. Python almacena Unix timestamp directamente. |
| Estado compartido | Disco (un proceso por request) | Disco + opcionalmente en memoria | PHP necesita disco porque los procesos FPM son aislados. Python podría usar estado en memoria pero usa disco para consistencia. |
| Fallback quote | JSON en disco | JSON en disco | Idéntico. El fallback debe sobrevivir reinicios. |

**El algoritmo que ambos implementan es idéntico:** exponential backoff con jitter, circuit breaker con ventana de tiempo fija, y fallback al último valor conocido. PHP y Python llegan a la misma lógica desde diferentes APIs de stdlib.
