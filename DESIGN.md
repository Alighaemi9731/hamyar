---
name: Hamyar
description: Calm, near-monochrome Persian RTL interface — one blue accent, pill actions, hairline borders, generous whitespace (incumbent system, ADR 0008; being replaced by Redesign v2)
colors:
  canvas: "#ffffff"
  canvas-alt: "#f5f5f7"
  canvas-invert: "#000000"
  ink: "#1d1d1f"
  ink-soft: "#6e6e73"
  brand: "#0066cc"
  brand-hover: "#005bbb"
  brand-on-dark: "#409cff"
  brand-bright: "#0a84ff"
  success: "#0f7b3f"
  warning: "#8a5a00"
  danger: "#b3261e"
  success-on-dark: "#4cc47f"
  warning-on-dark: "#e0a13a"
  danger-on-dark: "#ff6961"
  label: "#ffd84d"
  secondary-fill: "#e8e8ed"
  hairline: "rgb(0 0 0 / 0.08)"
  dark-surface: "#1d1d1f"
  dark-surface-muted: "#2c2c2e"
typography:
  display:
    fontFamily: "Estedad, Vazirmatn, ui-sans-serif, system-ui, sans-serif"
    fontSize: "4.5rem"
    fontWeight: 800
    lineHeight: 1.08
    letterSpacing: "-0.022em"
  headline:
    fontFamily: "Estedad, Vazirmatn, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.75rem"
    fontWeight: 700
    lineHeight: 1.2
    letterSpacing: "-0.015em"
  title:
    fontFamily: "Estedad, Vazirmatn, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.3125rem"
    fontWeight: 700
    lineHeight: 1.2
    letterSpacing: "-0.015em"
  body:
    fontFamily: "Vazirmatn, ui-sans-serif, system-ui, 'Segoe UI', sans-serif"
    fontSize: "1.0625rem"
    fontWeight: 400
    lineHeight: 1.65
    letterSpacing: "0"
  label:
    fontFamily: "Vazirmatn, ui-sans-serif, system-ui, 'Segoe UI', sans-serif"
    fontSize: "0.8125rem"
    fontWeight: 500
    lineHeight: 1.4
    letterSpacing: "0"
  figure:
    fontFamily: "Vazirmatn, ui-sans-serif, system-ui, 'Segoe UI', sans-serif"
    fontSize: "1.3125rem"
    fontWeight: 700
    lineHeight: 1.2
    fontFeature: "'tnum' 1"
rounded:
  pill: "9999px"
  card: "18px"
  control: "12px"
  inner: "8px"
spacing:
  row: "44px"
  row-compact: "36px"
  gap: "16px"
  section: "144px"
  section-sm: "88px"
  shell: "1110px"
  wide: "1400px"
components:
  button-primary:
    backgroundColor: "{colors.brand}"
    textColor: "{colors.canvas}"
    rounded: "{rounded.pill}"
    padding: "0 20px"
    height: "40px"
  button-primary-hover:
    backgroundColor: "{colors.brand-hover}"
    textColor: "{colors.canvas}"
    rounded: "{rounded.pill}"
  button-secondary:
    backgroundColor: "{colors.secondary-fill}"
    textColor: "{colors.ink}"
    rounded: "{rounded.pill}"
    padding: "0 20px"
    height: "40px"
  button-outline:
    backgroundColor: "{colors.canvas}"
    textColor: "{colors.ink}"
    rounded: "{rounded.pill}"
    padding: "0 20px"
    height: "40px"
  input:
    backgroundColor: "{colors.canvas}"
    textColor: "{colors.ink}"
    rounded: "{rounded.control}"
    padding: "0 12px"
    height: "40px"
  card:
    backgroundColor: "{colors.canvas}"
    textColor: "{colors.ink}"
    rounded: "{rounded.card}"
    padding: "24px"
  chip-status:
    backgroundColor: "{colors.canvas-alt}"
    textColor: "{colors.ink}"
    rounded: "{rounded.pill}"
    padding: "2px 8px"
    height: "24px"
  nav-item-active:
    backgroundColor: "rgb(0 102 204 / 0.10)"
    textColor: "{colors.brand}"
    rounded: "{rounded.pill}"
    padding: "0 12px"
    height: "44px"
---

# Design System: Hamyar

<!-- Scan-mode capture of the INCUMBENT system (2026-09-03), written from
     resources/css/app.css, docs/design-system.md and ADR 0008/0019 so the impeccable
     detector has a target during Redesign v2. The qualitative interview was skipped by
     decision: this world is about to be replaced (ADR 0020/0021); the replacement gets a
     fresh document pass at the finish of that programme. -->

