# MobiShop — Design system

English source of truth for the UI. The `.claude/skills/mobishop-ui/SKILL.md` file is
the per-session enforcement summary of this document; when the two disagree, this one
wins and the skill gets updated.

The audience is a phone-shop owner in Iran, often on a mid-range Android and a slow
connection, working at a counter with a customer waiting. Every decision below serves
clarity and speed over showmanship.

---

## 1. Tokens

Defined once, in `resources/css/app.css` under Tailwind v4 `@theme`. **Pages never
hardcode a colour or a size.**

The visual language is set by [ADR 0008](adr/0008-visual-language.md): calm neutral
ground, one accent, whitespace as the primary structuring device, and colour reserved
for things you can act on.

### Ground and text

| Token | Value | Use |
|---|---|---|
| `canvas` | `#ffffff` | Primary section ground |
| `canvas-alt` | `#f5f5f7` | Alternating section ground |
| `canvas-invert` | `#000000` | Feature band — rare, high impact |
| `ink` | `#1d1d1f` | Primary text — 16.8:1 on white |
| `ink-soft` | `#6e6e73` | Secondary text — 5.07:1 on white, 4.66:1 on `canvas-alt` |

Sections **alternate** `canvas` and `canvas-alt`. That alternation, not borders, is
what separates one block of content from the next.

### The one accent

| Token | Value | Use |
|---|---|---|
| `brand` | `#0066cc` | Links, primary fills, active states, focus ring |
| `brand-hover` | `#005bbb` | Hover |
| `brand-on-dark` | `#409cff` | Dark mode — 7.4:1 on black |

**One accent, and it is the only chromatic colour in the chrome.** Blue means *you can
act on this*. A second accent would destroy that rule, so there isn't one — secondary
actions are neutral-filled or outlined.

It is named `brand`, not `accent`, because shadcn already owns `--color-accent` for
its muted hover surface.

`#0066cc` rather than a brighter blue is a measured choice: `#0071e3` falls to 4.31:1
on `#f5f5f7`, below AA, and half our sections use that ground.

### Semantics

| Token | Value | Meaning | Contrast on white |
|---|---|---|---|
| `success` | `#0f7b3f` | paid · cleared · delivered · in stock | 5.35:1 |
| `warning` | `#8a5a00` | due soon · awaiting · reserved | 5.93:1 |
| `danger` | `#b3261e` | overdue · bounced · void · abandoned | 6.54:1 |
| `info` | `#0066cc` | in flight, nothing to do | 5.57:1 |

Muted to sit inside a near-monochrome page, but every value clears AA on both grounds.
A status badge is information, not decoration.

`label` (`#ffd84d`) survives as a signature for **price tags only** — tiny highlights,
never a fill. At scale it reads as a warning, which is the opposite of what it means.

### Surfaces

Light/dark is a **variable swap** (`:root` and `.dark`), never a per-component colour
override. Components consume `--background`, `--surface`, `--card`, `--border`,
`--primary`, `--muted-foreground` — not the raw palette.

Dark mode grounds on true black with `#1d1d1f` surfaces, and lifts the accent to
`brand-on-dark`, because the light-mode blue is unreadable on black.

The theme class is applied by an inline script in `app.blade.php` **before first
paint**, so switching never flashes white.

### Typography

- Headings — **Estedad** 600/700/800, tight tracking
- Body — **Vazirmatn** 400/500/600/700
- Self-hosted via Fontsource. **We do not ship SF Pro** — it is Apple's and not
  licensed to us. Estedad and Vazirmatn are the right answer regardless: this product
  is Persian-first.
- Body 17px at **1.65** leading; Persian needs more leading than Latin at the same size.
- Scale: 12 / 13 / 15 / **17** / 21 / 28 / 40 / 56 / 72.
- Tracking tightens as size grows: `-0.015em` headings, `-0.022em` display. Large type
  needs negative tracking to hold together.
- **`tabular-nums` on every financial figure — unchanged.**

### Shape and depth

- **Actions are pills** (`9999px`), enforced at the system level on
  `[data-slot="button"]` so no page has to remember and a shadcn re-add cannot undo it.
- Cards 18px (`--radius-card`), controls 12px (`--radius-control`), chips 8px.
- **Hairline borders** at 8% alpha — a crease, not a rule. That is what keeps a dense
  table from looking like a spreadsheet.
