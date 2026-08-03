# Caso 12 en Rust — binario compilado, imagen final minima.
#
# Solo std: sin crates externos. musl-dev es necesario igual porque el
# linker de Rust en Alpine lo requiere. La imagen final solo lleva el binario.
FROM rust:1.83-alpine AS build
RUN apk add --no-cache musl-dev
WORKDIR /src

COPY app/Cargo.toml ./
COPY app/src ./src
RUN cargo build --release

FROM alpine:3.20
WORKDIR /app
# wget para el healthcheck del compose.
RUN apk add --no-cache wget
COPY --from=build /src/target/release/case12 /app/case12
EXPOSE 8080
CMD ["/app/case12"]
