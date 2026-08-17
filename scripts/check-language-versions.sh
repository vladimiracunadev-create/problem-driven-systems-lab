#!/usr/bin/env bash
#
# check-language-versions.sh — coherencia de versiones de lenguaje.
#
# Verifica dos invariantes que ninguna otra validacion del repo cubre:
#
#   1. Todos los Dockerfile de un mismo stack fijan la MISMA imagen base.
#      Sin esto, un caso puede quedarse en una version vieja mientras el resto
#      avanza. Paso de verdad: 10 de 12 casos Node se quedaron en `node:20`
#      mientras el dispatcher y los casos 01/02 ya usaban `node:22`, asi que
#      el mismo caso corria en runtimes distintos segun se levantara por el hub
#      o en aislamiento — y la documentacion decia "Node.js 22" para todos.
#
#   2. La version que declara la documentacion coincide con la que fija el
#      Dockerfile. Una tabla que dice "Go 1.23" sobre un `FROM golang:1.22`
#      es exactamente el tipo de drift que este repositorio se propone no tener.
#
# No consulta la red: es determinista y corre en cada PR. La deteccion de
# versiones NUEVAS publicadas upstream vive en .github/workflows/language-drift.yml
# porque necesita internet y no debe bloquear un merge.
set -uo pipefail

fail=0
note() { printf '  %s\n' "$1"; }
bad()  { printf '  ✗ %s\n' "$1"; fail=1; }
ok()   { printf '  ✓ %s\n' "$1"; }

echo "Verificando coherencia de versiones de lenguaje..."
echo

# --- 1. Imagen base uniforme por stack -------------------------------------
# stack:patron-de-FROM
STACKS=(
  "php:php:"
  "node:node:"
  "python:python:"
  "java:eclipse-temurin:"
  "dotnet:mcr.microsoft.com/dotnet/sdk:"
  "go:golang:"
  "rust:rust:"
)

echo "[1/2] Imagen base uniforme entre los Dockerfile de cada stack"
for entry in "${STACKS[@]}"; do
  stack="${entry%%:*}"
  pattern="${entry#*:}"

  mapfile -t images < <(
    find cases -type f -name Dockerfile -path "*/${stack}/*" -print0 2>/dev/null \
      | xargs -0 grep -hoE "^FROM ${pattern}[^ ]+" 2>/dev/null \
      | sed 's/^FROM //' | sort -u
  )

  count=${#images[@]}
  if [ "$count" -eq 0 ]; then
    note "· ${stack}: sin Dockerfile propio (se omite)"
  elif [ "$count" -eq 1 ]; then
    ok "${stack}: ${images[0]} en todos los casos"
  else
    bad "${stack}: ${count} imagenes base distintas -> ${images[*]}"
    note "  Un caso quedaria en otro runtime segun como se levante."
  fi
done

echo

# --- 2. La documentacion declara la misma version que el Dockerfile --------
# stack|version-esperada-en-docs|patron-en-Dockerfile
CLAIMS=(
  "node|Node.js 22|node:22"
  "python|Python 3.12|python:3.12"
  "java|Java 21|eclipse-temurin:21"
  "dotnet|.NET 8|mcr.microsoft.com/dotnet/sdk:8.0"
  "go|Go 1.23|golang:1.23"
  "rust|Rust 1.83|rust:1.83"
)

echo "[2/2] La documentacion declara la version que el Dockerfile fija"
for entry in "${CLAIMS[@]}"; do
  IFS='|' read -r stack claim dockerpat <<< "$entry"

  if ! grep -rq "FROM ${dockerpat}" --include=Dockerfile cases 2>/dev/null; then
    bad "${stack}: la doc dice '${claim}' pero ningun Dockerfile fija '${dockerpat}'"
    continue
  fi
  if ! grep -q "${claim}" README.md 2>/dev/null; then
    bad "${stack}: el Dockerfile fija '${dockerpat}' pero README.md no menciona '${claim}'"
    continue
  fi
  ok "${stack}: README dice '${claim}' y el Dockerfile fija '${dockerpat}'"
done

echo
if [ "$fail" -ne 0 ]; then
  echo "✗ Hay drift de versiones. Revisa los Dockerfile y la documentacion."
  exit 1
fi
echo "✓ Versiones de lenguaje coherentes entre codigo y documentacion."
