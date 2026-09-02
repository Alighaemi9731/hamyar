---
name: hamyar-ui
description: Use when building or changing ANY user-facing UI in this repo — Inertia/React pages, Blade public pages, emails, or print layouts. Enforces the Hamyar design system: RTL logical classes, design tokens, Persian typography/digits, shadcn/ui usage, domain components, and the /design gallery workflow. Also use when reviewing UI diffs.
---

# Hamyar UI — design system rules

Source of truth: `docs/design-system.md` (read it when unsure). Direction is set by
`docs/adr/0008-visual-language.md`. This skill is the per-session enforcement summary.

**Visual language in one line:** calm neutral ground, ONE blue accent, whitespace doing
the structuring, pill actions, hairline borders, very soft shadows.

## Tokens (never hardcode raw colors/sizes in pages)

| token | value | use |
|---|---|---|
| canvas | #FFFFFF | primary section ground |
| canvas-alt | #F5F5F7 | alternating section ground |
| canvas-invert | #000000 | rare feature band |
| ink | #1D1D1F | primary text |
| ink-soft | #6E6E73 | secondary text |
| **brand** | **#0066CC** | the ONLY accent — links, primary fills, active, focus |
| brand-on-dark | #409CFF | dark mode accent |
| success | #0F7B3F | paid / cleared / delivered |
| warning | #8A5A00 | due soon / awaiting |
| danger | #B3261E | overdue / bounced / failed |
| label | #FFD84D | price tags only — tiny highlights, never a fill |

Defined once in `resources/css/app.css` under `@theme`. Radius: pill for actions,
18px cards, 12px controls. Three soft shadows — `low` (resting card), `mid` (menu,
select, popover), `high` (dialog, sheet); never Tailwind's `shadow-md`/`shadow-lg`.
Dark mode via CSS vars — never a per-component color override.

**Colour carries meaning, not decoration.** Blue = "you can act on this". The semantic
three = money/work state. Everything else is neutral. There is no second accent; do
not introduce one. Secondary actions are neutral-filled or outlined.

Note: the accent is `brand`, not `accent` — shadcn owns `--color-accent` for its muted
hover surface.

## Hard rules

1. RTL: logical utilities ONLY — `ms-/me-/ps-/pe-/start-/end-/text-start/text-end`.
   Any `ml- mr- pl- pr- left- right- text-left text-right` in a diff = bug.
   `bin/check-direction-classes` fails the build. Escape hatch: `rtl-allow` comment on
   the same or preceding line, with a reason.
