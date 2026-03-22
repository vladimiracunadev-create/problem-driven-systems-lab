#!/usr/bin/env bash
set -euo pipefail

echo "Validando estructura básica..."
test -f README.md
test -f compose.root.yml
test -d docs
test -d cases
echo "OK"
