# Local development shortcuts. Sites: http://tmc.localhost, http://tmh.tmc.localhost, ...
.PHONY: help setup up down restart logs lint test smoke check wp shell login

help: ## Show commands
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  make %-9s %s\n", $$1, $$2}'

setup: ## First run: create .env, build, install all six sites + demo accounts
	./scripts/make-env.sh local
	./scripts/setup.sh
	./scripts/create-demo-users.sh

up: ## Start containers
	docker compose up -d --build

down: ## Stop containers (data kept)
	docker compose down

restart: ## Restart WordPress (after Dockerfile / compose changes)
	docker compose up -d --build --force-recreate wordpress

logs: ## Follow WordPress logs
	docker compose logs -f wordpress

lint: ## Static checks (PHP, JSON, JS, shell)
	./scripts/lint.sh

test: ## Workflow / permissions / audit-log tests
	docker compose run --rm -T wpcli --url=tmh.tmc.localhost eval-file - < scripts/tests/workflow-test.php

smoke: ## HTTP smoke test of all sites
	./scripts/smoke-test.sh

check: lint test smoke ## Everything CI runs

wp: ## Run WP-CLI, e.g. make wp ARGS="site list"
	docker compose run --rm -T wpcli --url=tmc.localhost $(ARGS)

login: ## Show local admin + demo logins (local only)
	@grep -E '^WP_ADMIN_(USER|PASSWORD)=' .env; cat demo-users.txt 2>/dev/null
