const http = require('http');

const payload = {
  "lab": "Problem-Driven Systems Lab",
  "case": "07 - Modernización incremental de monolito",
  "stack": "Node.js",
  "message": "Base mínima dockerizada del caso.",
  "focus": "El sistema legacy sigue siendo crítico, pero su evolución se vuelve lenta, riesgosa y costosa."
};

http.createServer((req, res) => {
  res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(payload, null, 2));
}).listen(8080, '0.0.0.0', () => {
  console.log('Servidor Node escuchando en 8080');
});
