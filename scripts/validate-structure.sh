#!/usr/bin/env bash
set -euo pipefail

echo "Validando estructura básica..."

required_root=(
  .github/workflows/ci.yml
  ARCHITECTURE.md
  CHANGELOG.md
  CONTRIBUTING.md
  INSTALL.md
  README.md
  RECRUITER.md
  ROADMAP.md
  RUNBOOK.md
  SECURITY.md
  SUPPORT.md
  Makefile
  compose.root.yml
  docs
  cases
  shared/catalog
  templates
  shared
  scripts/generate_case_catalog.php
)

required_case_docs=(
  business-value.md
  context.md
  diagnosis.md
  root-causes.md
  solution-options.md
  symptoms.md
  trade-offs.md
)

stacks=(php node python java dotnet)

for path in "${required_root[@]}"; do
  test -e "$path"
done

for case_dir in cases/*; do
  test -d "$case_dir"
  test -f "$case_dir/README.md"
  test -f "$case_dir/compose.compare.yml"
  test -d "$case_dir/docs"
  test -d "$case_dir/shared"

  for doc in "${required_case_docs[@]}"; do
    test -f "$case_dir/docs/$doc"
  done

  for stack in "${stacks[@]}"; do
    test -d "$case_dir/$stack"
    test -f "$case_dir/$stack/Dockerfile"
    test -f "$case_dir/$stack/README.md"
    test -f "$case_dir/$stack/compose.yml"
  done
done

test -f shared/catalog/cases.json

if command -v php >/dev/null 2>&1; then
  php scripts/generate_case_catalog.php --check
fi

if git ls-files | grep -E '\.class$|/__pycache__/|\.pyc$|metrics-store\.json$|telemetry\.json$|legacy\.log$|observable\.log$|\.coverage$|/coverage/|/\.pytest_cache/' >/dev/null; then
  echo "Se detectaron artefactos generados versionados en Git."
  exit 1
fi

echo "OK"
