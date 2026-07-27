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

Composer equivalents (configs in `config/tools/`): `composer phpunit|phpstan|phpcs|rector` — pass paths after `--` to scope. Bare defaults come from each tool's own config: `phpstan`/`phpcs` are `src/`-only, `rector` covers `src/` **and** `tests/` (see 🧪 Testing for why).

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
└── Repository/   # Doctrine repos (GameSessionRepository, PlayerRepository, OrderRepository)

packages/userforged/shop-engine/   # the ordering engine, extracted as a Composer package
                                   #   → has its own CLAUDE.md; read it before touching it
```
**Governing rule**: `Entity/` & `Repository/` are the technical Doctrine layer, shared across the app. The `Game/` bounded context owns everything else that belongs to it — value objects, enums, non-persisted state, services.

**Two standards, on purpose**: **the library applies best practices; the application applies what is proportionate.** `userforged/shop-engine` is written for a reader who is not us — every port documented, no host class named, no shortcut that a second consumer would inherit. The app is written for this game: an assumed `instanceof` on a type we construct ourselves, a JSON column where an entity would be over-engineering, a display aggregate that duplicates six lines rather than share a helper it would be tempting to merge. Neither standard is sloppiness — applying the library's rigour to the app wastes effort on a reader who will never exist, and applying the app's pragmatism to the library ships that shortcut to everyone.

**The shop is no longer a bounded context of `src/`** — it is `userforged/shop-engine`, a path-repository package with its own namespace (`Userforged\ShopEngine\`), bundle, tooling and tests. `src/Game/Shop/` holds the *adapters* that connect it to the game (`PlayerBuyerProvider`, `AdvanceProductProvider`, `AdvanceFulfillment`, `ShopConnector`, `SessionCartStorage`, `ShopMercurePublisher`, `ShopExceptionTranslator`), and `config/services.yaml` declares the six port→adapter bindings explicitly. **The package must never name an `App\` class**; two greps guard that — see its `CLAUDE.md`.

### Config-driven game data
```
config/game/
├── advances.yaml    # advances + categories (colors)  → AdvanceCatalog
│                   #   per-advance `effects:` (the rules an advance bends) → AdvanceEffectCatalog
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
make quality                                        # the app pipeline (rector, phpcs, phpstan, phpunit)
make lib-quality                                    # the package only
docker compose exec app composer phpunit -- tests/Functional/AstTest.php   # single file, while iterating
```

```
tests/                                   # 432 tests — the application, sorted by layer
├── Unit/          # pure TestCase, no kernel
├── Integration/   # container + DB, no rendering
├── Component/     # Twig / Live component render
├── Functional/    # HTTP client + routes only
├── Support/       # hand-written doubles + the GameBuilder/PlayerBuilder object mother
└── bootstrap.php  # drops+recreates the SQLite schema once per run; DAMA (config/tools/phpunit.xml + bundles.php) rolls back each test

