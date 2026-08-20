# ADR 0016 — The landing page goes full immersive, on its own dark skin

- **Status:** accepted 2026-08-20 (Decision Gate 5)
- **Supersedes:** the "immersive-lite" position in [`docs/design-system.md#landing`](../design-system.md#landing)
- **Does not change:** [ADR 0008](0008-visual-language.md), the in-app visual language

## Context

`docs/design-system.md#landing` ruled the landing "immersive-lite": one signature
interactive moment, everything else calm, on the grounds that the audience is a shop
owner on a mid-range Android on an Iranian connection and that "for a B2B tool, clarity
of value beats spectacle."

That reasoning was sound and its constraints still hold. What changed is the goal the
owner set for the page: it should make a shopkeeper stop and say «این فرق دارد». A
calm, competent page does not do that, and every competitor in this market already has
a calm, competent page.

## Decision

**Build the landing as a scroll-driven immersive experience, with its own dark theme,
on its own Vite entry.**

Three parts, and the third is the one that keeps this from spreading.

### 1. A landing-only dark skin

Direction: «پشت پیشخوان، بعد از تعطیلی» — the page is lit like a phone shop after
closing. Near-black ground, and the only light sources are the things that actually
glow in that room.

| role | value | measured on ground |
|---|---|---|
| ground | `#070B0E` | — |
| **فیروزه** — the screen-glow hue | `#3FD9C8` | 11.3:1 |
| **label yellow** — carried over unchanged | `#FFD84D` | 14.3:1 |
| receipt paper | `#F2EDE3` | 16.9:1 |

The risk the page takes, deliberately: **the brightest object on it is an 80mm thermal
receipt.** Not a headline, not a gradient. That inversion is the identity, and it comes
from the counter rather than from a design trend.

### 2. Turquoise on the landing, blue in the product — on purpose

**This is the part somebody will later try to "fix". Do not.**

The product's brand token is `#0066cc`, a blue, and it stays that. The landing uses
Persian turquoise as its light source. The two are different on purpose:

- the **product** is a tool used for eight hours a day, and its near-monochrome
  single-blue chrome exists so that colour in the interface always means *information* —
  a status, a warning, an amount (ADR 0008);
- the **landing** is read once, for ninety seconds, by somebody deciding whether this
  is worth an afternoon. Its job is atmosphere, and فیروزه is the hue this market reads
  as Persian without being asked to.

A landing that matched the app's chrome would be a screenshot of the app. A product
that matched the landing would be a tool that shouts.

The divergence is therefore intentional, approved at Gate 5, and recorded here so that
a future reviewer finding "the landing doesn't use the brand colour" reads this instead
of harmonising them.

### 3. Two pins, not five

The brief asked for a pinned scroll section per flagship module — five of them. It ships
as **one pinned stage that five scenes move through**, plus the hero. Two pins total.

Beyond about two pins a page stops reading as cinema and starts reading as a page that
will not let you leave; on a phone it is simply broken. Below 900px, and on any coarse
pointer, the stage is not pinned at all — it degrades to an ordinary stacked list, and
Lenis is never engaged. **No scroll-jacking on touch** was a hard guardrail, not a
preference.

## The constraints that did not move

The original ruling's budget survives intact, and the numbers are better than it asked
for:

| | budget | measured |
|---|---|---|
| landing JS, critical path | — | **0.9 KB gz** |
| landing JS, all effects loaded (desktop) | ≤ 180 KB gz | **50.8 KB gz** |
| reduced-motion visitor | — | **0.9 KB gz** — the effects bundle is never requested |
| WebGL | none | none |

`prefers-reduced-motion` gets a **complete** experience, not a disabled one: the receipt
arrives already printed, as a finished object. Nothing on the page is hidden by CSS
until JavaScript has confirmed it will be able to reveal it again — the reverse of the
usual pattern, and the reason a failed chunk degrades to a plain page rather than a
blank one.

## Consequences

- The landing has its own stylesheet, its own font subsets (arabic only) and its own
  entry. **Neither bundle may import the other.** `Dockerfile.prod` asserts the landing
  entry and its screenshots are in the built manifest, for the same reason it asserts
  the module pages are — see [`docs/testing.md`](../testing.md), *the check must live
  where the fault can live*.
- Plan prices on the landing are read from the database, never typed into the template.
  Roadmap 11.4 promises that changing a price at launch is "a panel edit, not a deploy",
  and a number in Blade would quietly break that promise on the one page a prospect
  actually reads.
- The section illustrations are **real screenshots of the running product**, captured
  from a seeded tenant on staging. They were not possible before the image fix that
  landed the same day — until then the product rendered white.
- `docs/design-system.md#landing` is rewritten to point here.
