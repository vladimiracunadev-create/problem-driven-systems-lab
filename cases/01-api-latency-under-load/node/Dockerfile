FROM node:22.23.4-alpine3.24
WORKDIR /app
COPY app/server.js /app/server.js
EXPOSE 8080
# node:sqlite sigue marcado experimental en Node 22, pero esta habilitado por defecto desde 22.13.
CMD ["node", "server.js"]
