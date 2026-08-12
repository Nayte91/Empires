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
- **Stimulus** (`assets/controllers/`: `mercure-refresh`, `modal`, `evolution`)
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

`src/` is sorted by **application layer**, not by framework artefact. The goal it serves: Empires must be able to run *either* as free data entry (today) *or* driven by a game engine — the way a chess program lets you move pieces freely or play a real game.

**The keystone: free mode is not "the mode without an engine", it is the mode where the engine is human.** The operator view (`nextTurn` / `previousTurn` / `finishGame`) *is* the engine's clock, held by a person — the main thing a real engine would take away.

```
src/
├── State/           # the material state, passive: Game, Player, Order (Doctrine),
│                    #   plus the shape of any persisted column (CreditEntry, CreditSource, ASTVersion)
├── Rules/           # what is true / permitted / follows — never persists
│   ├── Ruleset/     #   the rules-as-data readers: *Registry + their read models
│   ├── Action/      #   which actions exist and when they are legal: Stat, StatAction, CreateGame
│   ├── Advisory/    #   the advisory (non-blocking) rules + PlayerAdvisor
│   ├── Scenario/    #   what a prospective game will look like
│   └── *Calculator  #   Stock, Tax, HandSize, CitySupport, Score, Standings, CityBuild, CensusOrder
├── Engine/          # owns the only write path: Handler/ + Shop/ (fulfilment)
├── Presentation/    # Component/, Controller/, Twig/, Shop/ (user-facing messages)
├── Infrastructure/  # Repository/, Doctrine/, Mercure/, Shop/ (session + buyer lookup)
└── Kernel.php

packages/userforged/shop-engine/   # the ordering engine, extracted as a Composer package
                                   #   → has its own CLAUDE.md; read it before touching it
```

**Governing rule — `Rules` answers, `Rules` never persists.** That is what lets one rule set serve both modes: a rule that throws only serves an engine; a rule that returns a verdict serves both. Reading a repository from `Rules` is fine (`ShopConnector` does); writing is not.

**Dependency direction**, enforced by three greps — run them after any move:
```
Presentation ─┐
              ├──> Engine ──> Rules ──> State          Infrastructure: nobody names it,
Agent (future) ┘                └──> Ruleset (yaml)     everyone receives it by interface

grep -rn '^use App\Engine\|^use App\Presentation'          src/Rules/ src/State/   → 0
grep -rn 'EntityManagerInterface\|->flush()\|->persist('   src/Rules/ src/State/   → 0
grep -rn '^use App\Presentation'                           src/Engine/             → 0
```

`Engine/` is not speculative: four of a game engine's six organs already exist (`legal`, `next`, `score`, half of `terminal`). What is missing is the clock and the action log — the clock currently being the operator. There is deliberately **no `Agent/`**: unlike `Engine/`, it would have nothing to hold.

**Class names say the role, not the layer** — the namespace already says the layer. `App\Rules\TaxCalculator`, never `TaxRules` (stutter) nor `Tax` (reads as data). `Advisor`, `Summarizer`, `Resolver`, `Provider` coexist on purpose: each names a different job.

**Two standards, on purpose**: **the library applies best practices; the application applies what is proportionate.** `userforged/shop-engine` is written for a reader who is not us — every port documented, no host class named, no shortcut that a second consumer would inherit. The app is written for this game: an assumed `instanceof` on a type we construct ourselves, a JSON column where an entity would be over-engineering, a display aggregate that duplicates six lines rather than share a helper it would be tempting to merge. Neither standard is sloppiness — applying the library's rigour to the app wastes effort on a reader who will never exist, and applying the app's pragmatism to the library ships that shortcut to everyone.

