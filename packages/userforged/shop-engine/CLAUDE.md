# CLAUDE.md — userforged/shop-engine

> Scope: this file governs `packages/userforged/shop-engine/` only. The host application
> (Empires) has its own `CLAUDE.md` at the repo root. When the two disagree about this
> package, **this file wins**.
>
> The design specification lives in `README.md` next to this file. This document is the
> *working* contract: what may enter the package, what may not, and the traps that cost
> us time already.

---

## 1. What this package is — and the word for it

**A domain engine, not an embeddable shop.** The distinction is not decorative; it decides
every "should this go in?" question.

| Deliverable | Domain engine | Embeddable shop |
|:---|:---:|:---:|
| Ports/interfaces, VOs, enums | ✅ | ✅ |
| Domain services, commands + handlers, events, exceptions | ✅ | ✅ |
| Concrete entities / traits / mapped superclasses | ❌ | ✅ |
| ORM mapping, migrations, schema | ❌ | ✅ |
| Routes, controllers | ❌ | ✅ |
| Templates, assets, CSS | ❌ | ✅ |
| Admin back-office, fixtures, translations | ❌ | ✅ |

The decisive test for anything not in the table above: **how long between `composer
require` and a first order placed?** "Days — you write the adapter tier" is this package.
"Minutes — you write a theme" is not.

### Rings, not a binary

Choosing "domain engine" does not forbid ever shipping templates. It forbids shipping them
*in this package*. The framework-glue ring (`UserforgedShopEngineBundle`) lives **inside**
this package, not a separate package. The presentation ring (Empires' `src/Component/` +
`templates/`) lives in the host — it is the draft of a future ring, if one is ever
extracted. Empires is currently the reference starter *and* the only consumer.

---

## 2. The canon — the vocabulary is not ours to invent

`Catalog → Product → Cart → Checkout → Order → Fulfillment` is the standard OMS
decomposition (ARTS/NRF, TM Forum SID, Fowler, Apache OFBiz — see README for the pedigree).
Consequence, and it is a rule: **when naming a new type, look for the industry word before
inventing one.** Matching the canon is an adoption argument, not an aesthetic one.

---

## 3. Prior art — the two lessons that bind a decision here

> **Sylius died of disuse as a set of standalone domain components** (almost nobody
> consumed them outside Sylius; 2.0 removed pieces without deprecation). **The lesson to
> keep:** the difficulty is not building an extractable core, it is finding a second
> consumer before the package refossilises into a folder. Until one exists, do not invest
> in the outer rings — Empires *is* the proof.

**Medusa v2** is the closest living analogue — but every Medusa module owns its own data
model and migrations; persistence is prescribed. Here it is a port (§5). That is the actual
differentiator with the closest prior art; state it that way rather than claiming novelty.

Full comparative survey (Aimeos, django-oscar, Broadleaf, Spree/Solidus, platforms) is in
`README.md` — none of it changes a decision beyond the two points above.

---

## 4. Two guards, and why the second exists

```bash
grep -rn '^use App\\' packages/userforged/shop-engine/src/                  # → 0
grep -rniE 'player|advance|empire' packages/userforged/shop-engine/src/     # → 3
```

Run **both**. The first only tests *imports* — for a long time it certified this package
"host-free" while the game's vocabulary sat in the code in plain words (`playerId` on the
write façade, `OrderInterface` saying `buyerId` right beside it). The package contradicted
itself and the guard could not see it.

