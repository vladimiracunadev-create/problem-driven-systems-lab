# Caso 02 en Rust — binario compilado, imagen final minima.
#
# rusqlite con feature `bundled` compila SQLite desde fuente, asi que el stage
# de build necesita toolchain de C (musl-dev). La imagen final solo lleva el
# binario: no hay runtime que instalar.
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
COPY --from=build /src/target/release/case02 /app/case02
EXPOSE 8080
CMD ["/app/case02"]
