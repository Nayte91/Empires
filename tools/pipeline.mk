BACKEND = docker compose exec app
PHP = $(BACKEND) php
CONSOLE = $(PHP) bin/console
COMPOSER = $(BACKEND) composer
PARAMS ?=

# userforged/shop-engine lives outside src/ but is first-party code: without this
# the whole library silently drops out of rector, phpcs and phpstan. Its tests are
# deliberately absent from PHPCS_PATHS/PHPSTAN_PATHS — see CLAUDE.md, "Which tool
# runs on tests/": rector owns test style, and phpstan/phpcs contradict it there.
LIB_SRC = packages/userforged/shop-engine/src/

RECTOR_PATHS  = src/ tests/ $(LIB_SRC)
PHPCS_PATHS   = src/ $(LIB_SRC)
PHPSTAN_PATHS = src/ $(LIB_SRC)

.PHONY: quality phpstan phpcs rector phpunit

quality: ## [Quality] Run all quality checks (Rector, PHP-CS-Fixer, PHPStan, PHPUnit)
quality:
	@$(MAKE) rector PARAMS=$(PARAMS)
	@$(MAKE) phpcs PARAMS=$(PARAMS)
	@$(MAKE) phpstan PARAMS=$(PARAMS)
	@$(MAKE) phpunit PARAMS=$(PARAMS)

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
