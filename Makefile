# Hamyar — developer entrypoints.
# Everything PHP runs inside the `app` container; Node/Vite runs on the host.

SHELL := /bin/bash
.DEFAULT_GOAL := help

export HOST_UID := $(shell id -u)
export HOST_GID := $(shell id -g)

# Prefer the `docker compose` plugin; fall back to the standalone `docker-compose`
# binary, which is what Homebrew installs alongside colima.
DC := $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")

APP    := $(DC) exec -T app
APP_IT := $(DC) exec app

.PHONY: help
help: ## Show this help
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

## ---------------------------------------------------------------- stack ----

.PHONY: build
build: ## Rebuild the app image
	$(DC) build --build-arg UID=$(HOST_UID) --build-arg GID=$(HOST_GID) app

.PHONY: up
up: ## Start the dev stack (app, nginx, postgres, redis, minio, mailpit)
	$(DC) up -d --wait postgres redis minio
	$(DC) up -d
	@echo ""
	@echo "  app        http://app.localhost$(if $(APP_HTTP_PORT),:$(APP_HTTP_PORT),)"
	@echo "  tenant     http://demo.app.localhost$(if $(APP_HTTP_PORT),:$(APP_HTTP_PORT),)"
	@echo "  mailpit    http://localhost:8025"
	@echo "  minio      http://localhost:9001"
	@echo ""
	@echo "  Run 'npm run dev' on the host for Vite."

.PHONY: down
down: ## Stop the dev stack (keeps volumes)
	$(DC) down

.PHONY: destroy
destroy: ## Stop the stack AND delete all volumes (irreversible)
	$(DC) down -v

.PHONY: restart
restart: ## Restart app + nginx
	$(DC) restart app nginx

.PHONY: logs
logs: ## Tail logs for all services
	$(DC) logs -f --tail=100

.PHONY: sh
sh: ## Shell inside the app container
	$(APP_IT) bash

.PHONY: psql
psql: ## psql into the dev database as the superuser (NOTE: bypasses RLS — you see all tenants)
	$(DC) exec postgres psql -U $${DB_ROOT_USERNAME:-hamyar} -d $${DB_DATABASE:-hamyar}

.PHONY: psql-app
psql-app: ## psql as the application role (RLS enforced — set app.tenant_id to see rows)
	$(DC) exec postgres psql -U $${DB_USERNAME:-hamyar_app} -d $${DB_DATABASE:-hamyar}

## ------------------------------------------------------------ app tasks ----

.PHONY: install
install: ## Install PHP + JS dependencies
	$(APP) composer install
	npm install

.PHONY: fresh
fresh: ## Drop, migrate and seed the dev database (demo tenant)
	$(APP) php artisan migrate:fresh --seed
	@echo ""
	@echo "  Demo tenant: http://demo.app.localhost — admin@demo.test / password"

.PHONY: migrate
migrate: ## Run pending migrations
	$(APP) php artisan migrate

.PHONY: test
test: ## Full quality gate: Pint + Larastan + Pest
	$(APP) composer test

.PHONY: test-isolation
test-isolation: ## Tenancy isolation suite only
	$(APP) composer test:isolation

.PHONY: pint
pint: ## Fix code style
	$(APP) ./vendor/bin/pint

.PHONY: stan
stan: ## Static analysis (Larastan level 8)
	$(APP) ./vendor/bin/phpstan analyse

.PHONY: artisan
artisan: ## Run an artisan command: make artisan CMD="route:list"
	$(APP_IT) php artisan $(CMD)

.PHONY: composer
composer: ## Run a composer command: make composer CMD="require foo/bar"
	$(APP_IT) composer $(CMD)

.PHONY: gates
gates: ## Run every bin/check-* gate (the same ones CI runs)
	@for gate in bin/check-*; do \
		printf '\n\033[36m▸ %s\033[0m\n' "$$gate"; \
		$(APP) php "$$gate" || exit 1; \
	done

.PHONY: health
health: ## Database, cache, migrations and queue — the check /health and bin/deploy run
	$(APP_IT) php artisan health:check

.PHONY: horizon
horizon: ## Run Horizon in the foreground
	$(APP_IT) php artisan horizon

.PHONY: hooks
hooks: ## Install the repo's git hooks (refuses direct pushes to main)
	@git config core.hooksPath .githooks
	@echo "core.hooksPath = .githooks — direct pushes to main will be refused."
	@echo "Override for one push with: ALLOW_MAIN_PUSH=1 git push"
