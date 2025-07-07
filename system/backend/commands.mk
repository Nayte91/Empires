BACKEND = docker compose exec backend
PHP = $(BACKEND) php
CONSOLE = $(PHP) bin/console

.PHONY: back-install
back-install: ## [Back] Composer install
	$(BACKEND) composer install

.PHONY: back-update
back-update: ## [Back] Composer update
	$(BACKEND) composer update

.PHONY: back-tests
back-tests: ## [Back] Create test database and launch PHPUnit
	$(CONSOLE) --env=test cache:clear
	$(CONSOLE) --env=test doctrine:database:drop --force
	$(CONSOLE) --env=test doctrine:database:create
	$(CONSOLE) --env=test doctrine:schema:create
	$(PHP) bin/phpunit