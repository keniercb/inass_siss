# SGP backend developer shortcuts (plan S1.2).
# Local runs use the portable toolchain; `compose` targets orchestrate Docker.

COMPOSE = docker compose
PHP = php
Pest = vendor/bin/pest

.PHONY: help up down shell test test-unit test-coverage pint stan deptrac seed fresh quality compose-up compose-down

help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' Makefile | awk 'BEGIN {FS = ":.*?## "}; {printf "  %-14s %s\n", $$1, $$2}'

up: ## Start the portable local MySQL (port 13306)
	@/home/z/.runtime/bin/start-mysql.sh 2>/dev/null || true

test: up ## Run the whole suite against real MySQL (ADR-08)
	$(PHP) $(Pest)

test-unit: ## Run module unit tests only
	$(PHP) $(Pest) app/Modules/Shared/Tests/Unit

test-coverage: up ## Full suite with coverage thresholds (90/80)
	$(PHP) $(Pest) --coverage --min=80

pint: ## Format the codebase
	vendor/bin/pint

stan: ## Static analysis (PHPStan level 8 via Larastan)
	$(PHP) vendor/bin/phpstan analyse

deptrac: ## Verify module dependency rules (ADR-10)
	$(PHP) vendor/bin/deptrac analyse

seed: up ## Run the idempotent seeders
	$(PHP) artisan migrate:fresh --seed --force

fresh: up ## Recreate the database from scratch
	$(PHP) artisan migrate:fresh --force

quality: pint stan deptrac test-coverage ## Full local quality gate

compose-up: ## Start the full Docker stack (app, web, db, queue, scheduler)
	$(COMPOSE) up -d --build

compose-down: ## Stop the Docker stack
	$(COMPOSE) down
