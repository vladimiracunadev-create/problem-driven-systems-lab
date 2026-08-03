FROM node:22-alpine
WORKDIR /app
COPY app/server.js /app/server.js
EXPOSE 8080
# node:sqlite es built-in pero experimental en Node 22.x — requiere flag.
CMD ["node", "--experimental-sqlite", "server.js"]
