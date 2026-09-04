# 🎯 Project

**Empires** is a PHP companion app for live tabletop sessions of Tresham's *Civilization* (1980
lineage, **not** Sid Meier's). **Mega Empires (2024) is the canonical ruleset**; rules in
`sources/rules/`, lineage and sources in `docs/lineage.md`.

- Today: the app owns the game's *bookkeeping* (sessions, per-player boards, shop order lifecycle,
  AST/census/city stateboards); the physical board owns map, movement, conflict, calamities.
- Tomorrow: a game engine hosting a full game through the same screens. Every screen is built for
  that.
- Three audiences: the **player** (board, shop, saga), **everyone** (dashboard, chronicle), the
  **operator** — the canonical name for the game master, everywhere — who drives turns, stats and
  order validation. The operator is the engine's clock, held by a human (`docs/architecture.md`).
- No accounts, no auth: access by slug URL, deliberately. 3–18 players, 10–20 hours, phone in hand.

# 🛠 Stack & commands

PHP 8.5 · Symfony 8.1 · Twig + UX Twig/Live Components · Stimulus (`assets/controllers/`:
`mercure-refresh`, `modal`, `evolution`) · Asset Mapper (no build) · Mercure (hub in Caddy) ·
Docker Compose, single service `app` (FrankenPHP), port 8020, `compose.yml` + `compose.dev|prod.yml`
· infra in `system/` (Containerfile, webserver, migrations).

```bash
make dev / make down / make clean       # environment (http://localhost:8020)
make quality                            # rector, phpcs, phpstan, phpunit — before committing
make lib-quality                        # the shop-engine package only — only when packages/ changes
make deploy / make deploy-migrate
docker compose exec app php bin/console …
docker compose exec app composer phpunit|phpstan|phpcs|rector -- <paths>   # scoped runs
```

`.env` committed defaults, `.env.local` overrides. Backlog: `TODO.md`.

# 🏗 Architecture

Layers, not framework artefacts. Rationale in `docs/architecture.md`.

```
src/
├── State/           # passive material state: Game, Player, Order + persisted column shapes
│   └── Repository/  #   contracts, implemented by Infrastructure/, consumed by everyone
├── Rules/           # what is true / permitted / follows — NEVER persists
│   ├── Ruleset/     #   yaml readers: *Registry + read models
│   ├── Action/      #   which actions exist and when: Stat, StatAction, CreateGame
│   ├── Scenario/    #   what a prospective game looks like
│   └── *Calculator  #   Stock, Tax, HandSize, CitySupport, Score, Standings, CityBuild, CensusOrder
├── Engine/          # the only write path: Handler/ + Shop/ (fulfilment)
├── Presentation/    # Component/, Controller/, Twig/, Shop/ (user-facing messages)
│   └── Advisory/    #   non-blocking advice + PlayerAdvisor — copy, so it lives here, not in Rules/
└── Infrastructure/  # Repository/, Doctrine/, Mercure/, Shop/ (session + buyer lookup)

packages/userforged/shop-engine/   # the ordering engine — own CLAUDE.md, read it before touching it
```

- **Dependency direction**, enforced by phpat (`tests/Architecture/LayerDependencies.php`, fails
  `make phpstan`): `Presentation → Engine → Rules → State`; `Rules → Ruleset (yaml)`.
  **Nobody names `Infrastructure/`** — consume `State/Repository/*Interface`, auto-aliased.
- **`Rules` answers, never persists.** Return a verdict, do not throw: one rule set serves both the
  free-entry screens and the future engine. Reading a repository from `Rules` is fine.
- **Class names say the role, not the layer**: `App\Rules\TaxCalculator`, never `TaxRules` nor `Tax`.
  `Advisor`, `Summarizer`, `Resolver`, `Provider` name different jobs.
- **Two standards**: the package applies best practices for an unknown reader; the app applies what
  is proportionate to this one game. **The package never names an `App\` class** (its `CLAUDE.md`
  has the guard greps). Its app-side adapters all keep a `Shop/` path segment:
  `find src -path '*/Shop/*'`.
- **Game data is yaml** in `config/game/` (`advances`, `ast`, `empires`, `game_data`, `scenarios`),
  one `*Registry` reader each: `#[Autowire('%kernel.project_dir%/config/game/x.yaml')]` + lazy
  instance-property cache.
