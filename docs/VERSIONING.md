# Versioning policy

The project version lives in **`VERSION`** at the repository root and nowhere else.
`config('app.version')` reads that one file, so there is no mirror to drift. It is a
Semantic Version `MAJOR.MINOR.PATCH`, and `bin/release` refuses to tag a version that
does not match the file.

The single question to ask: **what is the largest kind of change in this release?**

## MAJOR — needs a human before it is safe

Upgrading needs **manual operator action**, or an existing contract or existing data
breaks. In this product that is almost always one of four things:

- **A migration that is not backward-compatible for one release.** This is the big one,
  and it is a property of *our* deploy rather than of Postgres. Blue/green means both
  releases serve traffic against one already-migrated database for a few seconds
  (`docs/deploy.md` §3). Dropping a column in the same release that stops writing it
  takes the site down for the length of the overlap — and the 500s come from the **old**
  container, so it reads as a reason to roll back rather than as this release's fault.
  Additive now, destructive next release: that is two releases, and the destructive one
  is MAJOR.
- **A changed `.env.production` contract** — a renamed or removed required variable, a
  new mandatory secret. The file lives only on the box, so a release that needs a new key
  is a release that needs somebody to go and add it.
- **A narrowed or removed RLS policy, or a change to how `app.tenant_id` is pinned.**
  Tenancy is the product's one hard guarantee; a change here that needs a data migration
  or a re-grant is not something to discover from a 500.
- **A removed or renamed route, permission name, or Pennant feature key** that a shop's
  saved link, a printed QR code, or an existing plan row depends on.

If a normal `bin/release --deploy` would break the box or need hand-holding → MAJOR.

## MINOR — a new capability that upgrades cleanly

A new backward-compatible feature, or a substantial enhancement. Existing shops upgrade
with no manual step:

- a new module, screen, report, or workflow (a new Pennant feature that defaults off);
- a new payment method, a new SMS template kind, a new export;
- a new plan or add-on;
- a meaningful new setting that defaults to today's behaviour.

## PATCH — no new capability

- a bug fix, a correctness fix, a security patch;
- a performance fix (the dashboard query work is PATCH, not MINOR — it makes an existing
  screen do the same thing faster);
- a refactor, a dependency bump, a copy or UI tweak, a docs-only change;
- an **additive nullable** column applied automatically by a migration with no behaviour
  change.

## Decision checklist

1. Would upgrading need a manual step, or does it break an existing contract, route or
   row? → **MAJOR**
2. Otherwise, does it add a capability a shopkeeper can reach? → **MINOR**
3. Otherwise → **PATCH**

## While the leading number is 0

`VERSION` starts at `0.12.0`. There are no paying shops on the box yet, and calling that
`1.0.0` would claim something untrue.

So until then: a MAJOR-shaped change bumps **MINOR** (`0.12.x` → `0.13.0`) and its
CHANGELOG entry is prefixed **BREAKING** — the signal that matters is in the changelog,
not in the digit. The rest of the policy is unchanged.

**`1.0.0` is reserved for the release the first paying shop runs.** That is the same
moment as the tripwire in `CLAUDE.md`: the day a real shop's data lands on the box,
`migrate:fresh` becomes unthinkable, the load test needs its own machine, and this
paragraph gets rewritten by whoever onboards them.
