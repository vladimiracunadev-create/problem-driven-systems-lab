const http = require('http');

const payload = {
  "lab": "Problem-Driven Systems Lab",
  "case": "03 - Observabilidad deficiente y logs inútiles",
  "stack": "Node.js",
  "message": "Base mínima dockerizada del caso.",
  "focus": "Existen errores e incidentes, pero no hay trazabilidad suficiente para identificar causa raíz de forma rápida y confiable."
};

http.createServer((req, res) => {
  res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(payload, null, 2));
}).listen(8080, '0.0.0.0', () => {
  console.log('Servidor Node escuchando en 8080');
});