## Overview

**Creative North Star: "The Quiet Counter"**

A phone-shop till that stays out of the way. The interface is near-monochrome — white and
warm-grey grounds, near-black ink, one blue — with whitespace doing the structuring that
lines and colour usually do. Colour is information: blue means *you can act on this*, and
the three semantic tones mean money or work state; nothing else is coloured. Actions are
pills, surfaces are soft 18px cards on hairline creases, shadows are almost colourless. The
audience is a shopkeeper on a mid-range Android with a customer waiting, so density is
moderate in registers, compact at the POS, and generous on every marketing surface.

The incumbent measured itself against apple.com (ADR 0008) and deliberately did not copy it:
its own blue for AA on grey grounds, its own greys for contrast, Persian faces rather than
SF Pro. Confirmed rejections: a second accent; per-component dark-mode overrides; borders
as section separators; Tailwind's stock shadow ramp.

**Key Characteristics:**
- Two alternating grounds (white / `#f5f5f7`) separate sections — never borders
- One accent, `brand` `#0066cc`, on ≤10% of any screen; semantics carry meaning only
- Pill actions enforced system-wide on `[data-slot="button"]`
- Hairline borders at 8% alpha, three near-colourless shadow steps
- Persian-first type: Estedad display, Vazirmatn body, 17px/1.65, tabular figures
- RTL from the root; logical properties only; three digit modes

## Colors

A near-monochrome page with one blue and three muted semantics, every pair measured for AA on both grounds.

### Primary
- **Counter Blue** (`#0066cc`): links, the single primary fill per view, active nav, focus ring. `#005bbb` on hover. Lifts to `#409cff` on black in dark mode; `#0a84ff` is the dark-mode *fill* only, never text.

### Neutral
- **Paper White** (`#ffffff`): primary section ground, cards, inputs.
- **Warm Grey** (`#f5f5f7`): alternating section ground, muted surfaces, shadcn `accent` hover.
- **Ink** (`#1d1d1f`): primary text (16.8:1 on white); dark-mode surface.
- **Soft Ink** (`#6e6e73`): secondary text (5.07:1 on white, 4.66:1 on warm grey — the floor).
- **Hairline** (`rgb(0 0 0 / 0.08)`): borders and creases; `0.14` for strong, `0.16` for input strokes.
- **Secondary Fill** (`#e8e8ed`): neutral filled action — one step deeper than the grey ground so it reads on both grounds.
- **Feature Black** (`#000000`): a rare full-bleed band; the dark-mode page ground.

### Semantics
- **Paid Green** (`#0f7b3f`), **Due Amber** (`#8a5a00`), **Overdue Red** (`#b3261e`): status badges, stat trends, timeline icons. In dark mode they lift to `#4cc47f` / `#e0a13a` / `#ff6961`; on paper (`[data-paper]`) they always restore to the light step.
- **Label Yellow** (`#ffd84d`): price tags only — a tiny highlight, never a fill, never text (1.4:1 on white).

### Named Rules
**The One Blue Rule.** There is no second accent. Secondary actions are neutral-filled or outlined; charts use the blue plus greys and the semantic three.
**The Paper Island Rule.** A print sheet is ink on white in both themes; every semantic token restores to its `-on-light` step inside `[data-paper]`.
**The Meaning Rule.** Colour carries meaning, not decoration: blue = actionable, green/amber/red = money or work state, yellow = a price tag.

## Typography

**Display Font:** Estedad (with Vazirmatn, system-ui)
**Body Font:** Vazirmatn (with system-ui)
**Figures:** Vazirmatn with `tabular-nums` (`'tnum' 1`)

**Character:** a Persian-first pairing — a compact geometric display face over a neutral
text face, set with more leading than Latin would need. Headings tighten as they grow.

### Hierarchy
- **Display** (800, 4.5rem, 1.08, −0.022em): hero only.
- **Headline** (700, 1.75rem–2.5rem, 1.2, −0.015em): page and section titles.
- **Title** (700, 1.3125rem, 1.2): card and group headings; also the shell `h1` on product pages (1.125–1.25rem).
- **Body** (400, 1.0625rem, 1.65): prose; 15px in dense registers.
- **Label** (500, 0.8125rem / 0.75rem): eyebrows, meta, table headers.
- **Figure** (700, tabular): money and counts, aligned on the units digit.

### Named Rules
**The Three Digits Rule.** Prose uses Persian digits; tables use Latin tabular digits; IMEI, phone and serial are Latin, LTR-isolated and ungrouped.
**The No SF Pro Rule.** Only self-hosted, licensed Persian faces ship.

