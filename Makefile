# Transaction Doctrine — development targets use the root docker-compose.yml (REQ-MAKE-001).

SHELL := /bin/bash

# Prefer the Compose V2 plugin; fall back to docker-compose V1 (REQ-MAKE-010).
COMPOSE_FILE := docker-compose.yml
COMPOSE_BIN := $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")
COMPOSE     := $(COMPOSE_BIN) -f $(COMPOSE_FILE)
SERVICE_PHP := php

.PHONY: help up down build shell install ensure-up test test-coverage cs-check cs-fix rector rector-dry phpstan igor qa \
	check-no-cursor-coauthor release-check check-open-prs release-check-demos composer-sync clean update validate \
	setup-hooks db-up db-down test-with-db test-coverage-with-db

help:
	@echo "Transaction Doctrine — development commands"
	@echo ""
	@echo "  up / down / build / shell   Docker container (root docker-compose.yml)"
	@echo "  install                     Install Composer dependencies"
	@echo "  test                        Run PHPUnit"
	@echo "  test-coverage               PHPUnit with coverage + global Lines %%"
	@echo "  test-with-db                Real-database suite (MySQL, MariaDB, PostgreSQL containers)"
	@echo "  test-coverage-with-db       Coverage including the real-database suite"
	@echo "  cs-check / cs-fix           Code style"
	@echo "  rector / rector-dry         Rector"
	@echo "  phpstan                     Static analysis (incl. FrankenPHP rulesets)"
	@echo "  igor                        Igor worker-state audit (REQ-CS-008)"
	@echo "  qa                          cs-check + phpstan + test"
	@echo "  release-check               Pre-release pipeline (REQ-MAKE-002)"
	@echo "  check-open-prs              Fail if unresolved open PRs (REQ-REL-003)"
	@echo "  composer-sync               Validate composer.json and refresh the install"
	@echo "  setup-hooks                 Install git hooks from .githooks/ (REQ-MAKE-006)"
	@echo "  clean                       Remove vendor and local artifacts"

build:
	$(COMPOSE) build --no-cache

up:
	$(COMPOSE) build
	$(COMPOSE) up -d
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction
	@echo "Container ready."

down:
	$(COMPOSE) --profile db down

shell:
	$(COMPOSE) exec $(SERVICE_PHP) sh

install: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install

ensure-up:
	@if ! $(COMPOSE) exec -T $(SERVICE_PHP) true 2>/dev/null; then \
		echo "Starting container (root docker-compose)..."; \
		$(COMPOSE) up -d --build $(SERVICE_PHP); \
		$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction; \
	fi

test: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer test

test-coverage: ensure-up
	@mkdir -p .coverage
	@set -o pipefail; $(COMPOSE) exec -T $(SERVICE_PHP) composer test-coverage 2>&1 | tee .coverage/coverage-php.txt
	sh .scripts/php-coverage-percent.sh .coverage/coverage-php.txt

# REQ-TEST-005: real MySQL 8.4 / MariaDB 11.4 / PostgreSQL 16 suite (TD_*_URL set in docker-compose.yml).
db-up: ensure-up
	$(COMPOSE) --profile db up -d --wait mysql mariadb postgres

test-with-db: db-up
	$(COMPOSE) exec -T $(SERVICE_PHP) vendor/bin/phpunit --testsuite Database --no-coverage --fail-on-skipped

test-coverage-with-db: db-up
	@mkdir -p .coverage
	@set -o pipefail; $(COMPOSE) exec -T $(SERVICE_PHP) composer test-coverage 2>&1 | tee .coverage/coverage-php.txt
	sh .scripts/php-coverage-percent.sh .coverage/coverage-php.txt

db-down:
	$(COMPOSE) --profile db stop mysql mariadb postgres

cs-check: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-check

cs-fix: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-fix

rector: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer rector

rector-dry: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer rector-dry

phpstan: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer phpstan

# Igor worker-state audit (REQ-CS-008)
igor: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer igor

qa: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer qa

update: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer update --no-interaction

validate: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict

composer-sync: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction --prefer-dist

# REQ-MAKE-002: sequential chain, ensure-up first.
release-check:
	@$(MAKE) ensure-up
	@$(MAKE) check-no-cursor-coauthor
	@$(MAKE) check-open-prs
	@$(MAKE) composer-sync
	@$(MAKE) cs-fix
	@$(MAKE) cs-check
	@$(MAKE) rector-dry
	@$(MAKE) phpstan
	@$(MAKE) igor
	@$(MAKE) test-coverage
	@$(MAKE) release-check-demos

release-check-demos:
	@if [ -f demo/Makefile ]; then $(MAKE) -C demo release-check; else echo "No demo/ (library) — skip release-check-demos"; fi

check-open-prs:
	@chmod +x .scripts/check-open-prs.sh
	@GH_REPO=nowo-tech/transaction-doctrine ./.scripts/check-open-prs.sh

# REQ-GIT-001
check-no-cursor-coauthor:
	@chmod +x .scripts/check-no-cursor-coauthor.sh .scripts/check-no-claude-coauthor.sh
	@./.scripts/check-no-cursor-coauthor.sh HEAD

setup-hooks:
	@chmod +x .githooks/pre-commit .githooks/commit-msg
	@git config core.hooksPath .githooks
	@echo "Git hooks installed (.githooks, commit-msg strips AI co-author trailers: REQ-GIT-001)."

clean:
	rm -rf vendor .phpunit.cache coverage coverage.xml .php-cs-fixer.cache .coverage
	rm -f coverage-php.txt

# REQ-MAKE-008: update-deps from the optional monorepo helper; absent on a standalone checkout (REQ-MAKE-009).
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))
-include $(BUNDLE_ROOT)/../.scripts/Makefile.update-deps.mk