The 5 tolerated hits are deliberate: an enumeration that *demonstrates* agnosticism
(`FulfillmentInterface`'s "granting a license, adding a game advance to a player,
decrementing stock" example), plus docblocks naming real host classes as examples. Do not
"fix" them — a renamed enumeration loses the pedagogy. Do not add to them either.

### Vocabulary rules

The package speaks **buyer, product, order, line, cart, promotion, gift, fulfillment,
window**. Never player, advance, turn, empire.

`window` is the pattern's word for what the host calls `turn`; `OrderInterface` documents
the correspondence. Do not "harmonise" it in either direction.

**The translation boundary is the host's job, and it is not a smell:**

```
this package  →  ShopExceptionReason::ProductAlreadyOwned
host (match)  →  'error.advance_already_owned'
player reads  →  "You already own this advance"
```

The translation key stays `advance` on purpose — the copy addresses a player buying an
advance, which is the host's domain. Never push the package's vocabulary through to user
copy, and never pull the host's back into the package.

---

## 5. Persistence is a port — no entity, no mapping, no migration

**Decided, not pending.** This package ships no Doctrine entity, no ORM mapping and no
migration. The host writes its own entity implementing `OrderInterface` and generates its
own schema with `doctrine:migrations:diff`.

Why it is not a gap: the host's `orders` table carries a FK to its own `player` table. No
migration shipped from here could express that. Shipping one would be a regression.

If a future consumer ever wants convenience (MappedSuperclass, mapping traits à la
`knplabs/doctrine-behaviors`, or a Sylius-Resource-style opt-in model), it belongs in a
**separate** `shop-engine-doctrine` adapter package — never in this one. (Full comparison of
the five PHP strategies for library-owned persistence is in `README.md`.)

Also worth knowing: a plain Composer library **cannot** force tables on a host — Doctrine
only maps what `doctrine.orm.mappings` declares. The real vector is a bundle whose DI
extension calls `prependExtensionConfig('doctrine', …)` unconditionally. **This bundle must
never do that.** (Infrastructure tables auto-creating themselves — `messenger_messages`,
`lock_keys` — is an accepted exception for infra, never for domain tables.)

---

## 6. Symfony wiring — three traps that cost us real time

**`prependExtension()` is the only phase that can contribute to `framework.*`.**
`FrameworkExtension::load()` consumes its configuration during the load phase, and
FrameworkBundle is registered before this one — so contributing from `loadExtension()`
is **silently discarded**, the worst possible failure mode. The bundle therefore injects
`order_class` into `framework.workflows.shop_order.supports` from `prependExtension()`,
reading raw config via `$builder->getExtensionConfig('userforged_shop_engine')`.

*Corollary*: at that point the config tree from `configure()` has not run, so
`isRequired()` protects nothing. `order_class` is validated by hand and throws
`InvalidConfigurationException`. Without it, a forgotten key yields an empty workflow, in
silence.

**Singly-implemented-interface auto-aliasing does not cross loaders.** Symfony marries an
interface to its single implementation only within one loader's scan. Since this package's
services are registered by the bundle and the host's adapters by the app, the two never
meet — every port broke at once when the bundle landed. The six port→adapter bindings are
therefore declared explicitly in the **host's** `config/services.yaml` (never here — that
would mean naming `App\…` classes in the package). This is an improvement, not a
workaround: the hexagonal wiring became the readable list of adapters the application
provides.

**Composer never merges a dependency's `autoload-dev` into the root autoloader.** When
`config/tools/bootstrap.php` falls back to the host's `vendor/autoload.php`, the package's
own test namespace is unresolvable unless the bootstrap registers it explicitly. It does.

---

## 7. Tooling — the package verifies itself

Configs live in `config/tools/` (QA config is config; the subdirectory exists so a single
`.gitattributes` rule can exclude it from a distribution tarball while keeping
`config/{services,workflow,messenger}.yaml`, which load at runtime).

```bash
make quality      # from the repo root: runs the app pipeline, then this package's
make lib-quality  # this package only
```

`make quality` runs both and fails if either fails. **Never make this package
self-sufficient by making it invisible** — it dropped out of all three analysers once
already, silently, when it moved out of `src/`. Nothing turned red; the coverage just
shrank.

### The tool split (`tests/` gets rector only, never phpcs/phpstan) is reproduced here and is not negotiable

Same split and same reason as the host (root `CLAUDE.md`): `@PhpCsFixer:risky` rewrites
`assertEquals`→`assertSame`, which is wrong on freshly-constructed readonly VOs; phpstan
flags the very `$this->assertSame()` form rector enforces. Rector and phpstan can never
agree on test files — rector is the sole authority on test style here too.

