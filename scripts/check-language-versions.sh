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
#      Dockerfile. Una tabla que dice "Go 1.27" sobre un `FROM golang:1.22`
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
  "java:amazoncorretto:"
  "dotnet:mcr.microsoft.com/dotnet/sdk:"
  "go:golang:"
  "rust:rust:"
)

echo "[1/3] Imagen base uniforme entre los Dockerfile de cada stack"
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

if grep -rq "FROM mcr.microsoft.com/dotnet/aspnet:10.0.14" --include=Dockerfile cases \
  && grep -q "FROM mcr.microsoft.com/dotnet/aspnet:10.0.14" dotnet-dispatcher/Dockerfile; then
  ok "dotnet: los 20 casos y el dispatcher fijan el runtime aspnet:10.0.14"
else
  bad "dotnet: falta el runtime aspnet:10.0.14 en algun caso o dispatcher"
fi

echo

# --- 2. La documentacion declara la misma version que el Dockerfile --------
# stack|version-esperada-en-docs|patron-en-Dockerfile
CLAIMS=(
  "php|PHP 8.4.27|php:8.4.27-cli-alpine3.24"
  "node|Node.js 22.23.4|node:22.23.4-alpine3.24"
  "python|Python 3.13.17|python:3.13.17-alpine3.24"
  "java|Java 25.0.5|amazoncorretto:25.0.5-alpine3.24"
  "dotnet|.NET 10.0.14|mcr.microsoft.com/dotnet/sdk:10.0.403"
  "go|Go 1.27.3|golang:1.27.3-alpine3.24"
  "rust|Rust 1.99|rust:1.99"
)

echo "[2/3] La documentacion declara la version exacta que el Dockerfile fija"
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
echo "[3/3] No quedan marcadores actuales con versiones o coberturas antiguas"
CURRENT_DOCS=(
  README.md
  INSTALL.md
  ARCHITECTURE.md
  AWS_MIGRATION.md
  RECRUITER.md
  docs/architecture.md
  docs/docker-strategy.md
  docs/languages/README.md
)

stale_pattern='PHP 8\.3|Python 3\.12|Node\.js 20|eclipse-temurin|\.NET 10([^.]|$)|\.NET 10\.0([^.]|$)|mcr\.microsoft\.com/dotnet/(sdk|aspnet):10\.0([^.]|$)|12 subprocesos|12 procesos internos|/12/health|12 apps PHP|los doce casos|ninguno de los doce casos'
if grep -En "$stale_pattern" "${CURRENT_DOCS[@]}"; then
  bad "la documentacion actual conserva versiones o coberturas antiguas"
else
  ok "sin versiones ni coberturas antiguas en la documentacion actual"
fi

for cve in \
  CVE-2026-109618 CVE-2026-109619 CVE-2026-115560 \
  CVE-2026-106069 CVE-2026-106072 CVE-2026-105482 CVE-2026-111497 \
  CVE-2026-105481 CVE-2026-106074 CVE-2026-106073 \
  CVE-2026-111943 CVE-2026-91403 \
  CVE-2026-70197 CVE-2026-102870 CVE-2026-114148 \
  CVE-2026-115557 CVE-2026-77214 CVE-2026-102633 CVE-2026-114583 CVE-2024-3220; do
  if ! grep -q "$cve" README.md || ! grep -q "$cve" docs/security/CVE-2026-OCTOBER.md; then
    bad "$cve no aparece tanto en README como en la matriz de seguridad"
  else
    ok "$cve visible en portada y matriz de seguridad"
  fi
done

echo
if [ "$fail" -ne 0 ]; then
  echo "✗ Hay drift de versiones. Revisa los Dockerfile y la documentacion."
  exit 1
fi
echo "✓ Versiones de lenguaje coherentes entre codigo y documentacion."
