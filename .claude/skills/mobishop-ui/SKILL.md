---
name: mobishop-ui
description: Use when building or changing ANY user-facing UI in this repo — Inertia/React pages, Blade public pages, emails, or print layouts. Enforces the MobiShop design system: RTL logical classes, design tokens, Persian typography/digits, shadcn/ui usage, domain components, and the /design gallery workflow. Also use when reviewing UI diffs.
---

# MobiShop UI — design system rules

Source of truth: `docs/design-system.md` (read it when unsure). This skill is the
per-session enforcement summary.

## Tokens (never hardcode raw colors/sizes in pages)
| token | value | use |
|---|---|---|
| paper | #F7F9FB | app/page background (light) |
| ink | #0E1B2C | primary text, dark surfaces |
| brand | #0FA3A8 | CTAs, links, active states |
| label | #FFD84D | tiny highlights only (price tags, badges) — never large fills |
| success | #12A150 | paid / cleared / delivered |
| warning | #D97E00 | due soon / awaiting |
| danger | #D3363C | overdue / bounced / failed |

Defined once in `resources/css/app.css` under `@theme`. Radius 8/12. Two shadow
levels. Dark mode via CSS vars — never per-component color overrides.

## Hard rules
1. RTL: logical utilities ONLY — `ms-/me-/ps-/pe-/start-/end-/text-start/text-end`.
   Any `ml- mr- pl- pr- left- right- text-left text-right` in a diff = bug.
2. shadcn/ui is the base kit; `components.json` has `"rtl": true`. Pass `dir="rtl"`
   explicitly to Radix portal components (DropdownMenu, Popover, Select, Dialog).
   Directional icons get `rtl:rotate-180` when not auto-handled.
3. Inherently-LTR inputs (IMEI, phone, amounts shown in Latin digits) render with
   inner `dir="ltr"` while keeping the field's start-aligned label layout.
4. Typography: headings = Estedad (700/800); body = Vazirmatn; all financial/tabular
   numbers use `tabular-nums`. Persian digits in prose; Latin tabular digits in
   tables/invoices (tenant setting decides — use the `<Num/>` helper, don't inline).
5. Money is rendered ONLY via `<Money/>` (IRR integer in, formatted out). Dates ONLY
   via Jalali components/helpers. Statuses ONLY via `<StatusBadge/>` (single
   status→color map — never map colors ad hoc in a page).
6. Domain components live in `resources/js/components/domain/` and must be used
   instead of rebuilding: Money, Num, JDatePicker, JDateRange, IMEIInput,
   PartyPicker, UnitPicker, StatusBadge, StatCard, DataTable, EmptyState,
   KanbanCard, PrintLayout.Thermal80, PrintLayout.A4, PrintLayout.A5.
7. Forms: label above field; error text under field, actionable Persian copy;
   primary action is the only `brand` button per view; Enter submits in POS screens.
   Compact density token on POS/table-heavy screens.
8. Accessibility floor: visible focus ring, AA contrast, touch targets ≥ 40px,
   `prefers-reduced-motion` respected on every animation.
9. Print layouts are part of the system: thermal 80mm and A4/A5 templates under
   PrintLayout.* — never page-local `@media print` hacks.
10. Public/marketing pages (Blade) follow the landing brief in
    `docs/design-system.md#landing`: signature "live thermal receipt" hero,
    landing JS budget ≤ 180KB gz, no WebGL/scroll-jacking.

## Workflow
- New/changed component → add or update its state matrix on the `/design` gallery
  route (dev-only) BEFORE using it in a feature page.
- After UI work, verify visually: open the page (Playwright MCP if available),
  screenshot at 390px and 1280px in RTL, check dark mode, check focus order.
- UI diff review checklist: physical classes? raw hex? inline status colors?
  non-tabular numbers in tables? missing dir on portals? missing empty/loading state?
