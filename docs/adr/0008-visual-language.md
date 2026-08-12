# ADR 0008 — Visual language: calm neutral ground, one blue, pill actions

- **Status:** Accepted
- **Date:** 2026-08-07
- **Deciders:** Project owner (requested) + lead engineer
- **Approved by:** **Requested** by the project owner, and delivered — `docs/PROGRESS.md`, 2026-08-07 records the change and the two deliberate divergences from the reference. Note the weaker provenance: this is the one Accepted ADR whose approval is an owner *request* followed by delivery, rather than a recorded sign-off. Nothing here is expensive to reverse — it is tokens in one stylesheet — so it was not taken to a gate.
- **Supersedes** the palette and shape half of the original design brief
  (`docs/03-design-and-claude-setup-fa.md` §1–2). RTL rules, domain components,
  Persian typography rules and every CLAUDE.md golden rule are **unchanged**.

## Context

The first design system was built around a Persian-tile turquoise (`#0FA3A8`), a
price-label yellow, an eight-colour swatch wall and 96px section rhythm. It was
coherent, but it read as *a colourful admin tool*. For a product a shop owner stares
at for eight hours a day — and whose competitors are Windows desktop applications
from 2010 — the brief changed: adopt a calmer, more premium visual language in the
spirit of apple.com. The design *language*, not a pixel clone, and using none of
Apple's property.

The reference was studied directly rather than from memory: apple.com was opened in a
real browser at 390px and 1280px, nav / hero / product section / footer were captured,
and computed styles were extracted programmatically. What the measurement showed:

| Property | Measured |
|---|---|
| Section grounds | `#f5f5f7` dominant (8 large sections), `#000` for feature bands |
| Primary text | `rgb(29,29,31)` = `#1d1d1f` |
| Filled CTA | `#0071e3`, white text, `border-radius: 980px`, 44px tall, 17px |
| Outlined CTA | transparent, 1px `#0066cc` border, `#0066cc` text, same pill |
| Text links | `#0066cc` (23 occurrences — the dominant link colour) |
| Nav links | 17px, weight 600, letter-spacing `-0.374px` |
| Body | 17px base, tight leading on display sizes |

The pattern underneath: **near-monochrome, one accent, whitespace as the primary
structuring device, and colour reserved for things you can act on.**

## Decision

### Palette

- Alternating grounds: `--color-canvas` `#ffffff` and `--color-canvas-alt` `#f5f5f7`,
  plus `--color-canvas-invert` `#000000` for rare feature bands.
- Text `--color-ink` `#1d1d1f`, secondary `--color-ink-soft` `#6e6e73`.
- **One accent**, `--color-brand` `#0066cc`.
- Semantics kept but muted: success `#0f7b3f`, warning `#8a5a00`, danger `#b3261e`,
  info = the same blue.

Two colour decisions are worth stating because they are deliberate deviations:

1. **The accent is `#0066cc`, not the brighter `#0071e3`.** Measured contrast:
   `#0071e3` is 4.70:1 on white but drops to **4.31:1 on `#f5f5f7`** — below AA for
   body text, and half our sections use that ground. `#0066cc` gives 5.57:1 and
   5.11:1 respectively. The reference itself uses the darker value for text links
   for the same reason.
2. **Secondary text is `#6e6e73`, not the reference's `#86868b`.** `#86868b` measures
   **3.62:1 on white** — it fails AA. Our accessibility floor (design-system rule 8)
   is not negotiable for a fashion, so the value is darkened until it passes at
   5.07:1.

Every semantic value was likewise chosen by computing its contrast rather than by eye:
all clear AA against both grounds.

### Shape and depth

Pill (`9999px`) for every action, 18px cards, 12px controls, hairline borders at 8%
alpha, and two very soft near-colourless shadows. Elevation is carried by ground
contrast and whitespace first; shadow is the last resort, not the first.

### Space and type

Section rhythm 96px → **144px** (1.5×). Body 17px with 1.65 leading. Display sizes up
to 72px with negative tracking (`-0.022em`) — the larger the type, the more negative
tracking it needs to hold together. Persian keeps its more generous leading because
it needs it; that rule predates this change and survives it.

### Chrome and motion

Sticky frosted nav and sidebar via a `.glass` component class — `backdrop-filter:
saturate(180%) blur(20px)` over a translucent ground. The `saturate()` matters: without
it the blur reads grey and dead.

Motion is fade-and-rise only, ~0.5s, ~12px of travel, via a `.reveal` utility.
`prefers-reduced-motion` disables it entirely, as before.

### What is explicitly NOT taken

- **No SF Pro.** It is Apple's and not licensed to us. Estedad (display) and
  Vazirmatn (body) remain our faces, which is the right answer anyway — this product
  is Persian-first and neither SF nor any Latin face serves it.
- No Apple imagery, icons, logos, product names or copy.
- No pixel-level cloning of layouts.

## Alternatives considered

**Keep the turquoise, adopt only the spacing and shape.** Rejected: the turquoise was
doing the work of an accent *and* a brand *and* a status colour, and it is what made
the UI read as "admin tool". Spacing alone would not have changed the impression.

**Adopt the reference's exact values including `#86868b` and `#0071e3`.** Rejected on
measured contrast — see above. Fidelity to a reference is not worth failing our own
accessibility floor.

**Introduce a second accent for secondary actions.** Rejected: one accent is what
makes "blue means you can act on this" a learnable rule. Secondary actions are
neutral-filled or outlined instead.

## Consequences

- **Positive.** Colour now carries meaning exclusively — the blue means action, the
  semantic three mean money/work state, everything else is neutral. That makes a
  dense POS table far easier to scan.
- **Positive.** Every colour pair in the system has a measured contrast ratio, so
  accessibility is a property of the tokens rather than a per-page review item.
- **Negative.** 1.5× section rhythm means fewer rows visible per screen on marketing
  and settings pages. Accepted there; the `data-density="compact"` token exists
  precisely so POS and table-heavy screens do not pay that cost.
- **Negative.** `backdrop-filter` costs GPU on low-end Android, which is our actual
  audience. Confined to two persistent surfaces (nav, sidebar) rather than used
  decoratively, and it degrades to a solid translucent panel where unsupported.
### Applied (2026-08-07)

Propagated to every Phase 0/1 screen. Two consolidations came out of it and are part
of the decision rather than incidental:

- `login` and `register` each carried their own copy of the auth frame; both now use
  `AuthLayout`. Duplicated frames are how a "token change" turns into a per-page hunt.
- The four settings screens each chose their own card padding; `SettingsSection` now
  owns it.

`--secondary` also had to move off `#f5f5f7`: with it equal to `canvas-alt`, a
secondary button was invisible on an alternating section — caught immediately by the
gallery, which is what the gallery is for.

- **Binding.** These are tokens. Pages must not reintroduce raw hex values, a second
  accent, or per-page shadow and radius values; `docs/design-system.md` and the
  `mobishop-ui` skill are updated in step with this ADR.
