# Caso 14 en Go — binario estatico, imagen final minima.
#
# Solo stdlib: no hay go.sum que resolver ni modulos que descargar.
FROM golang:1.23-alpine AS build
WORKDIR /src

COPY app/go.mod ./
COPY app/main.go ./
RUN CGO_ENABLED=0 go build -trimpath -o /out/case14 .

FROM alpine:3.20
WORKDIR /app
# wget para el healthcheck del compose.
RUN apk add --no-cache wget
COPY --from=build /out/case14 /app/case14
EXPOSE 8080
CMD ["/app/case14"]