**The shop is no longer a bounded context of `src/`** — it is `userforged/shop-engine`, a path-repository package with its own namespace (`Userforged\ShopEngine\`), bundle, tooling and tests. Its 15 adapters are **split by layer, not grouped** — each keeps a `Shop/` path segment, so `find src -path '*/Shop/*'` still finds the twelve that kept it (`CreditEntry`/`CreditSource` went to `State/`, `ShopMercurePublisher` to `Infrastructure/Mercure/`). The split is what makes `config/services.yaml`'s seven port→adapter bindings readable: you can see at a glance that exactly one port writes (`FulfillmentInterface` → `Engine/`) and all the others answer questions (`Rules/`) or reach outside (`Infrastructure/`). **The package must never name an `App\` class**; two greps guard that — see its `CLAUDE.md`.

### Config-driven game data
```
config/game/
├── advances.yaml    # advances + categories (colors)  → AdvanceRegistry
│                   #   per-advance `effects:` (the rules an advance bends) → AdvanceEffectRegistry
├── ast.yaml         # AST eras, spans, basic/expert requirements → AstRegistry
├── empires.yaml     # empires (name, color, icons)    → EmpireRegistry
├── game_data.yaml   # regions, limits                 → GameRegistry
└── scenarios.yaml   # empires per player count/region → ScenarioRegistry
```
Yaml readers follow one pattern: `#[Autowire('%kernel.project_dir%/config/game/x.yaml')]` + lazy instance-property cache.

### Templates — Atomic Design
```
templates/
├── layout.html.twig  # root layout (all skeletons extend it; wires the ThemeColors atom)
├── atoms/            # themeColors (yaml → CSS custom properties, single color route), PageTitle, Marker, tab
├── molecules/        # ast, AstRequirements, Discounts, productTile, StatPicker, TradeCards, ...
├── organisms/        # gameDashboard, playerBoard, operatorConsole, shop, gameCreator, playerSaga
└── skeletons/        # full pages, split Game/ and Player/ — `assets/styles/skeletons/` mirrors it
```
**Colors**: empire/advance colors are emitted once by the `ThemeColors` atom as `--empire-<slug>` / `--advance-<category>` CSS vars. Never resolve colors in PHP; use `var(--empire-{{ slug }}, dimgray)` in templates.

**Live Components — `data-loading` vs conditional `disabled`**: never put an unconditional `data-loading="addAttribute(disabled)"` on an element that also has a business-conditional `disabled` — the loading plugin strips `addAttribute`/`removeAttribute` directives on mount ([symfony/ux#372](https://github.com/symfony/ux/issues/372)), silently re-enabling it. Wrap the directive in the inverted condition:
```twig
{% set isDisabled = <condition> %}
<button {{ isDisabled ? 'disabled' }} {% if not isDisabled %}data-loading="addAttribute(disabled)"{% endif %}>
```

## 🎮 Business Domain

- **Game**: session (slug, currentTurn 1–20, region, `astVersion` basic/expert, players)
- **Player**: empire slug, advances (json), cities (0–9), census, treasury, `astPosition` (0–15)
- **AST**: 16-position track across 6 eras (Stone → Late Iron); era boundaries/requirements in `ast.yaml`; read-only board on the game dashboard
- **Shop/Orders**: player cart → pending order → operator validation (POS console)
- **Mercure**: components subscribe to topic `empires/game/{id}` (`mercure-refresh` Stimulus controller); publish on state changes

Routes — the whole list, no `/game` prefix: `/`, `/create`, `/{slug}` (dashboard, or the chronicle once the game is finished), `/{slug}/operator`, `/{slug}/trade-cards`, `/{gameSlug}/player/{playerSlug}` (board, or the saga once the game is finished), `/{gameSlug}/player/{playerSlug}/shop`. Nothing links to `/{slug}/trade-cards` — deliberately, for now.

## 🧪 Testing

```bash
make quality                                        # the app pipeline (rector, phpcs, phpstan, phpunit)
make lib-quality                                    # the package only
docker compose exec app composer phpunit -- tests/Functional/AstTest.php   # single file, while iterating
```

```
tests/                                   # 760 tests — the application; tier first, layer second
├── Unit/          # pure TestCase, no kernel   ─┐ below this first level, the tree mirrors src/:
├── Integration/   # container + DB, no rendering ┤   Unit/State/, Unit/Rules/Advisory/,
├── Component/     # Twig / Live component render ┘   Integration/Engine/Handler/, …
├── Functional/    # HTTP client + routes only     — crosses every layer, so it mirrors none
├── Support/       # hand-written doubles + the GameBuilder/PlayerBuilder object mother
└── bootstrap.php  # drops+recreates the SQLite schema once per run; DAMA (config/tools/phpunit.xml + bundles.php) rolls back each test

packages/userforged/shop-engine/tests/   # 121 tests — the engine, pure TestCase, no kernel
```

**760 + 121 = 881.** The two suites are separate and have separate gates: `make quality` runs the app only, `make lib-quality` the package. Run the package's pipeline only when the diff touches `packages/`.

**The tier is the first thing you read, the layer the second.** The tier is decided by dependency, not by subject: an engine test that needs a kernel is not testing the engine, it is testing the wiring — so it lives in the app's `Integration/`, never in the package. Below the tier, the path mirrors `src/` — with two deliberate exceptions, `Functional/` and `Integration/ShopFlow/`: an end-to-end or flow test crosses every layer, so it inhabits none.

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