2. shadcn/ui is the base kit; `components.json` has `"rtl": true`. Every Radix
   primitive is RTL already: `app.tsx` mounts one `Direction.Provider dir="rtl"` at
   the root, so no portal needs `dir` passed by hand (sixty-one sites used to, and the
   ones that forgot opened mirrored). Pass `dir="ltr"` only for a genuinely LTR pocket.
   **Never mirror an icon that names a physical direction.** `ArrowLeft`/`ArrowRight`
   already point the right way in RTL; `rtl:rotate-180` on one sends it backwards —
   thirteen «بازگشت» links shipped that way, and `bin/check-rtl-arrows` now refuses
   it. Mirror only an icon chosen for *reading order* (Pagination's prev/next chevrons).
3. Inherently-LTR inputs (IMEI, phone, amounts shown in Latin digits) render with
   inner `dir="ltr"` while keeping the field's start-aligned label layout. Wrap a
   signed number in `<bdi>` — a minus sign is bidi-neutral and jumps sides otherwise.
4. Typography: headings = Estedad (600/700/800), tighter tracking as size grows; body =
   Vazirmatn at 17px / 1.65 leading; all financial/tabular numbers use `tabular-nums`.
   Persian digits in prose; Latin tabular digits in tables/invoices (tenant setting —
   use the `<Num/>` helper, don't inline). **Never ship SF Pro** — not licensed to us.
5. Money is rendered ONLY via `<Money/>` (IRR integer in, formatted out). Dates ONLY
   via Jalali components/helpers. Statuses ONLY via `<StatusBadge/>` (single
   status→color map — never map colors ad hoc in a page).
6. Layout primitives carry the frame — `AppShell`, `AuthLayout`, `SettingsSection`,
   all built on `<Card>` (`components/ui/card.tsx`), which owns radius, hairline and
   padding. Never hand-roll an auth frame or card padding in a page; extend the
   primitive. Toned callouts are notices, not cards — `Card` has no `tone` prop.
   Domain components live in `resources/js/components/domain/` and must be used
   instead of rebuilding: Money, Num, JDatePicker, JDateRange, IMEIInput,
   PartyPicker, UnitPicker, StatusBadge, StatCard, DataTable, EmptyState,
   KanbanCard, PrintLayout.Thermal80, PrintLayout.A4, PrintLayout.A5.
7. Forms: label above field; error text under field, actionable Persian copy;
   primary action is the only `brand`-filled button per view; Enter submits in POS
   screens. Compact density token on POS/table-heavy screens.
8. Accessibility floor: visible focus ring, AA contrast, touch targets ≥ 40px,
   **40px means 40px** — `sm`/`xs` are not a licence to go under it (settled 2026-08-31).
   Use them only for controls nobody taps. Inline links in prose are exempt, per WCAG
   2.5.8. A 16px checkbox inside a 40px label is correct: the target need not be the box.
   `prefers-reduced-motion` respected on every animation. Every token pair in the
   system has a measured contrast ratio — do not introduce one that has not been
   checked.
9. Print layouts are part of the system: thermal 80mm and A4/A5 templates under
   PrintLayout.* — never page-local `@media print` hacks.
   **Paper is a light island**: a sheet is ink on white in BOTH themes, so every
   semantic token restores to its light step inside `[data-paper]` (one rule in
   `app.css`; `PrintLayout` sets the attribute). Dark-mode `success` #4CC47F is 2.2:1
   on white — a paid stamp vanished exactly this way. Adding a semantic token means
   adding its `-on-light` step AND its `[data-paper]` line. Never fake a sheet with
   `bg-white text-black`: the ground turns white and the badges inside stay dark-step.
10. Public/marketing pages (Blade) follow the landing brief in
    `docs/design-system.md#landing`: signature "live thermal receipt" hero,
    landing JS budget ≤ 180KB gz, no WebGL/scroll-jacking.

## Layout & motion

- Sections **alternate** `bg-background` / `bg-surface-muted`. Alternation separates
  content, not borders.
- Section rhythm 144px desktop / 88px mobile. Whitespace is the primary structuring
  device — cutting it undoes the language.
- Multi-column bands inside `AppShell` split at `xl`, not `lg`. The sidebar appears at
  `lg`, so the content column is narrower at 1024 than at 768 — a row that fits at `md` can
  overflow at the width that looks safest. Measured three times: treasury summary, billing
  plans, and the unit passport's timeline, which ran 704px at 768 and 328px at 1024. The
  first two overflow; the third just collapses, which is quieter and worse.
- Sticky chrome uses the `.glass` class (frosted, `backdrop-filter`). Confined to nav
  and sidebar; it costs GPU on mid-range Android.
- Motion vocabulary is exactly `.reveal` (fade + rise, 12px) plus `.reveal-delay-1..3`.
  Nothing else. Durations and easing are tokens — `duration-(--duration-fast|base|slow)`
  and `ease-(--ease-out)`; never inline a number.
- z-index comes from `--z-index-*` (`z-sticky`, `z-overlay`, `z-popover`, …). The
  namespace matters: Tailwind v4 builds `z-` utilities from `--z-index-*`, and the
  earlier `--z-*` spelling generated no CSS at all while looking correct in the token
  block. **A defined token is not evidence of a generated class — grep the built CSS.**
- One theme authority: the `dark` class on `<html>`, set pre-paint in `app.blade.php` and
  read through `hooks/use-theme.ts`. Never add a theme provider, and never let a component
  keep its own copy of the theme.

## Formatting

`.prettierrc.json` governs `resources/js` (single quotes, 100 cols). `npm run format`.

## Workflow

- New/changed component → add or update its state matrix on the `/design` gallery
  route (dev-only) BEFORE using it in a feature page.
- After UI work, verify visually: open the page, screenshot at 390px and 1280px in
  RTL, check dark mode, check focus order, and assert zero horizontal overflow.
- UI diff review checklist: physical classes? raw hex? a second accent colour?
  inline status colors? non-tabular numbers in tables? missing `dir` on portals?
  missing empty/loading state? unsigned `<bdi>` around a negative number?