- **New feature**: entity/yaml → migration (`doctrine:migrations:diff`, lands in
  `system/database/migrations/`, namespace `System\`) → repository/Dto → component + template →
  tests.

# 🎨 Templates (Atomic Design)

`templates/{atoms,molecules,organisms,skeletons}/`; skeletons are full pages, mirrored by
`assets/styles/skeletons/`; `layout.html.twig` is the root.

- **Name says component or not**: backed by a component (anonymous, Twig or Live) → `PascalCase`;
  skeleton, `{% include %}` partial, Turbo stream → `snake_case`. Directories lowercase.
- **Every `<dialog>` goes through `molecules/Modal`**: it owns the element, `class="modal"` and
  `closedby="any"` (opt out with `:closedby="false"`, never `null`). It does not render the opening
  button: the caller's `<button command="show-modal" commandfor="<id>">` points at it. A
  server-opened `<dialog open>` is non-modal — `modal_controller.js` re-opens it with `showModal()`.
- **Colors** come from the `ThemeColors` atom as `--empire-<slug>` / `--advance-<category>` CSS vars.
  Never resolve a color in PHP: `var(--empire-{{ slug }}, dimgray)`.
- **`this` is rebound inside a `<twig:X>` slot**: alias before the tag (`{% set picker = this %}`),
  as `StatPicker` and `pos_dialog` do around `molecules/Modal`.
- **Live Components — `data-loading` vs conditional `disabled`**: an unconditional
  `data-loading="addAttribute(disabled)"` is stripped on mount
  ([symfony/ux#372](https://github.com/symfony/ux/issues/372)) and silently re-enables the element.
  Wrap it:
  ```twig
  {% set isDisabled = <condition> %}
  <button {{ isDisabled ? 'disabled' }} {% if not isDisabled %}data-loading="addAttribute(disabled)"{% endif %}>
  ```

# 🎮 Domain

- **Game**: slug, currentTurn 1–20, region, `astVersion` basic/expert, players.
- **Player**: empire slug, advances (json), cities 0–9, census, treasury, `astPosition` 0–15.
- **AST**: 16 positions, 6 eras (Stone → Late Iron), boundaries in `ast.yaml`.
- **Shop**: player cart → pending order → operator validation (POS). `OrderStatus::Rejected` exists
  in the package vocabulary but **this app never reaches it** — do not build for it. A player
  buying nothing has no order.
- **Mercure**: topic `empires/game/{id}` (`mercure-refresh` controller); publish on state change.
- **Routes**, no prefix: `/`, `/create`, `/{slug}` (dashboard, chronicle once finished),
  `/{slug}/operator/board`, `/{slug}/operator/{orders,calamities,trade,abilities}`,
  `/{slug}/operator/pos`, `/{slug}/trade-cards` (unlinked, deliberately),
  `/{gameSlug}/player/{playerSlug}` (board, saga once finished), `…/shop`.

# 🧪 Testing

```
tests/            # tier first, layer second
├── Unit/         # pure TestCase, no kernel        ┐ below the tier, the tree mirrors src/
├── Integration/  # container + DB, no rendering    ┤ (Unit/State/, Integration/Engine/Handler/, …)
├── Component/    # Twig / Live component render    ┘
├── Functional/   # HTTP client + routes — crosses every layer, mirrors none (so does Integration/ShopFlow/)
├── Support/      # doubles (RecordingHub, NullHub) + fixtures (Fixture/: Game|Player|OrderBuilder, Tables)
└── bootstrap.php # drops+recreates the SQLite schema per run; DAMA rolls back each test
```

- **Tier by dependency, not subject**: a test needing a kernel is not a unit test; a test whose base
  class contradicts its directory is misfiled — move it. `WebTestCase` is the container base.
- **NEVER run two phpunit invocations at once**: each run drops and recreates its SQLite file(s).
  Concurrent suites destroy each other and surface as a flake. Delegations touching `tests/` are
  sequential.
- **The suite itself runs in parallel**: `make phpunit` drives paratest with 8 workers, one SQLite
  file per worker (`var/empires_test<TEST_TOKEN>.db`, from `doctrine.yaml`'s `when@test` url). The
  rule above is unchanged — it forbids two *invocations* at once, whatever their worker count.
  `composer phpunit` stays the single-process fallback.
- **Tools on `tests/`: rector only.** php-cs-fixer and phpstan are scoped out (they contradict
  rector there — `docs/architecture.md`). `make quality` already does this: never pass
  `PARAMS=tests/`; scope with `composer rector -- tests/Unit/`. `assertEquals` on `OrderLine` /
  `CreditEntry` graphs is correct — never "fix" it to `assertSame`.
- **`$this->assert…`, always** — also for Symfony's `assertResponse*`/`assertSelector*` and for
  `createStub`, `fail`, `markTestSkipped`. `self::` only for kernel plumbing (`bootKernel`,
  `getContainer`, `createClient`). `grep -rn 'self::assert' tests/` → 0.
- **`#[Test]`**, attributes from `PHPUnit\Framework\Attributes\`, never a `test*` prefix.
- **Names are behaviour sentences**, articles spelled out: `addingAnAlreadyOwnedAdvanceIsRefused`.
- **AAA by blank lines**, no `// Arrange`.
- **No comments in tests.** A clear name, AAA and well-named values *are* the documentation
  (Clean Code ch. 9; Meszaros, *Tests as Documentation*). Before filing a comment anywhere, ask
  whether it is **a name in disguise**: a magic number wants a constant, a provider row wants a
  better `yield` key, a helper wants a name that says what it prevents. Rename first; only what
  survives a rename gets sorted into three piles:
  1. **Trap** — Clean Code's *warning of consequences*, a fact no name can carry: `commandfor`
     resolves by id so a duplicate opens the hidden dialog at 0×0; `data-loading` is stripped on
     mount; a positive control whose removal would leave the test green and meaningless (say what
     the weakened test would pass on). Stays as one docblock, as short as it can be, **stated once
     per suite** at its first occurrence.
  2. **Product decision** — describes what the app does or why ("the operator screen is deliberately
     operational only"): moves to the component or rule it describes, or to `docs/architecture.md`
     when no single class owns it, then is deleted here. If `src/` already says it, it is pile 3.
  3. **Delete** — restates the name, narrates history, points at another test, describes the class
     under test, explains a fixture value already visible as a builder argument, or explains the
     suite's own organisation (which tier, which file, which provider row — this file legislates it).
  Sort **sentence by sentence**, not block by block: a mixed docblock keeps its trap and loses its
  paraphrase. Class-level docblocks follow the same rule. **`tests/Support/` is the one exception, narrow**: a
  builder default and a `Tables` seating may state *what they hold*, because no call site can show
  it — never why they were chosen nor what the tests assert; three lines is the ceiling.
  `@param`/`@return`/`@var` shapes stay.
- **No mocks** (`createMock`/`MockObject`): real objects plus the doubles in `tests/Support/`.
- **No `tearDown()`**: DAMA rolls back. To re-read the DB, `$em->clear()` first — a
  `freshEntityManager()` helper returns the same instance and does not reset the identity map.
- **Private helpers at the bottom**, after the tests.
- **Fixtures — the fixture may be large, the test must stay readable.** Entities come from
  `tests/Support/Fixture/` (`GameBuilder`, `PlayerBuilder`, `OrderBuilder`) or the `Tables` object
  mother; `new Game|Player|Order(` in a test is a smell (exception: `Unit/State/PlayerTest`, it
  tests the constructor). The test *shows* only what it varies (`withCensus(30)`, `validated(60)`);
  what must merely exist lives off-stage in a builder default or a `Tables` seating (a Standard
  Fixture, rebuilt per test by DAMA). Limit — the *Mystery Guest*: a value an assertion reads is
  visible at the call site, as a builder argument or a named finder. A `Tables` seating documents
  what it holds and takes no arguments; a test needing more builds its own.
- **Data providers**: `provide<TestMethodPascal>Cases()`, `public static`, `iterable`, blank line
  between yields — rector rewrites anything else.
- **Selectors**: semantic tag → `id` → `data-*` → ARIA role. No new CSS-class selectors; the ~28
  remaining (`.allocation-picker`, `table.ast`…) are debt — migrate when you touch the template.
  Consequence: a Twig `class` change can break tests, so the CSS-only test-skip rule does not apply.
- **Logic, not layout.** A test proves what the code decides (a rule, a handler, a Live action's
  effect on state), never where or how something is drawn. Ceiling on the DOM: *this block is there*
  (one row per player, the dialog exists, no grid on a finished game). Forbidden: an element's
  position or order, a label or an attribute with no consequence for the user, and re-asserting in
  an organism what its molecule already asserts. Live actions are exercised through
  `createLiveComponent()->call()`; the assertion reads the state, the render is the means. Rendering
  is proven in the browser (the smoke test), not by a crawler. An anonymous component (no PHP
  class) gets no suite of its own: the page that embeds it proves it is there, in one test.
