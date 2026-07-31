PROD = docker compose --env-file .env --env-file .env.local -f compose.yml -f compose.prod.yml
DEV = docker compose -f compose.yml -f compose.dev.yml
COLOR_RESET = \033[0m
COLOR_CYAN = \033[36m
.DEFAULT_GOAL := help

include system/backend/commands.mk
include config/tools/pipeline.mk

.PHONY: help
help: ## [Help] This help
	@makefiles=$$(echo $(MAKEFILE_LIST) | grep Makefile); \
	grep -hE '^[2a-zA-Z_-]+:.*?## .*$$' $$makefiles | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "$(COLOR_CYAN)%-30s$(COLOR_RESET) %s\n", $$1, $$2}'

.PHONY: clean
clean: ## [Deployment] Stop containers and clean caches & deps
	$(PROD) down --remove-orphans
	$(PROD) run --rm --no-deps -u 0 app rm -rf \
		var/cache/ \
		vendor/

.PHONY: deploy
deploy: ## [Deployment] Start containers
	$(PROD) up --build --detach
	$(MAKE) back-bootstrap

.PHONY: deploy-migrate
deploy-migrate: ## [Deployment] Run Doctrine migrations on prod (after make deploy)
	$(PROD) exec app php bin/console doctrine:migrations:migrate -n

.PHONY: dev
dev: ## [Deployment] start containers for local dev
	$(DEV) up --detach --build --remove-orphans --force-recreate
	@echo "\n$(COLOR_CYAN)Local IP:$(COLOR_RESET) $$(hostname -I | awk '{print $$1}')"

.PHONY: down
down: ## [Deployment] close containers on local dev
	docker compose --env-file .env --env-file .env.local -f compose.yml -f compose.dev.yml -f compose.prod.yml down
