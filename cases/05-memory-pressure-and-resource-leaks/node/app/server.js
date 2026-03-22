const http = require('http');

const payload = {
  "lab": "Problem-Driven Systems Lab",
  "case": "05 - Presión de memoria y fugas de recursos",
  "stack": "Node.js",
  "message": "Base mínima dockerizada del caso.",
  "focus": "El sistema consume memoria, descriptores o conexiones de forma progresiva hasta degradar o caerse."
};

http.createServer((req, res) => {
  res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(payload, null, 2));
}).listen(8080, '0.0.0.0', () => {
  console.log('Servidor Node escuchando en 8080');
});
