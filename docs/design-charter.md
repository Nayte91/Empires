# Design charter — what the tokens mean

The design decisions this codebase implements were taken on the hi-fi canvas (`docs/mockups/empires-hifi.html`)
and arbitrated by the product owner. This page records the half that reached the code, so that a
reader of `assets/styles/globals.css` knows why 18px, why 12px, and where an ink comes from.

Everything here is declared in `globals.css` unless stated otherwise.

## The ground is the input, the ink is derived

One value describes a surface: `--ground`, the colour it is painted with. Both inks are computed
from it, so no screen ever declares a text colour for a light theme and another for a dark one.

```css
--ink-primary: lch(from var(--ground) clamp(15, calc((49 - l) * infinity), 92) clamp(0, calc(c * 0.6), 8) h);
--ink-muted:   color-mix(in oklab, var(--ink-primary) 65%, var(--ground));
```

- **The flip.** `calc((49 - l) * infinity)` is positive on a dark ground and negative on a light one;
  `clamp(15, …, 92)` turns that into one of two lightnesses. 49 is the threshold, and it reproduces
  the choices the canvas made by hand on all seven empire screens it drew.
- **Not black and white.** 15 and 92 are the lightnesses of the canvas inks, so the ink stops short
  of the extremes — a colour at L=0 or L=100 could carry no tint at all.
- **The tint follows the ground.** `h` is inherited, and the chroma is capped rather than fixed:
  `clamp(0, calc(c * 0.6), 8)` gives a neutral grey on an achromatic ground (white, `#111`) and a
  ground-tinted ink on an empire colour, without letting a saturated empire turn its ink green.
- **Muted is a mix toward the ground**, the formula the canvas already used on its player screens.

**The invariant: a surface that paints its own background declares its `--ground`, and its
`background` reads that token.** A `background:` holding a literal colour is the grep-able symptom
of a surface lying about its ground — the text inside it will be inked for the wrong backdrop.
Surfaces that declare one today: the page (`body`, and `body[data-empire]` from the empire colour),
the dashboard and chronicle (`#111`), the operator's canvas, panels, modals, order cards, game
tiles, buttons. `.panel.dark` returns its ground to the page, because it paints nothing.

The two formulas are declared on `*` rather than `:root`: a custom property containing `var()` is
resolved where it is **declared**, so an ink declared once at the root would keep the root's ground
for the whole document. On `*`, every element recomputes from the ground it inherits — which is what
lets a white panel on an empire-coloured page hold dark text.

## Sizes — four crans, four roles

| Token | Value | Role |
|---|---|---|
| `--size-hero` | `2.5rem` | the celebrated title: chronicle and saga, when a game is over |
| `--size-title` | `2rem` | the page title on every other screen |
| `--size-body` | `1rem` | running text |
| `--size-small` | `0.75rem` | hard floor: qualifiers, labels, hints |

`--size-body` is declared once, on `body`. Everywhere else the rule is **no `font-size` means body
size**; the token exists for the one case that needs it, coming back up to body size from inside a
smaller context. Nothing sits between 16 and 12: a text the eye runs along is 16, a text the eye
lands on is 12.

The page title carries the identity — the game or the player — and the section it belongs to is
named by the navigation, never by the header. The canvas draws that title at `1.125rem`, deliberately
modest; the app currently runs it at `2rem`, closer to the celebrated one.

## Weights — three, and what each claims

| Token | Value | It claims |
|---|---|---|
| `--weight-context` | `400` | this is context — the default, declared only to undo a tag that arrives bold |
| `--weight-answer` | `600` | this is the answer the box exists for — one per box |
| `--weight-hero` | `700` | the peak of the screen — at most one |

`500` is deliberately absent. At 12px it does not read as different from 400, and "somewhat
important" is the escape hatch that rebuilds the same problem one notch lower.

**With today's stand-in fonts, 600 and 700 render identically.** P052 and Noto Serif each ship one
roman and one bold and nothing between; asked for 600, the browser's font matching looks upward
first and serves the bold rather than synthesising a semibold. The three crans are therefore
correctly declared and only two of them are visible — the distinction appears on its own the day a
face with a real semibold lands, which both Cinzel and Inter have.

## Fonts

`--font-title` dresses every page title, `--font-hero` the celebrated one. Both are stand-ins: the
canvas draws its titles in Cinzel, which the app does not ship yet. They are named for their role,
so the day the real face lands there is nothing to rename.

## Four ranks of text, and which one to reach for

Every piece of text on a screen belongs to one of four ranks, each with its own component in
`templates/atoms/`. The rank is decided by what the text *does*, never by how big it should look.

| Rank | Component | It says |
|---|---|---|
| 1 | `PageTitle` | this is the page you are on |
| 2 | `SectionTitle` | this cuts the screen into parts |
| 3 | `Label` | this names a box, or a group inside one |
| 4 | `Hint` | this talks to you |

The reading test, when a rank is not obvious: **capitals name something; grey in sentence case
speaks to you; a text with a verb is an instruction, never a title.**

### The page title and its four variations

`PageTitle` takes `heading` and an optional `qualifier` — line one is the name of the game or the
player, line two the qualifier (`Kushan · Turn 9`) in capitals and muted ink. `variant` chooses
among four, and each signs its own output with `data-title` so a screen can be proven to have asked
for the right one:

| `variant` | Carries | Screens |
|---|---|---|
| `page` (default) | — | home, creation, dashboard, operator, trade cards |
| `player` | a rename trigger (`renameFor`) | player board, shop |
| `celebration` | — | chronicle and saga, once a game is over |
| `drilldown` | a way back (`back`, `backHref`) | none yet — for the dead-end screens the operator drills into |

Two rules hold across all four:

- **The header never names the screen.** The navigation already says which section you are in, so
  the title carries the identity — the game or the player — and nothing else. This is why the trade
  cards screen is titled with the game and reads "Trade card distribution" one rank below, as a
  section.
- **One `#page-title` per page.** The three components share that anchor because a page has exactly
  one title; it is the id the stylesheet and the functional tests both hang off. The element
  carrying it is a `<header>` in every variation, so gaining a command is an addition rather than a
  change of shape, and the `<hgroup>` inside holds the words.

Out of the system, on purpose: modal titles, card titles (an order, a player in the operator's
queue), the `<summary>` of a fold-out panel, and key/value labels. They are named where they live.

## What is deliberately not here yet

- The canvas grounds — parchment `#fdf5e6` for meta and operator screens, `#141110` for the
  in-game ones — are not applied; the app still opens on `#aaa`. Only the ink rule that will serve
  them is in place.
- The canvas' absolute ink tokens (`--ink-primary-dark`, `--ink-muted-dark`, `--ink-inverse`) have
  no equivalent: the first two are what the formula computes, and the third belongs to the fill it
  sits on, not to the page.
- The box treatment — white boxes with an ink hairline and no shadow — is decided on the canvas but
  not wired.
