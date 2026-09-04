# Architecture — the reasoning behind the rules

`CLAUDE.md` states the rules; this document keeps the arguments. Read it when a rule looks arbitrary,
or before moving something across a layer boundary.

## Free mode is the mode where the engine is human

`src/` is sorted by application layer, not by framework artefact, because Empires must run *either*
as free data entry (today) *or* driven by a game engine — the way a chess program lets you move
pieces freely or play a real game. Free mode is not "the mode without an engine": the operator view
(`nextTurn` / `previousTurn` / `finishGame`) *is* the engine's clock, held by a person — the main
thing a real engine would take away.

`Engine/` is not speculative: four of a game engine's six organs already exist (`legal`, `next`,
`score`, half of `terminal`). What is missing is the clock and the action log. There is deliberately
no `Agent/`: unlike `Engine/`, it would have nothing to hold yet.

## Why `Rules` never persists

One rule set serves both modes only if a rule returns a verdict rather than throwing: a rule that
throws only serves an engine, a rule that answers serves the engine *and* the free-entry screens.
Reading a repository from `Rules` is fine (`ShopConnector` does); writing is not.

## Why `Advisory/` sits under `Presentation/`

An advisory is a courtesy the board extends to its players — "you are 6 population over your city
count" — and the rules of the game are indifferent to it: delete the folder and nothing about the
game changes, only a panel empties. Two consequences before moving it back to `Rules/`:

- Each advisory ends in a finished English sentence, so it is copy, and copy belongs where copy is
  written. `Presentation/Shop/ShopExceptionTranslator` is the same seam one step further along: the
  engine raises a machine-readable reason and only `Presentation/` phrases it.
- An advisory computes a derived fact before phrasing it. A future `Agent/` wants that half: when
  one lands, the arithmetic moves down to a `Rules/` calculator and the advisory keeps the wording.
  The `REFACTOR-WHEN` in `Advisory.php` names the threshold.

## Why nobody names `Infrastructure/`

Repositories are consumed through the `State/Repository/*Interface` contracts, wired by Symfony's
singly-implemented-interface auto-aliasing — both sides live in the same `App\` scan, so there is no
`services.yaml` entry to maintain. Only the cross-package shop-engine ports need explicit aliases.

## Two standards, on purpose

The library applies best practices; the application applies what is proportionate.
`userforged/shop-engine` is written for a reader who is not us — every port documented, no host
class named, no shortcut a second consumer would inherit. The app is written for this game: an
assumed `instanceof` on a type we construct ourselves, a JSON column where an entity would be
over-engineering, a display aggregate that duplicates six lines rather than share a helper.
Applying the library's rigour to the app wastes effort on a reader who will never exist; applying
the app's pragmatism to the library ships that shortcut to everyone.

## The shop as a package

The shop is `userforged/shop-engine`, a path-repository package with its own namespace, bundle,
tooling and tests. Its adapters in `src/` are split by layer, each keeping a `Shop/` path segment,
so `find src -path '*/Shop/*'` finds them all (exceptions: `CreditEntry`/`CreditSource` in `State/`,
`ShopMercurePublisher` in `Infrastructure/Mercure/`). The split is what makes `config/services.yaml`
readable: exactly one port writes (`FulfillmentInterface` → `Engine/`), every other one answers a
question (`Rules/`) or reaches outside (`Infrastructure/`).

## Why the modal does not render its own button

`molecules/Modal` owns the `<dialog>`, `class="modal"` and the `closedby="any"` light dismiss, and
nothing else. No two callers put the opening button in the same place: `atoms/PageTitle` owns
Rename's, `Navigation` emits its QR buttons in a different loop than its dialogs, the POS has none
and is rendered `open` by the server. The link is the `id` the caller's own
`<button command="show-modal" commandfor="…">` points at — native HTML, no JavaScript.
`assets/controllers/modal_controller.js` exists only for the server-opened case: a `<dialog open>`
written in markup is non-modal, so it re-opens it with `showModal()`.

## Why `assertEquals` is right on value-object graphs

php-cs-fixer's risky PHPUnit set rewrites `assertEquals` → `assertSame`. On arrays of freshly
constructed readonly VOs that can never pass: `assertSame` demands instance identity. That is why
php-cs-fixer is scoped out of `tests/`, and why phpstan is too — it flags the very form rector
enforces (`staticMethod.dynamicCall` on every `$this->assertSame()`). Rector and phpstan can never
agree on test files; rector is the single authority there.

## One markup, two dashboard layouts

Under 60rem the dashboard is one screen with four tabs, switched by the browser alone: one radio
group in the bar, the panels shown or hidden off the checked one. Above 60rem the very same markup
is the stack it has always been. Every panel therefore ships in one response — switching tabs is a
paint, never a round trip — and nothing is rendered twice. The bar carries no script at all.

## Nothing links to a player's kiosk

The dashboard and the operator board link to a player's board, never to their shop: the board is
the player's home, and the kiosk is reached from there. The shop route stays directly reachable all
the same, because a QR code hands it out.

## A page title names the thing, not the section

Every screen titles itself with what the reader is looking at, never with the section they are
looking at it in — which is why the shop is titled with the player sitting at it and the trade cards
page with the game's slug. Four components share the `h1` rank and the `#page-title` anchor; each
signs its own output with `data-title`, so which one serves a screen is read off that signature.
