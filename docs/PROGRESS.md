# MobiShop — Progress log

One line per completed roadmap task. Newest at the bottom. Format:

```
YYYY-MM-DD (jYYYY-MM-DD) · <phase.section> · what shipped · notable decision (if any)
```

Dates are Gregorian with the Jalali equivalent in parentheses, because the roadmap
is written in English but the business calendar is Jalali.

---

- 2026-08-06 (j1405-05-15) · 0.1 · Laravel 12 skeleton scaffolded around the pre-seeded files; `.gitignore` merged, not replaced.
- 2026-08-06 (j1405-05-15) · 0.3 · Dev stack up: php-fpm 8.4 + nginx + postgres:16 + redis:7 + minio + mailpit, driven by `Makefile`. Decision: Postgres provisions a **non-owner app role** so RLS can never be bypassed by the connecting user (owners and superusers bypass RLS by default).
- 2026-08-06 (j1405-05-15) · 0.2 · Documentation baseline written: ROADMAP (all 12 phases as checkboxes with the 5 decision gates preserved), architecture, testing, deploy, design-system, ADRs 0001–0003, and one spec file per module.
- 2026-08-06 (j1405-05-15) · 0.4/0.5 · Strict Laravel config (fa locale, UTC storage + Tehran display, CarbonImmutable, strict models) and the module system: `make:module` generator, auto-discovery, all 18 module shells.
- 2026-08-06 (j1405-05-15) · 0.6 · Quality gate wired: `composer test` = Pint → RTL grep → Larastan L8 → Pest. Decision: Pest runs on **real PostgreSQL, never SQLite** — SQLite cannot express RLS, so a green SQLite suite would prove nothing about the isolation guarantee we sell.
- 2026-08-06 (j1405-05-15) · 0.6 · `bin/check-direction-classes` matches real Tailwind value syntax rather than "any word characters", after false positives on English prose (`left-to-right`) and on compound utilities (`slide-in-from-left-2`). It has its own 39-test suite so that precision does not regress.
- 2026-08-06 (j1405-05-15) · 0.7/0.8 · Frontend: Inertia v2 + React 19 + TS, Tailwind v4 `@theme` tokens, shadcn with `rtl:true`, domain components (Money, Num, JDatePicker, StatusBadge, EmptyState) and the `/design` gallery.
- 2026-08-06 (j1405-05-15) · 0.9/0.10 · App shell and login placeholder; Laravel Boost installed with its guidelines below our golden rules plus an explicit precedence note; Boost and Playwright MCP registered and both verified connected.
- 2026-08-07 (j1405-05-16) · 0.2 · ADR 0004 (Postgres-only tests) and ADR 0005 (RTL gate matches Tailwind value syntax) written and approved; ADR 0002 already carried the NOBYPASSRLS app-role decision and now cross-links to 0004. ADR index added; pending proration ADR renumbered to 0006.
- 2026-08-07 (j1405-05-16) · 0.DoD · Repo pushed to GitHub (private) and CI proven end-to-end on PR #1 — all four jobs green. The first three runs failed for genuinely different reasons, each a real defect: the Vite manifest is a gitignored build artefact the Pest job never produces (`withoutVite()` in TestCase); Inertia's page-existence check defaulted to `js/Pages` and macOS's case-insensitive filesystem hid it until Linux CI; and `pest --ci` treats an empty `--group=isolation` run as a failure. Known issue: only `workflow_dispatch` triggers runs on this repo — `push` and `pull_request` produce none.
- 2026-08-06 (j1405-05-15) · 0.DoD · Visual verification found three bugs the server-side tests could not: a missing `TooltipProvider` blanking every page, bidi reordering that displayed `<Money/>` as `</Money>` and pushed minus signs to the wrong side of negative amounts (fixed with `<bdi>` isolation), and a 1px mobile overflow from shadcn's `shrink-0` on the search button. Lesson: Inertia feature tests assert the server payload, not that React mounts — browser checks are not optional.
