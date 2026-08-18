# go-dispatcher — un solo contenedor con dispatcher + los casos del lab (Go 1.23).
#
# Espejo del patron java-dispatcher / dotnet-dispatcher: el stage de build
# compila cada caso por separado y el runtime los junta en /app/cases/0X/.
#
# Cada caso es su propio `package main` con su go.mod, asi que se compilan de a
# uno. Los casos 01 y 02 usan modernc.org/sqlite (Go puro) y necesitan
# `go mod tidy`; el resto son solo stdlib y compilan sin red.
#
# CGO_ENABLED=0 en todos: binarios estaticos, imagen final sin toolchain de C.
FROM golang:1.23-alpine AS build
WORKDIR /src

# --- casos con dependencia externa (SQLite embebido en Go puro) ---
COPY cases/01-api-latency-under-load/go/app/                          /src/case01/
COPY cases/02-n-plus-one-and-db-bottlenecks/go/app/                   /src/case02/
RUN cd /src/case01 && go mod tidy && CGO_ENABLED=0 go build -trimpath -o /out/cases/01/case01 . \
 && cd /src/case02 && go mod tidy && CGO_ENABLED=0 go build -trimpath -o /out/cases/02/case02 .

# --- casos solo-stdlib ---
COPY cases/03-poor-observability-and-useless-logs/go/app/                            /src/case03/
COPY cases/04-timeout-chain-and-retry-storms/go/app/                                 /src/case04/
COPY cases/05-memory-pressure-and-resource-leaks/go/app/                             /src/case05/
COPY cases/06-broken-pipeline-and-fragile-delivery/go/app/                           /src/case06/
COPY cases/07-incremental-monolith-modernization/go/app/                             /src/case07/
COPY cases/08-critical-module-extraction-without-breaking-operations/go/app/         /src/case08/
COPY cases/09-unstable-external-integration/go/app/                                  /src/case09/
COPY cases/10-expensive-architecture-for-simple-needs/go/app/                        /src/case10/
COPY cases/11-heavy-reporting-blocks-operations/go/app/                              /src/case11/
COPY cases/12-single-point-of-knowledge-and-operational-risk/go/app/                 /src/case12/
COPY cases/13-cache-stampede-and-thundering-herd/go/app/                           /src/case13/
COPY cases/14-connection-pool-exhaustion/go/app/                           /src/case14/
COPY cases/15-message-queue-backpressure/go/app/                           /src/case15/
COPY cases/16-idempotency-and-duplicate-effects/go/app/                           /src/case16/
COPY cases/17-zero-downtime-schema-migration/go/app/                           /src/case17/
COPY cases/18-cold-start-and-autoscale-lag/go/app/                           /src/case18/
RUN for n in 03 04 05 06 07 08 09 10 11 12 13 14 15 16 17 18; do \
      cd /src/case$n && CGO_ENABLED=0 go build -trimpath -o /out/cases/$n/case$n . || exit 1; \
    done

# --- dispatcher ---
COPY go-dispatcher/app/ /src/dispatcher/
RUN cd /src/dispatcher && CGO_ENABLED=0 go build -trimpath -o /out/dispatcher .

FROM alpine:3.20
WORKDIR /app
# wget para el healthcheck del compose.
RUN apk add --no-cache wget
COPY --from=build /out/dispatcher /app/dispatcher
COPY --from=build /out/cases      /app/cases

EXPOSE 8600
ENV PORT=8600
ENV APP_STACK="Go 1.23"

ENTRYPOINT ["/app/dispatcher"]
