# 🎯 About the project

**Empires** is a **companion app for live tabletop sessions** of the Civilization board game, built in PHP. It assists a real game played on a physical board.

The direct inspiration for this project's companion-then-host strategy is "CIV on rol-play.com". Study materials live in ./sources/civ.rol-play.com/.

## Civilization, the board game

Empires implements the rules lineage of Francis Tresham's **Civilization** (1980) — NOT Sid Meier's Civilization. The distinction is the rules lineage, not the medium: some video games share Tresham's core rules and belong to this family, while the "Sid Meier's Civilization" board game adaptations follow Meier's design and do not.

Same core rules (Tresham lineage):
- [Civilization](https://en.wikipedia.org/wiki/Civilization_(1980_board_game)) (1980)
- [Incunabula](https://en.wikipedia.org/wiki/Incunabula_(video_game)) (1984, video game)
- [Avalon Hill's Advanced Civilization](https://en.wikipedia.org/wiki/Advanced_Civilization) (1991) — and its [1996 PC port](https://en.wikipedia.org/wiki/Avalon_Hill%27s_Advanced_Civilization)
- [Mega Civilization](https://boardgamegeek.com/boardgame/184424/mega-civilization) (2015)
- [CIV on rol-play.com](https://civ.rol-play.com) (2015)
- [Western Empires](https://boardgamegeek.com/boardgame/267304/western-empires) (2019) / [Eastern Empires](https://boardgamegeek.com/boardgame/338980/eastern-empires) (2021)
- Mega Empires — [The West](https://mega-empires.com/introduction-mega-empires-the-west/) / [The East](https://mega-empires.com/introduction-mega-empires-the-east/) (2024)
- Mega Empires — [The Far East - North](https://mega-empires.com/introduction-mega-empires-the-far-east-north/) / [South](https://mega-empires.com/introduction-mega-empires-the-far-east-south/) / [The Silk Road](https://mega-empires.com/introduction-mega-empires-the-silk-road/) (2027)

NOT this family (Meier lineage, despite the name): every Sid Meier's Civilization video game, and their board game adaptations (Eagle Games 2002, FFG 2010, A New Dawn 2017).

**Mega Empires (2024) is the canonical ruleset for this project — when editions diverge, it wins.**
Detailed rules live in ./sources/rules/.

## Purpose

Empires assists the players driving a Civilization game: it relieves the table of its paper bookkeeping, and shows everyone a live, trustworthy picture of the game. That is the short-term goal — a game companion: purchases, calculations, strategy aids. The long-term goal reaches further: add a game engine, so the app can **host and play** a full game through these same screens. The project grows toward that goal screen by screen.

The operating context drives the design: a real game seats 3 to 18 players around a table for 10 to 20 hours, phone in hand.

### Present

Today the app owns the *bookkeeping* of the game, and the physical board owns everything else — map, movement, conflict, calamities. It implements game session management, per-player boards, a shop kiosk/POS order lifecycle (the operator plays the bank/cashier), and the stateboards (AST, census, city count).

A game is created on the server and rendered through different screens:
- for a **player**, who plays a specific empire (board, shop, saga),
- for **everyone**, to follow the leaderboard / operational state (dashboard, chronicle),
- for the **operator** — the canonical name for the game master, everywhere in code and doc — who keeps the game state up to date: turn progression, population & cities per player, order validation.

There are no accounts and no authentication — access is by slug URL, and trust is the table's trust. This is a deliberate product choice for now.

### Future

Two directions, in no committed order:
- the missing screens — first among them a representation of the game's geographic board;
- the game engine: the app becomes a host, a full game created and played through the same screens, no physical board required. Every screen built today for the companion is a screen the engine will drive tomorrow. The 🏗 Project Architecture chapter documents the design choices made toward this goal — including why the operator is, today, the human engine.

# 🛠 Tech Stack

## Backend
- **PHP** 8.5
- **Symfony** 8.1.* (Framework Bundle, Twig, Validator)
- **Mercure** (real-time UI refresh, hub embedded in Caddy)

## Frontend
- **Twig** + **UX Twig/Live Components**
- **Stimulus** (`assets/controllers/`: `mercure-refresh`, `modal`, `evolution`)
- **Asset Mapper** (no build step)

## Infrastructure
- **Docker Compose**, single service: `app` (FrankenPHP — Caddy + PHP embedded), port **8020** (Compose files: `compose.yml` + `compose.dev.yml` / `compose.prod.yml`)
- `system/` holds the project's infra: `Containerfile`, webserver config, database migrations

# ⚙️ Commands & Environment

```bash
make dev / make down / make clean       # environment lifecycle (http://localhost:8020)
make quality                            # full pipeline: rector, phpcs, phpstan, phpunit — run before committing
make rector|phpcs|phpstan|phpunit       # individual tools
make deploy / make deploy-migrate       # production

docker compose exec app php bin/console [command]   # Symfony Console
docker compose exec app composer [command]          # Composer
```

Composer equivalents (configs in `config/tools/`): `composer phpunit|phpstan|phpcs|rector` — pass paths after `--` to scope. Which tool covers which directory, and why: see 🧪 Testing.

- `.env`: defaults (committed) · `.env.local`: local overrides (NOT committed)
- New APP_SECRET: `docker compose exec app php -r "echo bin2hex(random_bytes(26)), PHP_EOL;"`

# 🏗 Project Architecture

`src/` is sorted by **application layer**, not by framework artefact. The goal it serves: Empires must be able to run *either* as free data entry (today) *or* driven by a game engine — the way a chess program lets you move pieces freely or play a real game.

**The keystone: free mode is not "the mode without an engine", it is the mode where the engine is human.** The operator view (`nextTurn` / `previousTurn` / `finishGame`) *is* the engine's clock, held by a person — the main thing a real engine would take away.

```
src/
├── State/           # the material state, passive: Game, Player, Order (Doctrine),
│                    #   plus the shape of any persisted column (CreditEntry, CreditSource, ASTVersion)
│   └── Repository/  #   the repository contracts — implemented by Infrastructure/, consumed by everyone
├── Rules/           # what is true / permitted / follows — never persists
│   ├── Ruleset/     #   the rules-as-data readers: *Registry + their read models
│   ├── Action/      #   which actions exist and when they are legal: Stat, StatAction, CreateGame
│   ├── Scenario/    #   what a prospective game will look like
│   └── *Calculator  #   Stock, Tax, HandSize, CitySupport, Score, Standings, CityBuild, CensusOrder
├── Engine/          # owns the only write path: Handler/ + Shop/ (fulfilment)
├── Presentation/    # Component/, Controller/, Twig/, Shop/ (user-facing messages),
│   └── Advisory/    #   the advisory (non-blocking) rules + PlayerAdvisor
├── Infrastructure/  # Repository/, Doctrine/, Mercure/, Shop/ (session + buyer lookup)
└── Kernel.php

packages/userforged/shop-engine/   # the ordering engine, extracted as a Composer package
                                   #   → has its own CLAUDE.md; read it before touching it
```

**Governing rule — `Rules` answers, `Rules` never persists.** That is what lets one rule set serve both modes: a rule that throws only serves an engine; a rule that returns a verdict serves both. Reading a repository from `Rules` is fine (`ShopConnector` does); writing is not.

**`Advisory/` sits under `Presentation/`, not `Rules/`.** An advisory is a courtesy the board extends to its players — "you are 6 population over your city count" — and the rules of the game are indifferent to it: delete the folder and nothing about the game changes, only a panel empties. Two consequences worth knowing before moving anything back. Each rule ends in a finished English sentence, so it is copy, and copy belongs where copy is written (`Presentation/Shop/ShopExceptionTranslator` is the same seam, one step further along: the engine raises a machine-readable reason and only `Presentation/` phrases it). And an advisory computes a derived fact before it phrases it — a would-be `Agent/` wants that half, so if one ever lands, the arithmetic moves back down to a `Rules/` calculator and the advisory keeps only the wording. See the `REFACTOR-WHEN` in `Advisory.php` for the threshold that forces the split.

**Dependency direction**, enforced by phpat (`tests/Architecture/LayerDependencies.php`) — a violation is a `make phpstan` error, nothing to run by hand:
```
Presentation ─┐
              ├──> Engine ──> Rules ──> State
Agent (future) ┘                └──> Ruleset (yaml)
```
`Infrastructure/` sits outside the arrows: **nobody names it** — the fourth phpat rule enforces that. Repositories are consumed through the `State/Repository/*Interface` contracts, wired by Symfony's singly-implemented-interface auto-aliasing (both sides live in the same `App\` scan, so there is no `services.yaml` entry to maintain — only the cross-package shop-engine ports need explicit aliases there).

`Engine/` is not speculative: four of a game engine's six organs already exist (`legal`, `next`, `score`, half of `terminal`). What is missing is the clock and the action log — the clock currently being the operator. There is deliberately **no `Agent/`**: unlike `Engine/`, it would have nothing to hold.

**Class names say the role, not the layer** — the namespace already says the layer. `App\Rules\TaxCalculator`, never `TaxRules` (stutter) nor `Tax` (reads as data). `Advisor`, `Summarizer`, `Resolver`, `Provider` coexist on purpose: each names a different job.

**Two standards, on purpose**: **the library applies best practices; the application applies what is proportionate.** `userforged/shop-engine` is written for a reader who is not us — every port documented, no host class named, no shortcut that a second consumer would inherit. The app is written for this game: an assumed `instanceof` on a type we construct ourselves, a JSON column where an entity would be over-engineering, a display aggregate that duplicates six lines rather than share a helper it would be tempting to merge. Neither standard is sloppiness — applying the library's rigour to the app wastes effort on a reader who will never exist, and applying the app's pragmatism to the library ships that shortcut to everyone.

**The shop is no longer a bounded context of `src/`** — it is `userforged/shop-engine`, a path-repository package with its own namespace (`Userforged\ShopEngine\`), bundle, tooling and tests. Its adapters are **split by layer, not grouped** — each keeps a `Shop/` path segment, so `find src -path '*/Shop/*'` finds them all, minus the renamed few (`CreditEntry`/`CreditSource` went to `State/`, `ShopMercurePublisher` to `Infrastructure/Mercure/`). The split is what makes `config/services.yaml`'s port→adapter bindings readable: you can see at a glance that exactly one port writes (`FulfillmentInterface` → `Engine/`) and all the others answer questions (`Rules/`) or reach outside (`Infrastructure/`). **The package must never name an `App\` class**; two greps guard that — see its `CLAUDE.md`.

## Config-driven game data
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

## Templates — Atomic Design
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

## Adding features
1. Entities / config yaml → migration (`doctrine:migrations:diff`, lands in `system/database/migrations/`, namespace `System\`)
2. Repository / bounded-context Dto following the yaml-reader pattern
3. Component + template (atomic design tier)
4. Tests (mirror the existing per-directory conventions)

Project TODO backlog: `TODO.md` at repo root.

# 🎮 Business Domain

- **Game**: session (slug, currentTurn 1–20, region, `astVersion` basic/expert, players)
- **Player**: empire slug, advances (json), cities (0–9), census, treasury, `astPosition` (0–15)
- **AST**: 16-position track across 6 eras (Stone → Late Iron); era boundaries/requirements in `ast.yaml`; read-only board on the game dashboard
- **Shop/Orders**: player cart → pending order → operator validation (POS console)
- **Mercure**: components subscribe to topic `empires/game/{id}` (`mercure-refresh` Stimulus controller); publish on state changes

Routes — the whole list, no `/game` prefix: `/`, `/create`, `/{slug}` (dashboard, or the chronicle once the game is finished), `/{slug}/operator`, `/{slug}/trade-cards`, `/{gameSlug}/player/{playerSlug}` (board, or the saga once the game is finished), `/{gameSlug}/player/{playerSlug}/shop`. Nothing links to `/{slug}/trade-cards` — deliberately, for now.

# 🧪 Testing

```bash
make quality                                        # the app pipeline (rector, phpcs, phpstan, phpunit)
make lib-quality                                    # the package only
docker compose exec app composer phpunit -- tests/Functional/AstTest.php   # single file, while iterating
```

```
tests/                                   # the application suite; tier first, layer second
├── Unit/          # pure TestCase, no kernel   ─┐ below this first level, the tree mirrors src/:
├── Integration/   # container + DB, no rendering ┤   Unit/State/, Unit/Presentation/Advisory/,
├── Component/     # Twig / Live component render ┘   Integration/Engine/Handler/, …
├── Functional/    # HTTP client + routes only     — crosses every layer, so it mirrors none
├── Support/       # hand-written doubles + the GameBuilder/PlayerBuilder object mother
└── bootstrap.php  # drops+recreates the SQLite schema once per run; DAMA (config/tools/phpunit.xml + bundles.php) rolls back each test

packages/userforged/shop-engine/tests/   # the engine suite, pure TestCase, no kernel
```

**Two suites, two gates**: `make quality` runs the app only, `make lib-quality` the package. Run the package's pipeline only when the diff touches `packages/`.

**The tier is the first thing you read, the layer the second.** The tier is decided by dependency, not by subject: an engine test that needs a kernel is not testing the engine, it is testing the wiring — so it lives in the app's `Integration/`, never in the package. Below the tier, the path mirrors `src/` — with two deliberate exceptions, `Functional/` and `Integration/ShopFlow/`: an end-to-end or flow test crosses every layer, so it inhabits none.

**Never run two phpunit invocations at once.** `bootstrap.php` drops and recreates the schema against a single shared SQLite file on every run, so concurrent suites destroy each other — the failure surfaces as an irreproducible flake. Delegations that touch `tests/` must be sequential.

## Which tool runs on `tests/` — and why the split is not negotiable

| Tool | `src/` | `tests/` | Why |
|---|:---:|:---:|---|
| **rector** | ✅ | ✅ | Owns the test style. |
| **php-cs-fixer** | ✅ | ❌ | Its risky PHPUnit set rewrites `assertEquals`→`assertSame`. On arrays of freshly-constructed readonly VOs that is **wrong** — `assertSame` demands instance identity and can never pass. This is why `assertEquals` is correct in `DirectSaleTest`/`OrderFlowTest` when comparing `OrderLine` graphs. |
| **phpstan** | ✅ | ❌ | It flags the very form rector enforces: `staticMethod.dynamicCall` ("Dynamic call to static method `Assert::assertSame()`") on every assertion. **Rector and phpstan can never agree on test files** — that is the whole reason phpstan is scoped out. |

`make quality` implements exactly this split — nothing else to run by hand. Never pass `PARAMS=tests/` to it, and never "settle" a test convention by running the pipeline over `tests/`: two of its three stages contradict each other there. To scope a run, call the tool directly (`composer rector -- tests/Unit/`). **Rector is the only authority on test style.**

## Assertion form — everything is `$this->`; only kernel statics are `self::`

**Assertions and expectations are `$this->`, kernel/container plumbing is `self::`** (`bootKernel`, `getContainer`, `ensureKernelShutdown`, `createClient`). Rector enforces the PHPUnit half; two families are convention only, so write them by hand:

- `assertResponse*` / `assertSelector*` (Symfony) — rector targets PHPUnit's own assertions only and leaves these alone: still `$this->`.
- `createStub`, `fail`, `markTestSkipped`, `markTestIncomplete` — genuinely static, rector does *not* rewrite them: still `$this->`.

Verify: `grep -rn 'self::assert' tests/` → 0. `$this->assertSame()` is deliberately a dynamic call to a static method — do not "correct" it back.

## Conventions

- **`#[Test]` attribute, never a `test*` prefix.** Attributes from `PHPUnit\Framework\Attributes\`, never doc-comment annotations.
- **Names are behaviour sentences**, articles spelled out: `aValidatedOrderWithLeftoverCartItemsKeepsSubmitDisabled`, `addingAnAlreadyOwnedAdvanceIsRefused`.
- **AAA by blank lines, never `// Arrange` comments.** Docblocks explain *why a test exists*, not what it does.
- **The directory now states the base class**, which is the point of the layer split: `Unit/` is pure `TestCase`, everything else needs the container (`WebTestCase` is the de-facto base; `KernelTestCase` is used once and is an anomaly). A test whose base class contradicts its directory is misfiled — move it rather than justify it.
- **No PHPUnit mocks.** Zero `createMock`/`createStub`/`MockObject` in the suite, deliberately. Use real objects, plus the hand-written doubles in `tests/Support/` (`NullHub` substituted for `HubInterface` under `when@test`, `ShopOrderStateMachine::create()`) and, on the package side, `packages/userforged/shop-engine/tests/Support/FakeProduct`.
- **No cleanup, no `tearDown()`.** DAMA rolls every test back. To re-read what the DB stored, `$em->clear()` before re-fetching — note `freshEntityManager()`-style helpers return the *same* instance and do **not** reset the identity map.
- **Private helpers at the bottom of the class**, after all `#[Test]` methods. Prefer aligning on an existing helper's name and signature — `createPlayer()`, `createCart()` and `makeAdvance()` currently have several incompatible signatures across files; don't add a new variant.
- **Data providers**: none exist yet. When adding the first, name it `provide<TestMethodPascal>Cases()`, `public static`, return `iterable`, blank line between yields — rector's PHPUnit set runs on `tests/` and will rewrite anything else.

## Functional selectors — semantics first, styling classes are a last resort

Priority: native semantic tag → `id` → `data-*` → ARIA role. **Avoid CSS classes**: they are presentational and rename on restyling.

The testbase does not fully honour this yet — a minority of crawler selectors still target BEM classes, `.shop__submit` being the most-relied-on selector in the suite. Treat those as debt: don't add new ones, and when a template you touch already has a semantic hook, migrate the assertion.

Consequence for the CSS-only skip rule (below/global): it does **not** apply here — a Twig `class` change can break tests, so run the suite.