- Two very soft, near-colourless shadows. Elevation comes from ground contrast and
  whitespace first; shadow is the last resort.

### Space and layout

- Content column 1110px (`--container-shell`), wide sections 1400px.
- **Section rhythm 144px** (`--spacing-section`), 88px on mobile — 1.5× the previous
  value. Generous whitespace is the single biggest carrier of this language; cutting it
  undoes the rest.
- `--density-row` is 44px by default and 36px under `data-density="compact"`, which POS
  and table-heavy screens set. 36px rows are for scanning, never for touch targets.
- Named z-index tokens (`--z-sticky` … `--z-toast`). Never inline a magic number.

### Chrome and motion

- `.glass` — sticky frosted nav and sidebar: `backdrop-filter: saturate(180%) blur(20px)`
  over a translucent ground. The `saturate()` is load-bearing; without it the blur reads
  grey and dead. Confined to the two persistent surfaces, because `backdrop-filter`
  costs GPU on the mid-range Android our users actually carry.
- `.reveal` — fade + rise, 0.5s, 12px of travel, with `.reveal-delay-1..3` for
  stagger. That is the entire motion vocabulary. `prefers-reduced-motion` disables it.

## 2. Hard rules

1. **Logical utilities only.** `ms-` `me-` `ps-` `pe-` `start-` `end-` `text-start`
   `text-end` `border-s` `border-e` `rounded-s` `rounded-e`.
   Any `ml- mr- pl- pr- left- right- text-left text-right float-left` is a **build
   failure** — `bin/check-direction-classes` runs inside `composer test` and in CI.

   The escape hatch is a `rtl-allow` comment on the same or preceding line, with a
   reason. It is legitimate for genuinely physical APIs — Radix's `Sheet side="left"`
   must pin to the physical left — and almost nothing else.

2. **shadcn/ui is the base kit**, with `"rtl": true` in `components.json`. The CLI
   emits logical classes for new components; anything pasted or inherited needs
   `npx shadcn migrate rtl` once.

3. **Radix portals need explicit `dir`.** They render outside the RTL root, so
   placement and animation invert without it. Menu-style primitives
   (`DropdownMenu`, `ContextMenu`, `Menubar`) take `dir` on the **Root** — it drives
   keyboard navigation too. Plain popovers (`Popover`, `Dialog`, `Sheet`, `Tooltip`)
   take it on the **Content**.

4. **Digits have three modes**, and mixing them is the most common visual bug:

   | Context | Mode | Why |
   |---|---|---|
   | Prose | Persian (`۳ دستگاه`) | Reads naturally |
   | Tables, invoices | Latin tabular | Columns must align |
   | IMEI, phone, barcode, serial | Latin, LTR-isolated, ungrouped | Must be readable over the phone and typeable into HAMTA |

   Use `<Num/>`. Never inline a conversion.

5. **Money renders only through `<Money/>`.** Integer rial in, formatted string out.
   Dates only through the Jalali helpers. Statuses only through `<StatusBadge/>`,
   which owns the single status→colour map for the whole product.

6. **Layout primitives** carry the frame so pages never re-invent spacing:
   `AppShell` (frosted sticky nav + sidebar, 1110px column), `AuthLayout` (every
   unauthenticated screen — login, onboarding, reset, 2FA challenge, invitation) and
   `SettingsSection` (one settings card). A page that hand-rolls its own auth frame or
   card padding is how the system drifts back to per-page styling; consolidate instead.

7. **Domain components** live in `resources/js/components/domain/` and are used rather
   than rebuilt:
   `Money` · `Num` · `JDatePicker` · `JDateRange` · `IMEIInput` · `PartyPicker` ·
   `UnitPicker` · `StatusBadge` · `StatCard` · `DataTable` · `EmptyState` ·
   `KanbanCard` · `PrintLayout.Thermal80` · `PrintLayout.A4` · `PrintLayout.A5`.

8. **Forms.** Label above the field. Error under the field, in actionable Persian —
   «مبلغ نمی‌تواند از مانده فاکتور بیشتر باشد», not «خطا». One `brand` button per
   view. Enter submits on POS screens. Inherently-LTR inputs get inner `dir="ltr"`
   while the label layout stays RTL.

