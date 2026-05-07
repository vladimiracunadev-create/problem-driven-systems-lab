#!/bin/sh
# entrypoint.sh — supervisor del PHP lab
#
# Spawnea los 12 servidores PHP de los casos como subprocesos internos en
# 127.0.0.1:9001-9012 (no expuestos al host) y luego corre el dispatcher
# como proceso foreground en :8100.
#
# Senales: tini (PID 1, ENTRYPOINT) propaga SIGTERM/SIGINT a este shell;
# el trap de abajo se encarga de matar a los 12 hijos antes de salir.

set -eu

CASE_PIDS=""

cleanup() {
    echo "[entrypoint] Shutting down case servers..."
    for pid in $CASE_PIDS; do
        kill -TERM "$pid" 2>/dev/null || true
    done
    # Espera breve para shutdown limpio
    sleep 1
    for pid in $CASE_PIDS; do
        kill -KILL "$pid" 2>/dev/null || true
    done
    exit 0
}
trap cleanup TERM INT

spawn_case() {
    case_id="$1"
    port="$2"
    case_dir="/cases/$case_id"

    case "$case_id" in
        01)
            DB_HOST="${CASE01_DB_HOST:-case01-db}" \
            DB_PORT=5432 \
            DB_NAME="${CASE01_DB_NAME:-problemlab}" \
            DB_USER="${CASE01_DB_USER:-problemlab}" \
            DB_PASSWORD="${CASE01_DB_PASSWORD:-problemlab}" \
            WORKER_NAME="${WORKER_NAME:-report-refresh}" \
            APP_STACK="PHP 8.3" \
            php -S "127.0.0.1:$port" -t "$case_dir" "$case_dir/index.php" \
                >/dev/null 2>&1 &
            ;;
        02)
            DB_HOST="${CASE02_DB_HOST:-case02-db}" \
            DB_PORT=5432 \
            DB_NAME="${CASE02_DB_NAME:-problemlab}" \
            DB_USER="${CASE02_DB_USER:-problemlab}" \
            DB_PASSWORD="${CASE02_DB_PASSWORD:-problemlab}" \
            APP_STACK="PHP 8.3" \
            php -S "127.0.0.1:$port" -t "$case_dir" "$case_dir/index.php" \
                >/dev/null 2>&1 &
            ;;
        *)
            APP_STACK="PHP 8.3" \
            php -S "127.0.0.1:$port" -t "$case_dir" "$case_dir/index.php" \
                >/dev/null 2>&1 &
            ;;
    esac
    pid=$!
    CASE_PIDS="$CASE_PIDS $pid"
    echo "  case $case_id -> :$port (pid $pid)"
}

echo "[entrypoint] Spawning 12 case servers..."
spawn_case 01 9001
spawn_case 02 9002
spawn_case 03 9003
spawn_case 04 9004
spawn_case 05 9005
spawn_case 06 9006
spawn_case 07 9007
spawn_case 08 9008
spawn_case 09 9009
spawn_case 10 9010
spawn_case 11 9011
spawn_case 12 9012

# Espera breve para que los servidores se levanten antes del dispatcher
echo "[entrypoint] Waiting 2s for case servers to come up..."
sleep 2

echo "[entrypoint] Dispatcher listening on :8100"
echo "[entrypoint] Routes: /01/.../12/  -> casos internos :9001...:9012"

# Dispatcher en foreground. NO usar exec — necesitamos que el trap siga vivo.
php -S 0.0.0.0:8100 /app/dispatcher.php &
DISP_PID=$!
CASE_PIDS="$CASE_PIDS $DISP_PID"

# Espera al dispatcher; si muere, salimos (cleanup mata los casos).
wait $DISP_PID
echo "[entrypoint] Dispatcher exited"
cleanup
