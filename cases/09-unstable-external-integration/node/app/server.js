const http = require('http');

const payload = {
  "lab": "Problem-Driven Systems Lab",
  "case": "09 - Integración externa inestable",
  "stack": "Node.js",
  "message": "Base mínima dockerizada del caso.",
  "focus": "Una API, servicio o proveedor externo introduce latencia, errores intermitentes o reglas cambiantes que afectan el sistema propio."
};

http.createServer((req, res) => {
  res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(payload, null, 2));
}).listen(8080, '0.0.0.0', () => {
  console.log('Servidor Node escuchando en 8080');
});
