# ADR 0016 — The landing page: one direction built and rejected, one shipped

- **Status:** accepted 2026-08-20 (Decision Gate 5, second pass)
- **Supersedes:** the "immersive-lite" position in [`docs/design-system.md#landing`](../design-system.md#landing)
- **Does not change:** [ADR 0008](0008-visual-language.md), the in-app visual language

## Context

`docs/design-system.md#landing` ruled the landing "immersive-lite": one signature
interactive moment, everything else calm. The owner then set a different goal — the page
should make a shopkeeper stop and say «این فرق دارد» — and a calm competent page does
not do that, because every competitor already has one.

## History — what was built, and why it was rejected

This ADR documents **two** directions, because the first was built, deployed and thrown
away, and the reason is worth more than the code.

### Direction A — dark, cinematic, scroll-driven · REJECTED

Approved at Gate 5 on a written summary, built in full, and deployed to staging.

«پشت پیشخوان، بعد از تعطیلی» — the page lit like a phone shop after closing, near-black
ground, Persian turquoise as the screen-glow hue, the product's label yellow kept as the
one warm accent, and an 80mm thermal receipt as deliberately the brightest object on the
page. The hero pinned and the receipt printed line by line under a scrubbed timeline;
five flagship modules shared one pinned stage; GSAP, ScrollTrigger and Lenis drove it,
lazily imported after first paint.

It met every constraint it was given. Measured on staging: 0.9KB gz of critical-path JS,
50.8KB with every effect loaded, reduced motion served a complete pre-printed receipt and
never requested the effects bundle at all, and no scroll-jacking on touch.

**It was rejected on taste, live, by the owner.** Not on a metric, not on a bug — the
page was doing what it promised and the promise turned out to be the wrong one for this
product. Recording that plainly matters more than recording the palette: a landing for a
tool that shop owners will use for eight hours a day should feel like the tool, and a
cinematic dark page reads as a different company's marketing site attached to somebody
else's software.

Two things were learned that survive into Direction B and would have been expensive to
learn twice:

- **The signature element cannot be empty on arrival.** With the whole receipt driven
  from scroll position zero, a visitor landing at the top saw a blank sheet of paper. It
  was fixed by printing the intake block immediately and animating only the later acts,
  and it is why the receipt is now simply static — the story was never the animation.
- **CSS cannot move an element to a different parent.** The pinned stage's stylesheet
  asserted it could, and the five frames rendered strung down the wrong column with half
  the stage empty. A comment that asserts something false is worse than no comment.

The code is preserved on **`archive/landing-dark-immersive`** (`604a834`) rather than
deleted. It is a complete, working implementation of a direction this product decided
against, which makes it useful reference and bad main-line code.

### Direction B — navy and white, premium minimal · SHIPPED

«سرمه‌ای و سفید، مینیمال و شیک». White ground, navy ink, one accent, and whitespace doing
the work decoration usually does. Typography carries the page.

| role | value | measured |
|---|---|---|
| ground | `#FFFFFF` / `#F7F9FB` | — |
| ink | `#0E1B2C` navy | 17.3:1 on white |
| secondary | `#4A5A6B` | 7.1:1 |
| muted | `#64748B` | 4.8:1 — the floor |
| **accent** | `#0066CC` — **the product's own blue** | 5.6:1 |
| hairline | `#E7E8EA` (10% navy) | one border value, one shadow |

Three decisions inside it worth stating:

- **The accent is the product's blue, not a landing invention.** Direction A used a
  turquoise the app does not have, and this ADR previously argued for that divergence.
  The brief for B is "one family with the app", and sharing the accent is most of what
  that means.
- **The primary CTA is navy, not blue.** The loudest thing on a quiet page should still
  be quiet, and the accent is worth more kept for links and small marks. White on navy is
  17.3:1.
- **Label yellow is dropped entirely.** It measures **1.4:1 on white** and can therefore
  never be text here — only a filled chip with navy on it. On a page whose whole argument
  is calm, one warm chip is a distraction with a contrast caveat attached. It stays in
  the product, where it means "price label" against a different ground.

Motion is a 220ms fade-and-rise on section entry, once, via `IntersectionObserver` and a
CSS transition. **No animation library at all.**

## The honest remaining divergence

The brief said `#0E1B2C` was "the existing product token". **It is not.** The product's
ink is `#1d1d1f` (near-black), and `#F7F9FB` is not a token either — the app's alternate
ground is `#f5f5f7`. So the landing is now one family with the app in *accent*, and still
differs in *ink*.

Two ways to close it, and this is a decision nobody has made yet:

1. **Adopt navy into the product.** Change `--color-ink` to `#0E1B2C` and
   `--color-canvas-alt` to `#F7F9FB`. It is a real change to ADR 0008's near-monochrome
   language, it touches every screen, and it wants its own review.
2. **Bring the landing back to `#1d1d1f`.** Cheaper, and it loses the navy the owner
   asked for by name.

Left open deliberately rather than resolved by whichever file was edited last.

## Consequences

- The landing keeps its own stylesheet, its own font subsets (arabic only) and its own
  Vite entry. **Neither bundle imports the other.** `Dockerfile.prod` asserts the landing
  entry and its screenshots reach the built manifest, for the same reason it asserts the
  module pages do — [`docs/testing.md`](../testing.md), *the check must live where the
  fault can live*.
- `gsap` and `lenis` were added for Direction A and **removed with it**. The landing has
  one JavaScript file and no second chunk.
- Plan prices are read from the database, never typed into the template: roadmap 11.4
  promises a price change at launch is "a panel edit, not a deploy", and a number in Blade
  would break that promise on the one page a prospect reads.
- Section illustrations are **real screenshots of the running product**, captured from a
  seeded tenant on staging. They were impossible before the image fix that landed the same
  day — until then the product rendered white.
- `docs/design-system.md#landing` points here.
