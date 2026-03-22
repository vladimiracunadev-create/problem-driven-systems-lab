from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse
from datetime import datetime, timezone
import json
import time

state = {
    'requests': 0,
    'samples_ms': [],
    'max_samples': 3000,
    'last_path': None,
    'last_status': 200,
    'last_updated': None,
}


def percentile(values, percent):
    if not values:
        return 0.0
    ordered = sorted(values)
    index = max(0, min(len(ordered) - 1, int((percent / 100) * len(ordered) + 0.9999) - 1))
    return round(float(ordered[index]), 2)


def payload_of_kb(kb):
    return 'x' * max(0, kb) * 1024


def cpu_work(iterations):
    value = 0
    for i in range(iterations):
        value += i % 13
    return value


def now_utc():
    return datetime.now(timezone.utc).isoformat()


class Handler(BaseHTTPRequestHandler):
    def _json(self, status, body):
        self.send_response(status)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.end_headers()
        self.wfile.write(json.dumps(body, ensure_ascii=False, indent=2).encode('utf-8'))

    def do_GET(self):
        start = time.perf_counter()
        parsed = urlparse(self.path)
        path = parsed.path
        query = parse_qs(parsed.query)
        status = 200

        try:
            if path == '/':
                body = {
                    'lab': 'Problem-Driven Systems Lab',
                    'case': '01 - API lenta bajo carga',
                    'stack': 'Python',
                    'goal': 'Simular endpoints rápidos y lentos para estudiar latencia, percentiles y comportamiento bajo carga.',
                    'recommended_flow': [
                        'Levantar un solo stack primero para entender el caso.',
                        'Usar compose.compare.yml solo cuando quieras comparar comportamientos.',
                        'Medir con /metrics antes y después de generar carga.',
                    ],
                    'routes': {
                        '/': 'Resumen del caso y rutas disponibles.',
                        '/health': 'Chequeo simple.',
                        '/fast': 'Respuesta rápida y liviana.',
                        '/slow?delay_ms=200&payload_kb=4': 'Simula latencia I/O y payload mayor.',
                        '/cpu?iterations=3500000': 'Simula trabajo CPU-bound.',
                        '/mixed?delay_ms=120&iterations=1500000&payload_kb=8': 'Combina espera, CPU y payload.',
                        '/metrics': 'Métricas acumuladas en memoria.',
                        '/reset-metrics': 'Reinicia contadores del caso.',
                    }
                }
            elif path == '/health':
                body = {'status': 'ok', 'stack': 'Python', 'case': '01 - API lenta bajo carga'}
            elif path == '/fast':
                body = {'endpoint': 'fast', 'message': 'Respuesta ligera diseñada para contrastar con rutas lentas.'}
            elif path == '/slow':
                delay_ms = max(0, int(query.get('delay_ms', ['250'])[0]))
                payload_kb = max(0, min(256, int(query.get('payload_kb', ['8'])[0])))
                time.sleep(delay_ms / 1000)
                body = {
                    'endpoint': 'slow',
                    'delay_ms': delay_ms,
                    'payload_kb': payload_kb,
                    'message': 'Esta ruta simula espera de red, I/O o dependencia externa.',
                    'payload': payload_of_kb(payload_kb),
                }
            elif path == '/cpu':
                iterations = max(1, min(20000000, int(query.get('iterations', ['3500000'])[0])))
                body = {
                    'endpoint': 'cpu',
                    'iterations': iterations,
                    'checksum': cpu_work(iterations),
                    'message': 'Esta ruta simula presión de CPU en una ruta crítica.',
                }
            elif path == '/mixed':
                delay_ms = max(0, int(query.get('delay_ms', ['120'])[0]))
                iterations = max(1, min(20000000, int(query.get('iterations', ['1500000'])[0])))
                payload_kb = max(0, min(256, int(query.get('payload_kb', ['12'])[0])))
                time.sleep(delay_ms / 1000)
                body = {
                    'endpoint': 'mixed',
                    'delay_ms': delay_ms,
                    'iterations': iterations,
                    'checksum': cpu_work(iterations),
                    'payload_kb': payload_kb,
                    'message': 'Mezcla espera, trabajo CPU y payload para emular una ruta más realista.',
                    'payload': payload_of_kb(payload_kb),
                }
            elif path == '/metrics':
                samples = state['samples_ms']
                avg = round(sum(samples) / len(samples), 2) if samples else 0.0
                body = {
                    'stack': 'Python',
                    'case': '01 - API lenta bajo carga',
                    'requests_tracked': state['requests'],
                    'sample_count': len(samples),
                    'avg_ms': avg,
                    'p95_ms': percentile(samples, 95),
                    'p99_ms': percentile(samples, 99),
                    'last_path': state['last_path'],
                    'last_status': state['last_status'],
                    'last_updated': state['last_updated'],
                    'note': 'Métrica simple, en proceso único, pensada para laboratorio. No reemplaza observabilidad real.',
                }
            elif path == '/reset-metrics':
                state['requests'] = 0
                state['samples_ms'] = []
                state['last_path'] = None
                state['last_status'] = 200
                state['last_updated'] = now_utc()
                body = {'status': 'reset', 'message': 'Métricas reiniciadas para el stack Python.'}
            else:
                status = 404
                body = {'error': 'Ruta no encontrada', 'path': path}
        except Exception as exc:
            status = 500
            body = {'error': 'Error interno', 'detail': str(exc)}

        elapsed_ms = round((time.perf_counter() - start) * 1000, 2)
        if path not in ['/metrics', '/reset-metrics']:
            state['requests'] += 1
            state['samples_ms'].append(elapsed_ms)
            if len(state['samples_ms']) > state['max_samples']:
                state['samples_ms'] = state['samples_ms'][-state['max_samples']:]
            state['last_path'] = path
            state['last_status'] = status
            state['last_updated'] = now_utc()

        body['elapsed_ms'] = elapsed_ms
        body['pid'] = 1
        body['timestamp_utc'] = now_utc()
        self._json(status, body)

    def log_message(self, format, *args):
        return


server = ThreadingHTTPServer(('0.0.0.0', 8080), Handler)
print('Servidor Python escuchando en 8080')
server.serve_forever()
