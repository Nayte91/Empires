DATESTAMP = ${shell date +%d-%m-%G}
COLOR_RESET = \033[0m
COLOR_CYAN = \033[36m
.DEFAULT_GOAL := help

include system/components.mk

.PHONY: help
help: ## [Help] This help
	@makefiles=$$(echo $(MAKEFILE_LIST) | grep Makefile); \
	grep -hE '^[2a-zA-Z_-]+:.*?## .*$$' $$makefiles | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "$(COLOR_CYAN)%-30s$(COLOR_RESET) %s\n", $$1, $$2}'

.PHONY: clean
clean: ## [Deployment] Stop containers and clean caches & assets
	docker compose down
	rm -rf var/cache/
	#rm -rf vendor/
	rm -rf public/assets/
	rm -rf config/secrets/

.PHONY: pull
pull: ## [Deployment] git pull, the right way
	git stash
	git pull -r origin production
	git stash pop

.PHONY: deploy
deploy: ## [Deployment] Start containers and launch startup commands
	docker compose -f compose.yaml -f compose.prod.yaml up --build -d
	$(BACKEND) composer install --ignore-platform-reqs --no-interaction --prefer-dist --no-dev
	$(BACKEND) composer dump-autoload --classmap-authoritative --optimize
	$(CONSOLE) secrets:generate-keys
	$(CONSOLE) importmap:install
	$(CONSOLE) asset-map:compile
	$(CONSOLE) doctrine:migrations:migrate -n
	$(CONSOLE) cache:warmup

.PHONY: dev
dev: ## [Deployment] start containers for local dev
	docker compose -f compose.yaml -f compose.dev.yaml up -d --build --remove-orphans --force-recreate

.PHONY: down
down: ## [Deployment] close containers on local dev
	docker compose -f compose.yaml -f compose.dev.yaml -f compose.prod.yaml down --remove-orphans
