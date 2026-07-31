BACKEND = docker compose exec app
PHP = $(BACKEND) php
CONSOLE = $(PHP) bin/console

.PHONY: back-deps-install
back-deps-install: ## [Backend] composer install
	$(BACKEND) composer install

.PHONY: back-deps-update
back-deps-update: ## [Backend] composer update
	$(BACKEND) composer update

.PHONY: migrate
migrate: ## [Backend] Run Doctrine migrations
	$(CONSOLE) doctrine:migrations:migrate -n

.PHONY: back-bootstrap
back-bootstrap: ## [Backend] Generate secrets, run migrations
	-$(PROD) exec -T -u 0 app php bin/console secrets:generate-keys
	-$(PROD) exec -T -u 0 app php bin/console secrets:set APP_SECRET --random
	$(CONSOLE) doctrine:migrations:migrate --no-interaction