9. **Accessibility floor.** Visible focus ring, AA contrast, touch targets ≥ 40px,
   `prefers-reduced-motion` honoured on every animation.

10. **Empty states are a screen state, not a fallback.** Say what is missing and give
   the next action. «موردی یافت نشد» tells a shop owner nothing.

11. **Print is part of the system.** Thermal 80mm and A4/A5 templates live under
    `PrintLayout.*`. No page-local `@media print` hacks.

---

## 3. Formatting

`.prettierrc.json` is the single formatting authority for `resources/js` — single
quotes, 100 columns, ES5 trailing commas. Run `npm run format`; `npm run format:check`
verifies. Formatting arguments are not a good use of review time, and an unconfigured
formatter silently rewrites the whole codebase to its own defaults the first time
somebody runs it.

## 4. Workflow

1. New or changed component → add it to the `/design` gallery with its **state
   matrix** (default, hover, focus, disabled, loading, error, empty) **before** it
   appears in a feature page.
2. Review it at 390px and 1280px, in light and dark, in RTL. Playwright MCP if
   available.
3. UI diff review checklist:
   - Physical direction classes?
   - Raw hex values?
   - Status colours mapped inline instead of `<StatusBadge/>`?
   - Non-tabular digits in a table?
   - Missing `dir` on a portal?
   - Missing empty and loading states?

The gallery is dev-only (`local`/`testing`). It is a development tool and shipping it
would put an un-audited surface on every tenant subdomain.

---

## 5. Landing page {#landing}

Built in Phase 11. **Blade + Tailwind, no React** — the public pages must be fast and
SEO-friendly.

### Position: "immersive-lite"

Not a heavy immersive site. The audience is a shop owner on a mid-range Android on an
Iranian connection; WebGL, 3D and scroll-jacking mean a high LCP, a bounce, and worse
SEO. The page's job is conversion to a 14-day trial, not a design award. For a B2B
tool, clarity of value beats spectacle.

So: **one signature interactive moment, and everything else calm, fast and precise.**

### Performance budget (hard limits)

- No WebGL
- Landing JS ≤ **180KB gzipped**
- LCP < **2.5s on 4G**
- `prefers-reduced-motion` fully honoured

### The signature element

A **live thermal receipt** in the hero: an 80mm receipt with a perforated edge that
"prints" line by line and tells the product's real story —

repair intake → "ready for collection" SMS → settlement → HAMTA transfer.

Scrolling advances it through **3 frames**. No scroll-jacking. This is the only
expensive animation on the page; everything else is a gentle reveal.

### Tone and copy

Persian, verb-led and concrete. «هر گوشی یک شناسنامه IMEI دارد» — never «راهکار جامع
هوشمند». The subject matter is the real world behind the counter: IMEIs, yellow price
labels, barcodes, thermal receipts, parts drawers. Professional and market-fluent,
without affectation.

Primary CTA: **«۱۴ روز رایگان شروع کنید»** — no bank card.

### Section order

1. Hero — core claim + live receipt + dual CTA (start free / 3-minute demo)
2. Trust bar — "suitable for sales, repairs, installments, cheques" + real counters after launch
3. Modules as problem→solution — 6 cards: serialized sales, repairs, installments/cheques, SMS, multi-branch, profit reporting
4. **IMEI passport** — a real product screenshot with a one-line explanation. This is the differentiator; give it room.
5. Pricing — three plans + add-ons
6. FAQ — HAMTA? Moadian? migrating from my old software? who owns my data?
7. Closing CTA + footer

---

## 6. Persian copy

- All UI strings in `lang/fa/**`. Never hardcode Farsi in a component.
- Domain terms, fixed translations: فاکتور invoice · پیش‌فاکتور quote · حواله transfer ·
  انبارگردانی stock count · قبض پذیرش repair intake receipt · رسوبی abandoned device ·
  چک cheque · قسط installment · صندوق cash account · کارتخوان POS terminal account ·
  طرف حساب party · معاوضه trade-in · همکار reseller price level · همتا HAMTA.
- Be honest in the UI where the product is limited: the HAMTA module states plainly
  that there is no official API and that it provides record-keeping and guidance.
