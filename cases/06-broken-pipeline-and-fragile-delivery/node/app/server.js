const http = require('http');

const payload = {
  "lab": "Problem-Driven Systems Lab",
  "case": "06 - Pipeline roto y entrega frágil",
  "stack": "Node.js",
  "message": "Base mínima dockerizada del caso.",
  "focus": "El software funciona en desarrollo, pero falla al desplegar, promover cambios o revertir incidentes con seguridad."
};

http.createServer((req, res) => {
  res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(payload, null, 2));
}).listen(8080, '0.0.0.0', () => {
  console.log('Servidor Node escuchando en 8080');
});
