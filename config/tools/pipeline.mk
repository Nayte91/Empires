BACKEND = docker compose exec app
PHP = $(BACKEND) php
CONSOLE = $(PHP) bin/console
COMPOSER = $(BACKEND) composer
PARAMS ?=

LIB_DIR = packages/userforged/shop-engine
RECTOR_PATHS  = src/ tests/
PHPCS_PATHS   = src/
PHPSTAN_PATHS = src/

.PHONY: quality phpstan phpcs rector phpunit lib-quality lib-phpstan lib-phpcs lib-rector lib-phpunit

quality: ## [Quality] Run the app pipeline, then the shop-engine package pipeline
quality:
	@$(MAKE) rector PARAMS=$(PARAMS)
	@$(MAKE) phpcs PARAMS=$(PARAMS)
	@$(MAKE) phpstan PARAMS=$(PARAMS)
	@$(MAKE) phpunit PARAMS=$(PARAMS)
	@$(MAKE) lib-quality

phpstan: ## [Quality] Run PHPStan static analysis (composer phpstan)
	@echo "🔍 Running PHPStan static analysis on: $(or $(PARAMS),$(PHPSTAN_PATHS))"
	$(COMPOSER) phpstan -- $(or $(PARAMS),$(PHPSTAN_PATHS))

phpcs: ## [Quality] Run PHP-CS-Fixer code style (composer phpcs)
	@echo "🎨 Running PHP-CS-Fixer code style check on: $(or $(PARAMS),$(PHPCS_PATHS))"
	$(COMPOSER) phpcs -- $(or $(PARAMS),$(PHPCS_PATHS))

rector: ## [Quality] Run Rector code modernization (composer rector)
	@echo "🔧 Applying Rector modernization on: $(or $(PARAMS),$(RECTOR_PATHS))"
	$(COMPOSER) rector -- $(or $(PARAMS),$(RECTOR_PATHS))

phpunit: ## [Quality] Run PHPUnit tests (composer phpunit)
	@echo "🧪 Running PHPUnit tests on: $(or $(PARAMS),tests/)"
	$(COMPOSER) phpunit -- $(or $(PARAMS),tests/)

lib-quality: ## [Quality] Run rector, phpcs, phpstan and phpunit on packages/userforged/shop-engine
lib-quality:
	@$(MAKE) lib-rector
	@$(MAKE) lib-phpcs
	@$(MAKE) lib-phpstan
	@$(MAKE) lib-phpunit

lib-phpstan: ## [Quality] Run PHPStan on the shop-engine package
	@echo "🔍 Running PHPStan static analysis on: $(LIB_DIR)/src"
	$(BACKEND) vendor/bin/phpstan analyse --configuration=$(LIB_DIR)/config/tools/phpstan.php --memory-limit=1G

lib-phpcs: ## [Quality] Run PHP-CS-Fixer code style on the shop-engine package
	@echo "🎨 Running PHP-CS-Fixer code style check on: $(LIB_DIR)/src"
	$(BACKEND) vendor/bin/php-cs-fixer fix --config=$(LIB_DIR)/config/tools/php-cs-fixer.php

lib-rector: ## [Quality] Run Rector code modernization on the shop-engine package
	@echo "🔧 Applying Rector modernization on: $(LIB_DIR)/src $(LIB_DIR)/tests"
	$(BACKEND) vendor/bin/rector process --config=$(LIB_DIR)/config/tools/rector.php

lib-phpunit: ## [Quality] Run PHPUnit tests for the shop-engine package
	@echo "🧪 Running PHPUnit tests on: $(LIB_DIR)/tests"
	$(BACKEND) vendor/bin/phpunit --configuration=$(LIB_DIR)/config/tools/phpunit.xml
