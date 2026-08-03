# Caso 02 en Go — binario estatico, imagen final minima.
#
# modernc.org/sqlite es un port de SQLite a Go puro: no necesita cgo, asi que
# CGO_ENABLED=0 produce un binario sin dependencias de libc. Esa es la razon de
# elegirlo por sobre mattn/go-sqlite3, que exigiria toolchain de C en el runtime.
FROM golang:1.23-alpine AS build
WORKDIR /src

COPY app/go.mod ./
COPY app/main.go ./
# `go mod tidy` resuelve go.sum en build. El repo no versiona go.sum para no
# arrastrar cientos de hashes transitivos de un port de SQLite.
RUN go mod tidy && CGO_ENABLED=0 go build -trimpath -o /out/case02 .

FROM alpine:3.20
WORKDIR /app
# wget para el healthcheck del compose.
RUN apk add --no-cache wget
COPY --from=build /out/case02 /app/case02
EXPOSE 8080
CMD ["/app/case02"]
