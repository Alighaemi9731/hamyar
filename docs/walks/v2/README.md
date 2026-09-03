# Walks — Redesign v2 (ROADMAP Phase 16)

Captures that back a claim in `docs/PROGRESS.md` or a review. Every set records what a
reviewer needs to compare against it — the thing `docs/walks/redesign/` never wrote down.

| set | date | build | data | widths | themes | tool |
|---|---|---|---|---|---|---|
| `baseline/` | 2026-09-03 | `main` @ `c78d4b2`, `public/build/assets/app-Bl8TVXwP.css` (built 02:32) | `make fresh` demo tenant (`09121234567`), no showcase data | 390 · 1440 fold (full matrix 390/768/1024/1280/1440 was measured; only the folds are tracked) | light (dark measured, not tracked) | Playwright via the `design-reviewer` agent |

Conventions for every later set:

- File name `<screen>-<width>-<theme>[-<variant>].png`; `fold` = viewport only from the
  document top, otherwise full page with entrance motion settled.
- The set's row above names the commit, the seeder, the widths and both themes, so a
  screenshot can be re-taken under the same conditions and diffed.
- Full matrices live in `.impeccable/review/` (gitignored); the repo keeps the captures a
  PROGRESS entry or an ADR actually cites.
