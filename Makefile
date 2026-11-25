# Every target runs inside Docker. Nothing here uses the host's PHP or Node.

.DEFAULT_GOAL := help
DC    := docker compose
EXEC  := $(DC) exec -T app
RUN   := $(DC) run --rm --no-deps app

help: ## List available targets
	@grep -hE '^[a-z-]+:.*?## ' $(MAKEFILE_LIST) | awk -F':.*?## ' '{printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

up: ## Build images, install dependencies, start every service, migrate
	$(DC) build
	@$(MAKE) --no-print-directory install
	$(DC) up -d
	@$(MAKE) --no-print-directory migrate
	@echo "\n  App      http://localhost:8000\n  Vite     http://localhost:5173\n"

down: ## Stop every service
	$(DC) down

fresh: ## Destroy everything including data volumes, then rebuild
	$(DC) down -v
	@$(MAKE) --no-print-directory up

install: ## Install PHP dependencies (inside the container, never on the host)
	$(RUN) composer install --no-interaction --prefer-dist

migrate: ## Run migrations
	$(EXEC) php artisan migrate --force

fresh-db: ## Drop and recreate the schema
	$(EXEC) php artisan migrate:fresh --force

test: ## Run the test suite
	$(EXEC) ./vendor/bin/pest

analyse: ## Run static analysis
	$(EXEC) composer analyse

lint: ## Check code style
	$(EXEC) composer lint

fix: ## Apply code style fixes
	$(EXEC) ./vendor/bin/pint

check: lint analyse test ## Everything CI runs

shell: ## Open a shell in the app container
	$(DC) exec app sh

psql: ## Open a Postgres session
	$(DC) exec postgres psql -U pixelforge -d pixelforge

logs: ## Tail logs from every service
	$(DC) logs -f

build-prod: ## Verify the production image builds
	docker build --target production -t pixelforge:production .

.PHONY: help up down fresh install migrate fresh-db test analyse lint fix check shell psql logs build-prod
