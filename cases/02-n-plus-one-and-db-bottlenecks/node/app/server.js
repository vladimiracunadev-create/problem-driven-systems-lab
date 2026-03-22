const http = require('http');

const payload = {
  "lab": "Problem-Driven Systems Lab",
  "case": "02 - N+1 queries y cuellos de botella en base de datos",
  "stack": "Node.js",
  "message": "Base mínima dockerizada del caso.",
  "focus": "La aplicación ejecuta demasiadas consultas por solicitud o usa el ORM de forma ineficiente, generando saturación de base de datos."
};

http.createServer((req, res) => {
  res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(payload, null, 2));
}).listen(8080, '0.0.0.0', () => {
  console.log('Servidor Node escuchando en 8080');
});