packages/userforged/shop-engine/tests/   # 121 tests — the engine, pure TestCase, no kernel
```

**432 + 121 = 553.** The two suites are separate and have separate gates: `make quality` runs the app only, `make lib-quality` the package. Run the package's pipeline only when the diff touches `packages/`.

**The layer is the first thing you read, the domain the second.** The tier is decided by dependency, not by subject: an engine test that needs a kernel is not testing the engine, it is testing the wiring — so it lives in the app's `Integration/`, never in the package.

**Never run two phpunit invocations at once.** `bootstrap.php` drops and recreates the schema against a single shared SQLite file on every run, so concurrent suites destroy each other — the failure surfaces as an irreproducible flake. Delegations that touch `tests/` must be sequential.

### Which tool runs on `tests/` — and why the split is not negotiable

| Tool | `src/` | `tests/` | Why |
|---|:---:|:---:|---|
| **rector** | ✅ | ✅ | Owns the test style. Aligned with `Userforged/Ephemere`. |
| **php-cs-fixer** | ✅ | ❌ | `php_unit_strict` (`@PhpCsFixer:risky`) rewrites `assertEquals`→`assertSame`. On arrays of freshly-constructed readonly VOs that is **wrong** — `assertSame` demands instance identity and can never pass. This is why `assertEquals` is correct in `DirectSaleTest`/`OrderFlowTest` when comparing `OrderLine` graphs. |
| **phpstan** | ✅ | ❌ | It flags the very form rector enforces: 25 × `staticMethod.dynamicCall` ("Dynamic call to static method `Assert::assertSame()`") on a single test file. **Rector and phpstan can never agree on test files** — that is the whole reason phpstan is scoped out. |

`make quality` implements exactly this split: `rector -- src/ tests/`, then `phpcs -- src/`, `phpstan -- src/`, then the full suite. Nothing else to run by hand.

Never pass `PARAMS=tests/` to `make quality` — that would drag phpcs and phpstan into `tests/`, which is the one thing this split exists to prevent. To scope a run, call the tool directly (`composer rector -- tests/Unit/`).

Corollary: never "settle" a test convention by running the full pipeline over `tests/` — two of its three stages contradict each other there. **Rector is the only authority on test style.**

### Assertion form — everything is `$this->`; only kernel statics are `self::`

| Call | Form | Enforced by |
|---|---|---|
| `assert*` (PHPUnit) | `$this->` | rector, `PreferPHPUnitThisCallRector` |
| `expectException*`, mock matchers (`once`, `never`, `any`, `exactly`, …), `createMock` | `$this->` | same rector rule — its `NonAssertNonStaticMethods` whitelist covers them |
| `assertResponse*`, `assertSelector*` (Symfony) | `$this->` | convention — rector's rule targets `PHPUnit\Framework\Assert` only and leaves these alone, so write `$this->` |
| `createStub`, `fail`, `markTestSkipped`, `markTestIncomplete` | `$this->` | convention only — these are **static** and rector does *not* rewrite them; both testbases still use `$this->` |
| `bootKernel`, `getContainer`, `ensureKernelShutdown`, `createClient` | **`self::`** | genuinely static, and left alone by rector |

Simple rule: **assertions and expectations are `$this->`, kernel/container plumbing is `self::`.** 772 `$this->assert*` / 0 `self::assert*`, matching Ephemere (2156 / 0).

`$this->assertSame()` is deliberately a dynamic call to a static method — that is why phpstan must not see `tests/`. Do not "correct" it back.

### Conventions

- **`#[Test]` attribute, never a `test*` prefix.** 340/340 methods comply. Attributes from `PHPUnit\Framework\Attributes\`, never doc-comment annotations.
- **Names are behaviour sentences**, articles spelled out: `aValidatedOrderWithLeftoverCartItemsKeepsSubmitDisabled`, `addingAnAlreadyOwnedAdvanceIsRefused`.
- **AAA by blank lines, never `// Arrange` comments.** Docblocks explain *why a test exists*, not what it does.
- **The directory now states the base class**, which is the point of the layer split: `Unit/` is pure `TestCase`, everything else needs the container (`WebTestCase` is the de-facto base; `KernelTestCase` is used once and is an anomaly). A test whose base class contradicts its directory is misfiled — move it rather than justify it.
- **No PHPUnit mocks.** Zero `createMock`/`createStub`/`MockObject` in the suite, deliberately. Use real objects, plus the hand-written doubles in `tests/Support/` (`NullHub` substituted for `HubInterface` under `when@test`, `ShopOrderStateMachine::create()`) and, on the package side, `packages/userforged/shop-engine/tests/Support/FakeProduct`.
- **No cleanup, no `tearDown()`.** DAMA rolls every test back. To re-read what the DB stored, `$em->clear()` before re-fetching — note `freshEntityManager()`-style helpers return the *same* instance and do **not** reset the identity map.
- **Private helpers at the bottom of the class**, after all `#[Test]` methods. Prefer aligning on an existing helper's name and signature — `createPlayer()`, `createCart()` and `makeAdvance()` currently have several incompatible signatures across files; don't add a new variant.
- **Data providers**: none exist yet. When adding the first, name it `provide<TestMethodPascal>Cases()`, `public static`, return `iterable`, blank line between yields — rector's PHPUnit set runs on `tests/` and will rewrite anything else.

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
