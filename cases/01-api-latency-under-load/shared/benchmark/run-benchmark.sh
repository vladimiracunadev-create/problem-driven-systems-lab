#!/usr/bin/env bash
set -euo pipefail

CASE_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
REPO_DIR="$(cd "$CASE_DIR/../.." && pwd)"
OUT_DIR="${OUT_DIR:-$CASE_DIR/shared/benchmark/out}"
REQUESTS="${REQUESTS:-60}"
CONCURRENCY="${CONCURRENCY:-8}"
DAYS="${DAYS:-30}"
LIMIT="${LIMIT:-20}"
BASE_URL="${BASE_URL:-http://localhost:811}"
TEST_STAMP="$(date +%Y%m%d-%H%M%S)"
RUN_DIR="$OUT_DIR/$TEST_STAMP"

mkdir -p "$RUN_DIR"

fetch_json() {
  local url="$1"
  local out="$2"
  curl -fsS "$url" > "$out"
}

echo "[1/7] Reiniciando métricas locales..."
fetch_json "$BASE_URL/reset-metrics" "$RUN_DIR/reset.json"

echo "[2/7] Warmup legacy..."
for _ in $(seq 1 8); do
  curl -fsS "$BASE_URL/report-legacy?days=$DAYS&limit=$LIMIT" > /dev/null
done

echo "[3/7] Benchmark legacy..."
(
  cd "$REPO_DIR"
  docker compose -f cases/01-api-latency-under-load/compose.compare.yml run --rm     -e TARGET_URL="http://php-app:8080/report-legacy?days=$DAYS&limit=$LIMIT"     -e REQUESTS="$REQUESTS"     -e CONCURRENCY="$CONCURRENCY"     -e TEST_NAME="legacy-before"     loadtest
) | tee "$RUN_DIR/legacy-loadtest.json"
fetch_json "$BASE_URL/metrics" "$RUN_DIR/legacy-metrics.json"
fetch_json "$BASE_URL/diagnostics/summary" "$RUN_DIR/legacy-diagnostics.json"
fetch_json "$BASE_URL/batch/status" "$RUN_DIR/legacy-worker.json"

echo "[4/7] Reiniciando métricas para la fase optimizada..."
fetch_json "$BASE_URL/reset-metrics" "$RUN_DIR/reset-optimized.json"

echo "[5/7] Warmup optimized..."
for _ in $(seq 1 8); do
  curl -fsS "$BASE_URL/report-optimized?days=$DAYS&limit=$LIMIT" > /dev/null
done

echo "[6/7] Benchmark optimized..."
(
  cd "$REPO_DIR"
  docker compose -f cases/01-api-latency-under-load/compose.compare.yml run --rm     -e TARGET_URL="http://php-app:8080/report-optimized?days=$DAYS&limit=$LIMIT"     -e REQUESTS="$REQUESTS"     -e CONCURRENCY="$CONCURRENCY"     -e TEST_NAME="optimized-after"     loadtest
) | tee "$RUN_DIR/optimized-loadtest.json"
fetch_json "$BASE_URL/metrics" "$RUN_DIR/optimized-metrics.json"
fetch_json "$BASE_URL/diagnostics/summary" "$RUN_DIR/optimized-diagnostics.json"
fetch_json "$BASE_URL/batch/status" "$RUN_DIR/optimized-worker.json"

echo "[7/7] Artefactos guardados en: $RUN_DIR"
