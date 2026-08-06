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

### Palette

| Token | Value | Use |
|---|---|---|
| `paper` | `#F7F9FB` | Light page ground — cool and clean, deliberately not warm cream |
| `ink` | `#0E1B2C` | Primary text and dark surfaces |
| `brand` | `#0FA3A8` | Persian-tile turquoise: CTAs, links, active states |
| `label` | `#FFD84D` | Price-label yellow — **tiny highlights only, never a large fill** |
| `success` | `#12A150` | Paid · cleared · delivered · in stock |
| `warning` | `#D97E00` | Due soon · awaiting · reserved |
| `danger` | `#D3363C` | Overdue · bounced · void · abandoned |
| `info` | `#2563C9` | In flight, nothing to do |

`brand` ships as a 50–900 ramp. Dark mode uses `brand-400` for primary because
full-saturation turquoise on a dark ground fails AA for text.

The yellow is a signature, not a surface. At scale it reads as a warning, which is the
opposite of the "price tag" association it exists to create.

### Surfaces

Light/dark is a **variable swap** (`:root` and `.dark`), never a per-component colour
override. Components consume `--background`, `--surface`, `--card`, `--border`,
`--primary`, `--muted-foreground` and friends — not the raw palette.

The theme class is applied by an inline script in `app.blade.php` **before first
paint**, so switching never flashes white.

### Typography

- Headings — **Estedad** 700/800, tight tracking
- Body — **Vazirmatn** 400/500/700
- Both self-hosted via Fontsource. No CDN, no Latin-only extra face: an Iranian shop
  on mobile data should not download a font it cannot read.
- Line height 1.7 for body: Persian needs more leading than Latin at the same size.
- Scale: 12 / 14 / 16 / 18 / 22 / 28 / 36 / 52.

### Shape and depth

- Radius: 12px cards (`--radius-card`), 8px controls (`--radius-control`).
- **Two** shadow levels. No coloured or rainbow shadows.

### Layout and density

- 12-column RTL grid, 1152px container (`--container-shell`).
- 96px vertical section rhythm (`--spacing-section`).
- `--density-row` is 44px by default and 36px under `data-density="compact"`, which
  POS and table-heavy screens set. 36px rows are for scanning, never for touch targets.
- Named z-index tokens (`--z-sticky` … `--z-toast`). Never inline a magic number.

---

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

6. **Domain components** live in `resources/js/components/domain/` and are used rather
   than rebuilt:
   `Money` · `Num` · `JDatePicker` · `JDateRange` · `IMEIInput` · `PartyPicker` ·
   `UnitPicker` · `StatusBadge` · `StatCard` · `DataTable` · `EmptyState` ·
   `KanbanCard` · `PrintLayout.Thermal80` · `PrintLayout.A4` · `PrintLayout.A5`.

7. **Forms.** Label above the field. Error under the field, in actionable Persian —
   «مبلغ نمی‌تواند از مانده فاکتور بیشتر باشد», not «خطا». One `brand` button per
   view. Enter submits on POS screens. Inherently-LTR inputs get inner `dir="ltr"`
   while the label layout stays RTL.

8. **Accessibility floor.** Visible focus ring, AA contrast, touch targets ≥ 40px,
   `prefers-reduced-motion` honoured on every animation.

9. **Empty states are a screen state, not a fallback.** Say what is missing and give
   the next action. «موردی یافت نشد» tells a shop owner nothing.

10. **Print is part of the system.** Thermal 80mm and A4/A5 templates live under
    `PrintLayout.*`. No page-local `@media print` hacks.

---

## 3. Workflow

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

## 4. Landing page {#landing}

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

## 5. Persian copy

- All UI strings in `lang/fa/**`. Never hardcode Farsi in a component.
- Domain terms, fixed translations: فاکتور invoice · پیش‌فاکتور quote · حواله transfer ·
  انبارگردانی stock count · قبض پذیرش repair intake receipt · رسوبی abandoned device ·
  چک cheque · قسط installment · صندوق cash account · کارتخوان POS terminal account ·
  طرف حساب party · معاوضه trade-in · همکار reseller price level · همتا HAMTA.
- Be honest in the UI where the product is limited: the HAMTA module states plainly
  that there is no official API and that it provides record-keeping and guidance.
