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
