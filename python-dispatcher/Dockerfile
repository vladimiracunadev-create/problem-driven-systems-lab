FROM python:3.12-alpine

WORKDIR /app

# Copiar cada server.py de los 12 casos en /cases/{id}/server.py
COPY cases/01-api-latency-under-load/python/app/server.py               /cases/01/server.py
COPY cases/02-n-plus-one-and-db-bottlenecks/python/app/server.py        /cases/02/server.py
COPY cases/03-poor-observability-and-useless-logs/python/app/server.py  /cases/03/server.py
COPY cases/04-timeout-chain-and-retry-storms/python/app/server.py       /cases/04/server.py
COPY cases/05-memory-pressure-and-resource-leaks/python/app/server.py   /cases/05/server.py
COPY cases/06-broken-pipeline-and-fragile-delivery/python/app/server.py /cases/06/server.py
COPY cases/07-incremental-monolith-modernization/python/app/server.py   /cases/07/server.py
COPY cases/08-critical-module-extraction-without-breaking-operations/python/app/server.py /cases/08/server.py
COPY cases/09-unstable-external-integration/python/app/server.py        /cases/09/server.py
COPY cases/10-expensive-architecture-for-simple-needs/python/app/server.py /cases/10/server.py
COPY cases/11-heavy-reporting-blocks-operations/python/app/server.py    /cases/11/server.py
COPY cases/12-single-point-of-knowledge-and-operational-risk/python/app/server.py /cases/12/server.py

# Dispatcher
COPY python-dispatcher/app/main.py /app/main.py

EXPOSE 8200

CMD ["python", "/app/main.py"]
