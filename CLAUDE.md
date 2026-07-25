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
├── Entity/       # GameSession, Player, Order (Doctrine) — technical persistence layer only
├── Game/         # bounded context: ScenarioCatalog, AdvanceCatalog, AstCatalog, EmpireCatalog,
│                 #   GameData (yaml config readers), ASTType/Category enums, Dto/ (Advance, Empire,
│                 #   AstEraDefinition read models), Service/ (ScoreCalculator)
├── Repository/   # Doctrine repos (GameSessionRepository, PlayerRepository, OrderRepository)
└── Shop/         # bounded context: Cart, CartRepository, OrderStatus enum, Dto/ (Product),
                  #   Service/ (DirectSale, OrderSubmitter, OrderValidator, PriceCalculator)
```
**Governing rule**: `Entity/` & `Repository/` are the technical Doctrine layer, shared across the app. Each bounded context (`Game/`, `Shop/`) owns everything else that belongs to it — value objects, enums, non-persisted state, services.

### Config-driven game data
```
config/game/
├── advances.yaml    # advances + categories (colors)  → AdvanceCatalog
├── ast.yaml         # AST eras, spans, basic/expert requirements → AstCatalog
├── empires.yaml     # empires (name, color, icons)    → EmpireCatalog
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

**Live Components — `data-loading` vs conditional `disabled`**: never put an unconditional `data-loading="addAttribute(disabled)"` on an element that also has a business-conditional `disabled` — the loading plugin strips `addAttribute`/`removeAttribute` directives on mount ([symfony/ux#372](https://github.com/symfony/ux/issues/372)), silently re-enabling it. Wrap the directive in the inverted condition:
```twig
{% set isDisabled = <condition> %}
<button {{ isDisabled ? 'disabled' }} {% if not isDisabled %}data-loading="addAttribute(disabled)"{% endif %}>
```

## 🎮 Business Domain

- **GameSession**: session (slug, currentTurn 1–20, region, `astType` basic/expert, players)
- **Player**: empire slug, advances (json), cities (0–9), census, treasury, `astPosition` (0–15)
- **AST**: 16-position track across 6 eras (Stone → Late Iron); era boundaries/requirements in `ast.yaml`; read-only board on the game dashboard
- **Shop/Orders**: player cart → pending order → operator validation (POS console)
- **Mercure**: components subscribe to topic `empires/game/{id}` (`mercure-refresh` Stimulus controller); publish on state changes

Routes (no `/game` prefix — verify with `debug:router` before assuming): `/create`, `/{slug}` (dashboard), `/{slug}/ast`, `/{slug}/census`, `/{slug}/operator`, `/{gameSlug}/player/{playerSlug}` (board), `/{gameSlug}/player/{playerSlug}/shop`.

## 🧪 Testing

```bash
make quality                                        # THE command: rector+phpcs+phpstan on src/, phpunit on tests/
docker compose exec app composer phpunit -- tests/Functional/AstTest.php   # single file, while iterating
```

```
tests/
├── Entity/  Functional/  Game/  Repository/  Shop/   # Support/ = hand-written doubles, not PHPUnit mocks
└── bootstrap.php   # drops+recreates the SQLite schema once per run; DAMA (tools/phpunit.xml + bundles.php) rolls back each test
```

### Never run the quality tools on `tests/`

`tools/{rector,phpstan,php-cs-fixer}.php` all target **`src/` only**, by design. `make quality` already does the right thing: static tools on `src/`, PHPUnit on `tests/`. **Never** pass `PARAMS=tests/` to `make quality`, and never call `composer rector/phpcs/phpstan -- tests/…`.

Rationale — two rules actively corrupt this testbase:
- `php_unit_strict` (`@PhpCsFixer:risky`) rewrites `assertEquals`→`assertSame`. On arrays of freshly-constructed readonly VOs that is **wrong**: `assertSame` demands instance identity and can never pass. This is why `assertEquals` is correct in `DirectSaleTest`/`OrderFlowTest` when comparing `OrderLine` graphs.
- `PreferPHPUnitThisCallRector` rewrites `self::assert*`→`$this->assert*`, which contradicts the convention below.

The testbase's style is settled by the rules here and by review, **not** by tooling.

### Assertion form — `self::` vs `$this->` is not taste, it's the method's nature

Verified by reflection, not habit:

| Call | Nature | Form |
|---|---|---|
| `assert*` (PHPUnit `Assert` + Symfony `assertResponse*`/`assertSelector*`) | static | `self::` |
| `markTestSkipped`, `markTestIncomplete` | static | `self::` |
| `expectException`, `expectExceptionMessage`, `expectExceptionMessageMatches` | **instance** | `$this->` |
| LiveComponent trait helpers (`createLiveComponent`, `assertComponentEmitEvent`, …) | **instance** | `$this->` |

So `self::assertSame(...)` next to `$this->expectException(...)` in the same method is **correct**, not drift. Dominant in the testbase (550 `self::` / 30 files); the 5 Shop/POS functional files still on `$this->assert*` are the drift — clean them up when you touch them.

### Conventions

- **`#[Test]` attribute, never a `test*` prefix.** 340/340 methods comply. Attributes from `PHPUnit\Framework\Attributes\`, never doc-comment annotations.
- **Names are behaviour sentences**, articles spelled out: `aValidatedOrderWithLeftoverCartItemsKeepsSubmitDisabled`, `addingAnAlreadyOwnedAdvanceIsRefused`.
- **AAA by blank lines, never `// Arrange` comments.** Docblocks explain *why a test exists*, not what it does.
- **Base class follows the need, not the directory**: pure object → `TestCase`; needs the container or DB → `WebTestCase` (the de-facto base here — `KernelTestCase` is used once and is an anomaly). `tests/Shop/` and `tests/Game/` are each ~half unit, half DB — the folder does not predict the base class.
- **No PHPUnit mocks.** Zero `createMock`/`createStub`/`MockObject` in the suite, deliberately. Use real objects, plus the hand-written doubles in `tests/Support/` (`NullHub` substituted for `HubInterface` under `when@test`, `ShopOrderStateMachine::create()`, `tests/Shop/Support/FakeProduct`).
- **No cleanup, no `tearDown()`.** DAMA rolls every test back. To re-read what the DB stored, `$em->clear()` before re-fetching — note `freshEntityManager()`-style helpers return the *same* instance and do **not** reset the identity map.
- **Private helpers at the bottom of the class**, after all `#[Test]` methods. Prefer aligning on an existing helper's name and signature — `createPlayer()`, `createCart()` and `makeAdvance()` currently have several incompatible signatures across files; don't add a new variant.
- **Data providers**: none exist yet. When adding the first, name it `provide<TestMethodPascal>Cases()`, `public static`, return `iterable`, blank line between yields — so the suite stays consistent if rector's PHPUnit set is ever pointed at `tests/`.

### Functional selectors — semantics first, styling classes are a last resort

Priority: native semantic tag → `id` → `data-*` → ARIA role. **Avoid CSS classes**: they are presentational and rename on restyling.

The testbase does not fully honour this yet — 18 of 85 crawler selectors target BEM classes, `.shop__submit` being the most-relied-on selector in the suite. Treat those as debt: don't add new ones, and when a template you touch already has a semantic hook, migrate the assertion.

Consequence for the CSS-only skip rule (below/global): it does **not** apply here — a Twig `class` change can break tests, so run the suite.

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
2. Repository / bounded-context Dto following the yaml-reader pattern
3. Component + template (atomic design tier)
4. Tests (mirror the existing per-directory conventions)

Project TODO backlog: `TODO.md` at repo root.
