# rust-dispatcher — un solo contenedor con dispatcher + los casos del lab (Rust 1.83).
#
# Espejo del patron java-dispatcher / dotnet-dispatcher / go-dispatcher: el
# stage de build compila los casos y el runtime los junta en /app/cases/NN/.
#
# A diferencia de los otros, aca se arma un WORKSPACE de cargo. Compilar los 12
# crates por separado haria que rusqlite (casos 01 y 02) se compile dos veces —
# ~80 s cada una. Con workspace, las dependencias compartidas se compilan una
# sola vez y todos los binarios salen a un target/ comun.
FROM rust:1.83-alpine AS build
RUN apk add --no-cache musl-dev
WORKDIR /src

COPY rust-dispatcher/workspace.Cargo.toml /src/Cargo.toml

COPY cases/01-api-latency-under-load/rust/app/                                   /src/case01/
COPY cases/02-n-plus-one-and-db-bottlenecks/rust/app/                            /src/case02/
COPY cases/03-poor-observability-and-useless-logs/rust/app/                      /src/case03/
COPY cases/04-timeout-chain-and-retry-storms/rust/app/                           /src/case04/
COPY cases/05-memory-pressure-and-resource-leaks/rust/app/                       /src/case05/
COPY cases/06-broken-pipeline-and-fragile-delivery/rust/app/                     /src/case06/
COPY cases/07-incremental-monolith-modernization/rust/app/                       /src/case07/
COPY cases/08-critical-module-extraction-without-breaking-operations/rust/app/   /src/case08/
COPY cases/09-unstable-external-integration/rust/app/                            /src/case09/
COPY cases/10-expensive-architecture-for-simple-needs/rust/app/                  /src/case10/
COPY cases/11-heavy-reporting-blocks-operations/rust/app/                        /src/case11/
COPY cases/12-single-point-of-knowledge-and-operational-risk/rust/app/           /src/case12/
COPY cases/13-cache-stampede-and-thundering-herd/rust/app/                     /src/case13/
COPY cases/14-connection-pool-exhaustion/rust/app/                     /src/case14/
COPY cases/15-message-queue-backpressure/rust/app/                     /src/case15/
COPY cases/16-idempotency-and-duplicate-effects/rust/app/                     /src/case16/
COPY cases/17-zero-downtime-schema-migration/rust/app/                     /src/case17/
COPY cases/18-cold-start-and-autoscale-lag/rust/app/                     /src/case18/
COPY cases/19-search-index-drift-and-broken-cdc/rust/app/                     /src/case19/
COPY rust-dispatcher/app/                                                        /src/dispatcher/

# Los manifests de cada caso declaran [profile.release] para poder compilarse
# en aislamiento. Dentro de un workspace ese perfil se ignora y cargo lo avisa
# en cada crate; se recorta para que el build salga limpio.
RUN for d in case01 case02 case03 case04 case05 case06 case07 case08 case09 case10 case11 case12 case13 case14 case15 case16 case17 case18 case19 dispatcher; do \
      sed -i '/^\[profile\.release\]/,$d' /src/$d/Cargo.toml; \
    done \
 && cargo build --release

FROM alpine:3.20
WORKDIR /app
# wget para el healthcheck del compose.
RUN apk add --no-cache wget

COPY --from=build /src/target/release/dispatcher /app/dispatcher
COPY --from=build /src/target/release/case01 /app/cases/01/case01
COPY --from=build /src/target/release/case02 /app/cases/02/case02
COPY --from=build /src/target/release/case03 /app/cases/03/case03
COPY --from=build /src/target/release/case04 /app/cases/04/case04
COPY --from=build /src/target/release/case05 /app/cases/05/case05
COPY --from=build /src/target/release/case06 /app/cases/06/case06
COPY --from=build /src/target/release/case07 /app/cases/07/case07
COPY --from=build /src/target/release/case08 /app/cases/08/case08
COPY --from=build /src/target/release/case09 /app/cases/09/case09
COPY --from=build /src/target/release/case10 /app/cases/10/case10
COPY --from=build /src/target/release/case11 /app/cases/11/case11
COPY --from=build /src/target/release/case12 /app/cases/12/case12
COPY --from=build /src/target/release/case13 /app/cases/13/case13
COPY --from=build /src/target/release/case14 /app/cases/14/case14
COPY --from=build /src/target/release/case15 /app/cases/15/case15
COPY --from=build /src/target/release/case16 /app/cases/16/case16
COPY --from=build /src/target/release/case17 /app/cases/17/case17
COPY --from=build /src/target/release/case18 /app/cases/18/case18
COPY --from=build /src/target/release/case19 /app/cases/19/case19

EXPOSE 8700
ENV PORT=8700
ENV APP_STACK="Rust 1.83"

ENTRYPOINT ["/app/dispatcher"]
