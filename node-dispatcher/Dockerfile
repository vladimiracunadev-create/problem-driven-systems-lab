FROM node:20-alpine

WORKDIR /app

# Copiar cada server.js de los 12 casos en /cases/{id}/server.js
COPY cases/01-api-latency-under-load/node/app/server.js               /cases/01/server.js
COPY cases/02-n-plus-one-and-db-bottlenecks/node/app/server.js        /cases/02/server.js
COPY cases/03-poor-observability-and-useless-logs/node/app/server.js  /cases/03/server.js
COPY cases/04-timeout-chain-and-retry-storms/node/app/server.js       /cases/04/server.js
COPY cases/05-memory-pressure-and-resource-leaks/node/app/server.js   /cases/05/server.js
COPY cases/06-broken-pipeline-and-fragile-delivery/node/app/server.js /cases/06/server.js
COPY cases/07-incremental-monolith-modernization/node/app/server.js   /cases/07/server.js
COPY cases/08-critical-module-extraction-without-breaking-operations/node/app/server.js /cases/08/server.js
COPY cases/09-unstable-external-integration/node/app/server.js        /cases/09/server.js
COPY cases/10-expensive-architecture-for-simple-needs/node/app/server.js /cases/10/server.js
COPY cases/11-heavy-reporting-blocks-operations/node/app/server.js    /cases/11/server.js
COPY cases/12-single-point-of-knowledge-and-operational-risk/node/app/server.js /cases/12/server.js

# Dispatcher
COPY node-dispatcher/app/main.js /app/main.js

EXPOSE 8300

CMD ["node", "/app/main.js"]
