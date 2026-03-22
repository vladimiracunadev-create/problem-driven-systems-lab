const http = require('http');

const payload = {
  "lab": "Problem-Driven Systems Lab",
  "case": "04 - Cadena de timeouts y tormentas de reintentos",
  "stack": "Node.js",
  "message": "Base mínima dockerizada del caso.",
  "focus": "Una integración lenta o inestable dispara reintentos, bloqueos y cascadas de fallas entre servicios."
};

http.createServer((req, res) => {
  res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(payload, null, 2));
}).listen(8080, '0.0.0.0', () => {
  console.log('Servidor Node escuchando en 8080');
});
