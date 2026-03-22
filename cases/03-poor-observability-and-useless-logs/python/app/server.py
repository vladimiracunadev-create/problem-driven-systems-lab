from http.server import BaseHTTPRequestHandler, HTTPServer
import json

payload = {
  "lab": "Problem-Driven Systems Lab",
  "case": "03 - Observabilidad deficiente y logs inútiles",
  "stack": "Python",
  "message": "Base mínima dockerizada del caso.",
  "focus": "Existen errores e incidentes, pero no hay trazabilidad suficiente para identificar causa raíz de forma rápida y confiable."
}

class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        self.send_response(200)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.end_headers()
        self.wfile.write(json.dumps(payload, ensure_ascii=False, indent=2).encode('utf-8'))

server = HTTPServer(('0.0.0.0', 8080), Handler)
print('Servidor Python escuchando en 8080')
server.serve_forever()
