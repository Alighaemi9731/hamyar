# Changelog

Every release, what changed, and **why** — the reason rather than the diff, because the
diff is already in Git and it is the one thing that cannot explain itself.

Versions follow `docs/VERSIONING.md`. A release is cut with `bin/release` and is not a
release until `bin/smoke` has confirmed, from outside the box, that the site is serving
it. Tags and published archives: <https://github.com/Alighaemi9731/mobishop/releases>.

## 0.12.0 - 2026-08-22

**A change that is finished is now a change that is live.** MINOR — the first versioned
release. No migration beyond the three RLS read policies described below, no new
`.env.production` key.

This release exists because of the state it found the project in. Twenty-one finished
commits were sitting on `feat/landing-immersive`; production was serving the release from
five commits earlier; `app.<apex>/login` offered a «ثبت نام» link pointing at the landing
host, where `/register` does not exist; and **every check was green**. Nothing was
broken in a way any gate could see. The gap was between "the code is correct" and "the
correct code is what is running", and nothing in the repository measured it.

### Shipped

- **The landing page carries weight.** ADR 0016's direction, built out: a scroll spine,
  real typographic hierarchy, and copy that stopped selling. The previous page was calm,
  correct and بی‌روح.
- **«ثبت نام» resolves.** It was hand-built from `config('app.domain')` — correct while
  login lived on a shop's hostname and sign-up on the central domain, and wrong from the
  moment ADR 0017 put both on `app.<apex>`. The page rendered perfectly and the link
  404'd on click, which is exactly the fault an assertion on a substring cannot see, so
  the test that replaces it *follows the href*.
- **ADR 0017 finished.** One host for the application, tenant resolved from the session
  rather than from the hostname. 63 test files, the shared test helpers, and the
  production code the migration turned out to reach: `ResolveTenant`, the Identity
  routes, impersonation, password reset, invitations, `PublicTenantResolver`,
  `PriceListAccess`. Three migrations open **platform-scoped read** policies on
  `invitations`, `repair_tickets` and `storefront_settings` — the public surfaces those
  serve (accepting an invitation, tracking a repair by QR, a public shop page) no longer
  have a hostname to resolve a tenant from. `tenancy:check` and the cross-tenant
  isolation suite are what prove they did not widen anything.
- **Dashboard query work.** The non-sargable date expression, the `select *`, and the
  N+1 that issued 20,600 calls — the three causes traced in the 2026-08-20 load test,
  where `/dashboard` came back at 2.03s p95 against a 1000ms threshold. PATCH-shaped work
  inside a MINOR release: it makes an existing screen do the same thing faster.
- **The CSP nonce is asserted where it can exist.** `/login` is Blade since ADR 0016 and
  carries no inline script, so asking its markup for a nonce could only fail. Split: the
  policy's shape is asserted on `/login`, the stamping on `/design` — and the new
  assertion compares the markup's nonce to the header's, because a nonce that does not
  match is worse than one that is missing. The browser drops the script either way, and
  each half reads as correct alone.

### The release process itself

`VERSION`, this file, `docs/VERSIONING.md`, `docs/RELEASE_PROCESS.md`, `bin/release` and
`bin/smoke`. Modelled on the sibling invoice-system project, which has run this way for
241 releases.

Three of its rules are load-bearing and were chosen against the obvious alternative:

- **A release is not a commit.** `VERSION` and the changelog entry are written in the same
  pull request as the change they describe, so `bin/release` only ever *publishes* what
  `main` already holds. Bumping the version at release time would mean a direct push to
  `main` — and therefore `ALLOW_MAIN_PUSH=1` as routine, which is not a guard.
- **`bin/release` refuses to run while a green pull request sits unmerged.** That is the
  exact state that produced this release's opening paragraph, so it is a failure with a
  named override rather than a warning. A warning is what gets ignored.
- **A deploy is not a release until `bin/smoke` says so.** It runs from outside the box,
  over the real TLS, and asserts the version the box reports back — plus the two links a
  shopkeeper actually clicks, followed rather than matched. It would have caught the 404
  above on the day it shipped.

`/health` now reports `version` (public) and, behind `X-Health-Secret`, the exact image
tag serving the request. That answer — *which build is live* — is what turns "the site is
still broken" from a guess into a fact.

### The repository is public

Owner's decision, 2026-08-22, taken with the consequences on the table: this publishes the
whole history — 178 commits, 72 revisions of the roadmap, the business plan, the security
document and the specs of all eighteen modules. A secret audit ran first and came back
clean: `.env` and `.env.production` have never been committed, no key, token or host
appears in any commit, and the only credential-shaped string in the tree is a `<token>`
placeholder in the deploy runbook.

It has one consequence worth more than the reason it was done for: **branch protection and
rulesets are Pro-gated for private repositories and free for public ones.** `main` now
requires a pull request and all five checks at the platform level. `CLAUDE.md` said in
plain words that nothing here was mechanically enforced — that paragraph was accurate and
is now rewritten, with the condition under which it must be written back.

### And one thing this release learned by breaking

The first deploy cut through this path lost its SSH connection partway through
`bin/deploy`. Because the command was a child of the session, it died with it: traffic had
already been cut over to the new container, and steps 8 and 9 never ran — **horizon and the
scheduler were left down, on the old image**, with nothing red anywhere to say so. Compose's
mid-recreate rename had also left a container holding the name `mobishop-horizon-1`, so the
obvious retry failed with a name conflict on top of it. The site served perfectly; it just
stopped sending SMS.

`bin/deploy`'s ordering guarantee — every irreversible step after every reversible one — is
about the order of the steps. It buys nothing against the script simply stopping between
two of them, and a dropped carrier is the one thing that can do that from outside. So
`bin/release` now runs both remote steps **detached**, with `nohup`, writing an exit code to
a sentinel file it polls for. A dropped link costs a reconnect instead of a half-deployed
box. `bin/deploy`'s header says so too, because that is where somebody will be reading when
it matters.
