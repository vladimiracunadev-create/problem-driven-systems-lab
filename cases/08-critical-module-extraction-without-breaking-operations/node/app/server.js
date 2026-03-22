const http = require('http');

const payload = {
  "lab": "Problem-Driven Systems Lab",
  "case": "08 - Extracción de módulo crítico sin romper operación",
  "stack": "Node.js",
  "message": "Base mínima dockerizada del caso.",
  "focus": "Se necesita desacoplar una parte clave del sistema, pero esa parte participa en flujos sensibles y no admite quiebres."
};

http.createServer((req, res) => {
  res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(payload, null, 2));
}).listen(8080, '0.0.0.0', () => {
  console.log('Servidor Node escuchando en 8080');
});
