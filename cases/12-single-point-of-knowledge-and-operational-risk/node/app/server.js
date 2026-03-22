const http = require('http');

const payload = {
  "lab": "Problem-Driven Systems Lab",
  "case": "12 - Punto único de conocimiento y riesgo operacional",
  "stack": "Node.js",
  "message": "Base mínima dockerizada del caso.",
  "focus": "Una persona, módulo o procedimiento concentra tanto conocimiento que el sistema se vuelve frágil ante ausencias o rotación."
};

http.createServer((req, res) => {
  res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(payload, null, 2));
}).listen(8080, '0.0.0.0', () => {
  console.log('Servidor Node escuchando en 8080');
});
