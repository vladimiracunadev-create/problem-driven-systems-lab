from http.server import BaseHTTPRequestHandler, HTTPServer
import json

payload = {
  "lab": "Problem-Driven Systems Lab",
  "case": "06 - Pipeline roto y entrega frágil",
  "stack": "Python",
  "message": "Base mínima dockerizada del caso.",
  "focus": "El software funciona en desarrollo, pero falla al desplegar, promover cambios o revertir incidentes con seguridad."
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
