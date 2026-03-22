const http = require('http');

const payload = {
  "lab": "Problem-Driven Systems Lab",
  "case": "11 - Reportes pesados que bloquean la operación",
  "stack": "Node.js",
  "message": "Base mínima dockerizada del caso.",
  "focus": "Consultas y procesos de reporting compiten con la operación transaccional y degradan el sistema completo."
};

http.createServer((req, res) => {
  res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(payload, null, 2));
}).listen(8080, '0.0.0.0', () => {
  console.log('Servidor Node escuchando en 8080');
});
