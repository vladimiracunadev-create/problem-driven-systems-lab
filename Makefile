SHELL := /bin/bash

.PHONY: help portal-up portal-down portal-logs case-list case-up case-down compare-up compare-down case-load case1-benchmark

help:
	@echo "Comandos disponibles:"
	@echo "  make portal-up"
	@echo "  make portal-down"
	@echo "  make portal-logs"
	@echo "  make case-list"
	@echo "  make case-up CASE=01-api-latency-under-load STACK=php"
	@echo "  make case-down CASE=01-api-latency-under-load STACK=php"
	@echo "  make compare-up CASE=01-api-latency-under-load"
	@echo "  make compare-down CASE=01-api-latency-under-load"
	@echo "  make case-load CASE=01-api-latency-under-load TARGET_URL=http://php-app:8080/report-legacy?days=30\&limit=20 REQUESTS=40 CONCURRENCY=8"
	@echo "  make case1-benchmark REQUESTS=60 CONCURRENCY=8 DAYS=30 LIMIT=20"

portal-up:
	docker compose -f compose.root.yml up -d --build

portal-down:
	docker compose -f compose.root.yml down

portal-logs:
	docker compose -f compose.root.yml logs -f

case-list:
	@find cases -maxdepth 1 -mindepth 1 -type d | sort

case-up:
	test -n "$(CASE)" && test -n "$(STACK)"
	docker compose -f cases/$(CASE)/$(STACK)/compose.yml up -d --build

case-down:
	test -n "$(CASE)" && test -n "$(STACK)"
	docker compose -f cases/$(CASE)/$(STACK)/compose.yml down

compare-up:
	test -n "$(CASE)"
	docker compose -f cases/$(CASE)/compose.compare.yml up -d --build

compare-down:
	test -n "$(CASE)"
	docker compose -f cases/$(CASE)/compose.compare.yml down

case-load:
	test -n "$(CASE)"
	docker compose -f cases/$(CASE)/compose.compare.yml run --rm \
		-e TARGET_URL="$(TARGET_URL)" \
		-e REQUESTS="$(REQUESTS)" \
		-e CONCURRENCY="$(CONCURRENCY)" \
		loadtest

case1-benchmark:
	bash cases/01-api-latency-under-load/shared/benchmark/run-benchmark.sh
