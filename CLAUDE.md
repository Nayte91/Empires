# CLAUDE.md - Empires Project

## 🎯 About the Project

**Empires** is a web-based strategy game adapting the Mega Empires / Mega Civilization board game, built with Symfony 8.1. It implements game session management, per-player boards, a shop/POS order lifecycle, and the Archaeological Succession Table (AST).

## 🛠 Tech Stack

### Backend
- **PHP** 8.5
- **Symfony** 8.1.* (Framework Bundle, Twig, Validator)
- **Doctrine** (ORM; migrations in `system/database/migrations/`, `System\` namespace)
- **Mercure** (real-time UI refresh, hub embedded in Caddy)

### Frontend
- **Twig** + **UX Twig/Live Components**
- **Stimulus** (`assets/controllers/`: `mercure-refresh`, `modal`)
- **Asset Mapper** (no build step)

### Infrastructure
- **Docker Compose**, single service: `app` (FrankenPHP — Caddy + PHP embedded), port **8020**
- Compose files: `compose.yml` + `compose.dev.yml` / `compose.prod.yml`

## 🐳 Docker Commands

```bash
docker compose exec app php bin/console [command]   # Symfony Console
docker compose exec app composer [command]          # Composer
```

## 📋 Makefile & Quality Pipeline

```bash
make dev / make down / make clean       # environment lifecycle
make quality                            # full pipeline: rector, phpcs, phpstan, phpunit
make rector|phpcs|phpstan|phpunit       # individual tools
make deploy / make deploy-migrate       # production
```

Composer equivalents (configs in `tools/`): `composer phpunit|phpstan|phpcs|rector` — each defaults to `src/`, pass paths after `--` to scope.

## 🏗 Project Architecture

```
src/
├── Component/    # Twig/Live Components (Ast, GameCreator, GameDashboard, OperatorConsole,
│                 #   PlayerBoard, Shop, OrderTab, Discounts, ThemeColors)
├── Controller/   # GameController, HomeController, PlayerController
├── DTO/          # Empire, AstEraDefinition (config-hydrated read models)
├── Entity/       # Game, Player, Order (Doctrine) + Advance (config-hydrated, not mapped)
├── Enumeration/  # ASTType (basic/expert), Category, OrderStatus
├── Game/         # ScenarioCatalog
├── Mapper/, Shop/
└── Repository/   # Doctrine repos + yaml config readers (see below)
```

### Config-driven game data
```
config/game/
├── advances.yaml    # advances + categories (colors)  → AdvanceRepository
├── ast.yaml         # AST eras, spans, basic/expert requirements → AstRepository
├── empires.yaml     # empires (name, color, icons)    → EmpireRepository
├── game_data.yaml   # regions, limits                 → GameData
└── scenarios.yaml   # empires per player count/region → Game\ScenarioCatalog
```
Yaml readers follow one pattern: `#[Autowire('%kernel.project_dir%/config/game/x.yaml')]` + lazy instance-property cache.

### Templates — Atomic Design
```
templates/
├── layout.html.twig  # root layout (all skeletons extend it; wires the ThemeColors atom)
├── atoms/            # themeColors (yaml → CSS custom properties, single color route)
├── molecules/        # ast, AstRequirements, Modal, Discounts, productCard, ...
├── organisms/        # gameDashboard, playerBoard, operatorConsole, shop, gameCreator
└── skeletons/        # full pages (one per route)
```
**Colors**: empire/advance colors are emitted once by the `ThemeColors` atom as `--empire-<slug>` / `--advance-<category>` CSS vars. Never resolve colors in PHP; use `var(--empire-{{ slug }}, dimgray)` in templates.

## 🎮 Business Domain

- **Game**: session (slug, currentTurn 1–20, region, `astType` basic/expert, players)
- **Player**: empire slug, advances (json), cities (0–9), census, treasury, `astPosition` (0–15)
- **AST**: 16-position track across 6 eras (Stone → Late Iron); era boundaries/requirements in `ast.yaml`; read-only board on the game dashboard
- **Shop/Orders**: player cart → pending order → operator validation (POS console)
- **Mercure**: components subscribe to topic `empires/game/{id}` (`mercure-refresh` Stimulus controller); publish on state changes

Routes: `/game/create`, `/game/{slug}` (dashboard), `/game/{slug}/operator`, player board & shop under `/game/{slug}/player/{slug}`.

## 🧪 Testing

```bash
make phpunit                                        # or: composer phpunit
docker compose exec app composer phpunit -- tests/Functional/AstTest.php
```

```
tests/
├── Entity/  Functional/  Game/  Repository/  Shop/  Support/
└── bootstrap.php        # DAMA doctrine-test-bundle for isolation
```

## 🔒 Environment

- `.env`: defaults (committed) · `.env.local`: local overrides (NOT committed)
- New APP_SECRET: `docker compose exec app php -r "echo bin2hex(random_bytes(26)), PHP_EOL;"`

## 🚀 Workflows

### Daily
```bash
make dev                # start (http://localhost:8020)
make quality            # before committing
```

### Adding features
1. Entities / config yaml → migration (`doctrine:migrations:diff`, lands in `system/database/migrations/`)
2. Repository / DTO following the yaml-reader pattern
3. Component + template (atomic design tier)
4. Tests (mirror the existing per-directory conventions)

Project TODO backlog: `TODO.md` at repo root.
