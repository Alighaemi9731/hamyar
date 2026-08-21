# ADR 0017 — One host for the application; tenant from the session, not the hostname

- **Status:** accepted 2026-08-21 (owner decision)
- **Amends:** [ADR 0002](0002-single-db-tenancy-rls.md) — the *resolution* mechanism only
- **Changes:** CLAUDE.md golden rule 1b's "Tenants resolve by `domains.hostname` rows"

## Context

Every shop has had its own hostname: `<shop>.<apex>`, resolved by `ResolveTenant` against
a `domains.hostname` row, with an unknown host 404ing rather than falling back. Sessions
are host-only cookies, so a session established at shop-a cannot be presented at shop-b —
the isolation is partly a property of the URL.

The owner has decided against it: **every shop signs in at one address, `app.<apex>`, and
lands in its own environment.** The same form admits a platform administrator to the
Filament panel.

## Decision

1. **`app.<apex>` serves the authenticated application.** Tenant comes from the session,
   established at login.
2. **Per-shop subdomains are removed**, not merely deprecated.
3. **A mobile number identifies one account in one shop.** `users.mobile` becomes globally
   unique.
4. **Public token routes move to `app.<apex>` too**, which forces the change below.

## What this costs, stated plainly

This is the highest-risk change available in this codebase. Golden rule 1 calls a tenancy
violation a bug to fix before anything else, and this edits the mechanism that rule is
about. Three consequences are not obvious from the decision itself.

### `tracking_token` must become globally unique

The QR code printed on every repair intake receipt is
`https://<shop>.<apex>/t/<tracking_token>`, and the token is
`unique(['tenant_id', 'tracking_token'])` — **per tenant**. The hostname was supplying the
tenant. With one host, `app.<apex>/t/<token>` has to identify a ticket on its own.

So the token joins the list `TenancyCheckCommand` already keeps for exactly this shape —
*"a credential whose whole job is to be unguessable and to resolve before any tenant is
known"* — beside `price_list_links_lookup_unique` and `invitations_token_hash_unique`.
That list exists because this is a known, reviewed category, not a loophole.

**Timing is the only reason this is cheap.** Nothing has launched, so no customer is
holding a receipt whose QR points at a hostname that will stop existing. After launch this
same change would break paper already in people's pockets, and would need the old hosts
kept alive purely to redirect.

### The URL stops being part of the isolation story

Host-only session cookies meant a stolen shop-a session was useless at shop-b because the
browser would not send it. On one host that protection disappears and the session's own
`tenant_id` carries the whole weight. It is server-side state and not attacker-controlled,
so this is sound — but it is a *different* guarantee, and the isolation suite has to be
rewritten to prove the new one rather than the old.

45 test files carry the `isolation` group and 59 build tenant URLs from a hostname. None
may be quietly weakened while being moved: a suite that still passes because it stopped
asserting anything is the failure mode golden rule 8 exists to prevent.

### A global unique index on `mobile` leaks account existence

The current migration says so explicitly, and it was a deliberate choice:

> *Unique PER TENANT, not globally: the same person may work at two shops, and two
> unrelated shops may each have an "info@" address. A global unique index here would leak
> the existence of other tenants' accounts through registration errors.*

With a global index, "this number is already registered" tells a stranger that the number
has an account somewhere. That is accepted as the price of a single login form, and the
registration flow must therefore not say *which* shop.

## Migration

The mobile index cannot be created while duplicates exist. The migration **fails loudly
and names the conflicts** rather than choosing a winner — silently keeping one of a
person's two accounts and orphaning the other is not a decision a migration gets to make.

On staging exactly one conflict exists: `09391601280` in tenants 53 (`testme`) and 54
(`mobitest`), both empty test shops created minutes apart.

## Consequences

- `domains` is retired as a resolution mechanism. It stays as the record of a shop's slug.
- `bin/check-apex-domain` still applies: `app.<apex>` comes from `config('app.domain')`,
  never a literal.
- `TenantProvisioner` stops issuing hostnames.
- The nav's «ورود» returns to the landing page once this lands; it is absent meanwhile
  rather than pointing at a URL that does not exist.
