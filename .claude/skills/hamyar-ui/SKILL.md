---
name: hamyar-ui
description: Use when building, changing or reviewing ANY user-facing UI in this repo — Inertia/React pages, Blade public/auth/error pages, emails, print layouts, the landing. Enforces the Hamyar design system (RTL logical classes, tokens, Persian typography and digits, shadcn/ui, domain components, the /design gallery) and says how the global design tooling (impeccable, frontend-design) is used here. Outranks any generic design skill on RTL, Persian, tokens, components and guards.
---

# Hamyar UI — the project's design rules

**Precedence.** `docs/design-system.md` is the source of truth; this skill is its
per-session summary. Both outrank any global or generic design skill (`impeccable`,
`frontend-design`, `ui-ux-pro-max`, a plugin's font list, a palette database) on anything
Persian, RTL, token, component or guard related. Generic advice is a menu; the repo decides.

**Redesign v2 (2026-09) is in flight.** Token *values* are moving into
`resources/css/brand.css` (ADR 0020) and the landing is being rebuilt (ADR 0021). Read the
token values from the CSS, never from memory; this file names roles, not hex codes.

## The system in one paragraph

One ink, one accent (`brand`; never `accent` — shadcn owns `--color-accent`), three
semantics (`success`/`warning`/`danger`), a `label` yellow for price tags only. Pill
actions (enforced on `[data-slot="button"]`), 18px cards, 12px controls, hairline borders,
three soft shadows (`low`/`mid`/`high`), `.glass` only on nav and sidebar, `.reveal` as the
motion vocabulary, durations and easing as tokens. Whitespace and ground alternation
structure a page; borders do not. Dark mode is a variable swap on the `dark` class set
pre-paint in `app.blade.php`.

## Hard rules (each one is a guard or a scar — see `docs/lessons.md`)

1. **Logical utilities only**: `ms- me- ps- pe- start- end- text-start text-end border-s
   border-e rounded-s rounded-e`. Any `ml- mr- pl- pr- left- right- text-left text-right
   float-*` fails `bin/check-direction-classes` (also over `.css` properties). Escape hatch:
   an `rtl-allow` comment on the same or preceding line with a reason — only for genuinely
   physical APIs (`Sheet side="left"`, a print edge).
2. **Radix is RTL from the root** (`app.tsx` mounts one `Direction.Provider dir="rtl"`).
   Never pass `dir="rtl"` to a portal; pass `dir="ltr"` only for a genuinely LTR pocket.
3. **Never mirror an icon that names a physical direction.** `ArrowLeft/ArrowRight` already
   point correctly in RTL; `rtl:rotate-180` on one is refused by `bin/check-rtl-arrows`.
   Mirror only icons chosen for reading order (Pagination's chevrons). A tree-depth chevron
   is a physical direction too.
4. **Digits have three modes** — prose: Persian (`۳ دستگاه`); tables/invoices: Latin
   `tabular-nums`; IMEI/phone/barcode/serial: Latin, LTR-isolated, ungrouped. Always via
   `<Num variant="prose|table|ltr">`; never inline a conversion. Wrap a signed number in
   `<bdi>`.
5. **Money only via `<Money>` / `<MoneyField>` / `<MoneyLadder>`** (integer rial in). Dates
   only via `lib/jalali.ts` + `<JDatePicker>` (wire format UTC ISO). Statuses only via
   `<StatusBadge>` — the single status→tone→label map.
6. **Every form renders every key of the error bag**: `<FormErrors errors={errors}
   handled={[…]} />` in any file that submits (`bin/check-form-errors`; the baseline in
   `bin/.form-errors-baseline` only shrinks — touch a listed file, add the region, delete its
   line in the same commit). Blade forms render `$errors->all()` in the layout.
7. **40px means 40px** for anything tapped; `sm`/`xs` only for controls nobody taps; a 20px
   checkbox inside a 40px label row is correct; unlabelled expanded targets carry
   `data-hit-area="expanded"`. Register rows are keyboard-reachable (tab stop, role, key).
8. **Multi-column bands split at `xl`, never `lg`** — the sidebar arrives at `lg`, so the
   content column is narrower at 1024 than at 768. Check the narrowest column against its
   widest figure at 1024.
9. **Paper is a light island**: a sheet is ink on white in both themes; every semantic token
   restores to its `-on-light` step inside `[data-paper]` (`PrintLayout.*` sets it). Never
   fake a sheet with `bg-white text-black`. A new semantic token needs an `-on-light` step
   and a `[data-paper]` line.
10. **No hostname literal anywhere** — `config('app.domain')`, `url()`, `route()`
    (`bin/check-apex-domain`; `lang/` is scanned too).
11. **Persian strings**: Blade/public pages read `lang/fa/*.php`; React pages carry their
    strings inline (no i18n layer). Every string follows `docs/brand/voice.md` — its
    glossary is the vocabulary — and `bin/check-copy-terms` refuses six of its rules:
    unverifiable adjectives, Arabic ك/ي or ٠–٩, «!» after Persian, a space where a
    compound takes a ZWNJ («ثبت‌نام»), and «مغازه»/«توی»/«رُک». Quoted speech keeps its
    words with a `copy-allow` comment on the line; the baseline is the old landing only.
12. **z-index from `--z-index-*`** (`z-sticky`, `z-overlay`, `z-popover`, `z-toast`). A
    defined token is not evidence of a generated class — grep the built CSS.
13. **One theme authority**: the `dark` class on `<html>`, read through `hooks/use-theme.ts`.
    No theme providers, no per-component theme copies.

## Components — use, never rebuild

- **ui/** (shadcn, `components.json` has `rtl: true`): badge · button · card · checkbox ·
  command · dialog · dropdown-menu · input · input-group · label · popover · select ·
  separator · sheet · skeleton · sonner · table · tabs · textarea · tooltip. Add new ones
  with the shadcn CLI; never paste LTR markup.
- **domain/** (32): `Money` · `MoneyField` · `MoneyLadder`(+`MoneyRow`) · `Num` ·
  `JDatePicker` · `StatusBadge` · `DataTable` · `FilterBar` · `PageHeader` · `EmptyState` ·
  `StatCard` · `Pagination` · `FormErrors` · `ConfirmDialog` · `Timeline` · `ShareBar` ·
  `BarChart` · `ReportPresets` · `HistoryLink` · `IMEIInput` · `PartyPicker` · `UnitPicker`
  · `VariantPicker` · `PickerSkeleton` · `PrintLayout.{Thermal80,A4,A5}` + `printSheet()` ·
  `UsageMeter` · `UsageBanner` · `QuotaBlock` · `AnnouncementBanner` · `CommandPalette` ·
  `BranchSwitcher` · `UserMenu`. Plus `SettingsSection` and `ThemeToggle` in `components/`.
- **Layout primitives**: `AppShell` (one per authenticated page; `header={<PageHeader …/>}`
  is the standard, bare `title=` is legacy) and `SettingsSection`. Auth screens are Blade
  on `resources/views/auth/layout.blade.php` (ADR 0020).
- **Page families** (ADR 0019): register = `PageHeader` + `FilterBar` + `DataTable` +
  `Pagination`; document/ledger = `Card` + `MoneyLadder`; analysis = screen + unchanged
  print sheet via `useReportView`. Inventing a surface needs a reason in the file.
- `Card` has no `tone` — a toned callout is a notice, not a card. No nested cards.

## Typography

Families and scale live in `brand.css` (`--font-display`, `--font-sans`, 12…72 scale,
`--tracking-display`, `--leading-display`). Rules that survive any pairing: display weight
600–700 (never 800 by default); headings tighten tracking as they grow; body ≥ 16px with
Persian leading (≈1.7–1.8 prose, 1.5 in dense tables); `tabular-nums` on every figure;
`font-display` only on `h1` and headline figures. **Never SF Pro, never a font the repo does
not self-host.**

## How the global design tooling is used here

- **impeccable** (global plugin). Run its `context.mjs` once per session; it loads
  `PRODUCT.md`, `DESIGN.md` and a surface brief. Modes: **Persuade** for the landing and
  auth, **Operate** for every product screen, **Read** for legal/help. **Code-led only** —
  there is no image generation and image models mangle Persian; its Google-Fonts matcher and
  Latin font advice never apply. Load `reference/craft-floor.md` before editing UI. Its
  detector hook runs on this project (`.impeccable/config.json`); triage findings — fix real
  ones, record a narrow `ignore-value` with evidence for sanctioned system choices (pills,
  `.glass` on nav, one accent), never an `ignore-rule` on your own judgment. Use its
  `clarify` / `onboard` / `typeset` / `critique` playbooks; its finish-reviewer and
  documenter agents close a build.
- **frontend-design** (global plugin): taste calibration only; it does not know RTL.
- **design-reviewer** (project agent): read-only reviewer with the Hamyar rubric; run it on
  flagship screens after a phase, never inside the build thread.

## Verify before you claim

- Widths **390 · 768 · 1024 (the sidebar trap) · 1280 · 1440**, light + dark, RTL, real
  seeded data (`make fresh`; showcase seeder for rich screens), console read (a CSP refusal
  and a 500 both look fine from PHP), `scrollWidth <= clientWidth + 1`, keyboard order.
- Tools: playwright / chrome-devtools MCP for captures and Lighthouse; `composer guards &&
  composer rtl`; targeted Pest (`--filter`); browser tests need `npm run build`.
- New or changed component → its state matrix on `/design` (local/testing only) **before**
  a feature page. Evidence is a number, not a look.
- Diff checklist: physical classes? raw hex? second accent? inline status colour? non-tabular
  digits in a table? `dir` on a portal? missing empty/loading state? unsigned `<bdi>`?
  submit without `<FormErrors>`? control under 40px? a band splitting at `lg`? a hostname?
