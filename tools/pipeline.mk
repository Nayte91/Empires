BACKEND = docker compose exec app
PHP = $(BACKEND) php
CONSOLE = $(PHP) bin/console
COMPOSER = $(BACKEND) composer
PARAMS ?=

.PHONY: quality phpstan phpcs rector phpunit

quality: ## [Quality] Run all quality checks (Rector, PHP-CS-Fixer, PHPStan, PHPUnit)
quality:
	@$(MAKE) rector PARAMS=$(PARAMS)
	@$(MAKE) phpcs PARAMS=$(PARAMS)
	@$(MAKE) phpstan PARAMS=$(PARAMS)
	@$(MAKE) phpunit PARAMS=$(PARAMS)

phpstan: ## [Quality] Run PHPStan static analysis (composer phpstan)
	@echo "🔍 Running PHPStan static analysis on: $(or $(PARAMS),src/)"
	$(COMPOSER) phpstan -- $(or $(PARAMS),src/)

phpcs: ## [Quality] Run PHP-CS-Fixer code style (composer phpcs)
	@echo "🎨 Running PHP-CS-Fixer code style check on: $(or $(PARAMS),src/)"
	$(COMPOSER) phpcs -- $(or $(PARAMS),src/)

rector: ## [Quality] Run Rector code modernization (composer rector)
	@echo "🔧 Applying Rector modernization on: $(or $(PARAMS),src/)"
	$(COMPOSER) rector -- $(or $(PARAMS),src/)

phpunit: ## [Quality] Run PHPUnit tests (composer phpunit)
	@echo "🧪 Running PHPUnit tests on: $(or $(PARAMS),tests/)"
	$(COMPOSER) phpunit -- $(or $(PARAMS),tests/)