### How to prove a linter config actually loads

A missing `../` on phpstan's `includes` raises **no error**: phpstan starts, silently drops
`phpstan-strict-rules` and `phpstan-deprecation-rules`, analyses more loosely, and prints
`[OK] No errors`. Reading the file cannot catch this. Execute a probe instead — a temporary
class with `if ($someString)`, which level 6 accepts and `strict-rules` rejects with
`if.condNotBoolean`. If the rule bites, the includes resolve. Delete the probe after.

Use this technique whenever a config file moves. It caught three would-be silent
regressions in one session.

---

## 8. Tests — placement is decided by dependency, not by subject

| Location | Content | Base |
|:---|:---|:---|
| `packages/…/tests/` | 121 tests of the **engine** — cart, quoting, promotions, serialization, exception contract, and every command handler | `TestCase`, no kernel |
| host `tests/Integration/ShopFlow/` | 25 tests of the **wiring** — order flow, direct sale, erase | `WebTestCase` |
| host `tests/Game/Shop/` | the `SessionCartStorage` adapter | host, not library |

**A test of the engine that needs a kernel is not testing the engine — it is testing the
integration**, so it belongs to the host whatever it talks about. A test that lands here
must run with an autoloader and nothing else.

**Rejection is the exception that proves the rule.** The host deleted its own reject flow
entirely — no button, no action, no translation branch — so no wiring is left to test up
there. The behaviour itself is still shipped in `RejectOrderHandler` and the `reject`
transition, and it is covered here alone. A capability with no consumer is the one most
likely to rot unnoticed; do not read the absence of host tests as permission to drop it.

Conventions are the project's (see root `CLAUDE.md`): `#[Test]` attribute, behaviour-sentence
names, AAA by blank lines, `$this->assert*` throughout, **no PHPUnit mocks** (hand-written
doubles only), no `tearDown()`, private helpers at the bottom.

---

## 9. Anti-scope — what never enters this package

Payment processing, taxes, shipping, stock/inventory, multi-currency, admin UI, user
management, emails, Twig, forms, HTTP anything. Each is a host concern or a future adapter.
A shop where payment happens at the counter is the *default* mental model here — payment is
an extension, not a hole.

**Live Components do not come down here.** Until Symfony UX offers headless LCs, moving
them in would reintroduce Twig/UX into a host-free library. They stay in the host.
(Decided; do not reopen without a reason.)

---

## 10. Publication — deliberately not done

Nothing is published. The package is a path repository that Composer symlinks; the manifest
validates (`composer validate --strict`) and its dependencies resolve standalone
(`composer update --dry-run` inside the package) — the two things that actually prove
publishability, both verified without any CI.

Still missing, and **only useful once a second consumer exists**: `LICENSE`, `CHANGELOG`, a
`.gitattributes` excluding `config/tools/`, and a CI job running `composer install` *inside*
the package. The dev dependencies are already declared and `config/tools/bootstrap.php`
already prefers a local `vendor/` — standalone installation is one command away, on purpose.

The license stays `proprietary` for now: as sole author, relicensing later costs nothing;
the constraint only appears with the first external contribution.

**Residual debt, known and accepted**: five docblocks in `src/` still cite host classes as
examples (`BuyerInterface`, `BuyerProviderInterface`, `FacetProviderInterface`,
`Promotion/OptionCredits`, `Exception/ShopExceptionReason`), plus a historical comment in
`Service/OrderValidator.php`. Harmless today, dangling references for anyone who is not
Empires — clean before publishing, not before. The README may keep naming Empires: it is a
design document that assumes its case study.

> **Do not confuse this list with the 5 tolerated grep hits of §4** — the coincidence of the
> number is pure accident, the sets differ. Three of the files above
> (`FacetProviderInterface`, `Promotion/OptionCredits`, `Exception/ShopExceptionReason`)
> cite host classes in prose only, without any `use App\` or the words
> `player|advance|empire` — so **both guards are blind to them**. Clearing §4's hits would
> not clear this debt; it has to be found by reading, not by grepping.