## Layout

A sticky frosted sidebar (`w-72`) and a 64px frosted topbar frame one content column of
1110px (`--container-shell`) with 16–32px side padding; full-bleed sections may use 1400px.
Section rhythm is 144px on desktop and 88px on mobile. Rows are 44px, 36px under
`data-density="compact"` (POS, dense tables). Multi-column bands split at `xl`, never `lg`,
because the sidebar arrives at `lg` and the content column is narrower at 1024 than at 768.
Everything is RTL with logical properties; the mobile drawer opens from the physical right.

## Elevation & Depth

Hybrid, ground-first: depth comes from ground alternation and whitespace, then hairlines,
and only then from three near-colourless shadows. Overlays never use Tailwind's stock ramp.

### Shadow Vocabulary
- **low** (`0 1px 2px rgb(0 0 0 / 0.04), 0 1px 3px rgb(0 0 0 / 0.04)`): a card resting on the page.
- **mid** (`0 2px 6px rgb(0 0 0 / 0.05), 0 8px 20px rgb(0 0 0 / 0.06)`): a surface that opened over the page and will close — menu, select, popover.
- **high** (`0 4px 12px rgb(0 0 0 / 0.06), 0 16px 40px rgb(0 0 0 / 0.08)`): a surface that has taken the page — dialog, sheet.

### Named Rules
**The Ground First Rule.** If a surface needs a shadow to be seen, its ground is wrong.

## Shapes

Pills for every action (9999px, enforced on `[data-slot="button"]`), 18px cards, 12px
controls, 8px chips and tight cells. Borders are 1px hairlines at 8% alpha — a crease, not a
rule. Nav rows are pills; status badges are pills; inputs and pickers are 12px rounded
rectangles. No nested cards; a toned callout is a notice, not a card.

## Components

### Buttons
- **Shape:** full pill (9999px), 40px tall (`sm`/`xs` only for controls nobody taps)
- **Primary:** Counter Blue fill, white text, 0 20px padding; the only `brand` fill per view
- **Hover / Focus:** `#005bbb`; a 2px blue ring offset from the pill
- **Secondary / Outline / Ghost:** `#e8e8ed` fill with ink; white fill with a hairline; transparent with ink

### Chips
- **Style:** `StatusBadge` — pill, muted ground, a 6px dot and the label in the semantic tone; the single status→tone→label map for the product
- **State:** filter chips in `FilterBar` are 40px pills, filled blue when active

### Cards / Containers
- **Corner Style:** 18px
- **Background:** white on the grey ground, grey on the white ground (`ground` variant)
- **Shadow Strategy:** `low` at rest; `mid` only when lifted (`elevated`)
- **Border:** hairline 8%
- **Internal Padding:** 16 / 24 / 32px (`sm` / `md` / `lg`)

### Inputs / Fields
- **Style:** white, 12px radius, 1px stroke at 16% alpha, 40px tall, label above
- **Focus:** stroke turns Counter Blue with a 2px ring
- **Error / Disabled:** red stroke and actionable Persian message under the field; 50% opacity

### Navigation
- Sidebar: frosted white at 72% with `saturate(180%) blur(20px)`, 44px pill rows, active row `rgb(0 102 204 / 0.10)` ground with blue semibold text and a Lucide icon at the end; section labels as small muted eyebrows. Topbar: command palette, branch switcher, theme toggle, user menu. Mobile: a right-side sheet with the same nav.

### Money ladder (signature)
`MoneyLadder` aligns totals on a fixed `9ch` figure track so every rung shares one right edge; the unit sits on its own line, never inside a rung.

## Do's and Don'ts

### Do:
- **Do** alternate white and `#f5f5f7` grounds to separate sections; keep borders for tables and cards only.
- **Do** keep one `brand` fill per view and render every status through `StatusBadge`.
- **Do** use `Money`, `Num`, `JDatePicker`, `DataTable`, `FilterBar`, `PageHeader`, `EmptyState` rather than page-local markup.
- **Do** keep touch targets at 40px and register rows keyboard-reachable.
- **Do** restore semantic tokens to their light step inside `[data-paper]`.

### Don't:
- **Don't** introduce a second accent, a gradient, or glass outside the nav and sidebar.
- **Don't** use physical direction classes or mirror `ArrowLeft`/`ArrowRight`.
- **Don't** hardcode a hex, a size, a z-index or a duration; every value is a token.
- **Don't** use Tailwind's `shadow-md`/`shadow-lg` or a `ring` as an edge.
- **Don't** fake a print sheet with `bg-white text-black`.
