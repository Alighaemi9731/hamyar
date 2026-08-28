# Release process

A release is one command. Everything below explains what that command refuses to do, and
why each refusal is there.

```bash
bin/release --dry-run     # print every step, change nothing
bin/release               # tag + publish the GitHub release
bin/release --deploy      # …then sync, build on the box, cut over, and prove it
```

Production coordinates live in `.claude/OPS.local.md` and `.deploy.local`, both
gitignored. **The repository is public — that is why they are.** Never copy a host, an IP
or a secret into a commit, a release note, a test fixture or a document.

---

## The rule this whole document exists for

> **A change that is not on the box is not done.**

On 2026-08-21 twenty-one finished commits sat on a branch, the box served the release from
five commits earlier, a live 404 had its fix among them, and every CI check was green.
Nothing measured the distance between "the code is correct" and "the correct code is
running". This process is that measurement.

Its corollary is the merge law: **green means merge, and merge means deploy.** A pull
request whose checks have all passed is not a decision waiting to be made. `bin/release`
enforces it — it stops if a green non-draft PR is still open.

---

## 1. Do the work, and version it in the same pull request

A release is **not** a commit. `VERSION` and the `CHANGELOG.md` entry are part of the pull
request that changes the behaviour they describe.

1. Branch. Implement. Add the Pest tests, including the cross-tenant isolation test for
   any tenant-scoped endpoint (golden rule 8).
2. Choose the next version per `docs/VERSIONING.md` and write it into `VERSION`.
3. Write the `CHANGELOG.md` entry **now**, not at release time. Written afterwards it gets
   written from the diff, and the diff is the one thing that cannot say why.
4. Push. Read the checks. Do not run the suite on the laptop — CI runs Pint, the RTL gate,
   Larastan level 8, `tenancy:check`, Pest on real PostgreSQL, the isolation suite and a
   browser smoke, on GitHub's machines, free.

Why the version bump is not its own commit at release time: it would be a direct push to
`main`, so `ALLOW_MAIN_PUSH=1` would become routine — and a guard whose override is part
of the routine is not a guard. A tag is not `refs/heads/main`, so nothing has to be waved
through.

## 2. Merge it the moment it is green

```bash
gh pr merge <number> --squash --delete-branch
git switch main && git pull
```

This is not a stopping point and it is not a question. If the checks are red or the DoD is
unwalked, that is work to do (CLAUDE.md, "Workflow every session" §6).

## 3. Release

```bash
bin/release --deploy
```

In order, and every one of these is a refusal that has a reason:

| Step | Refuses when |
|---|---|
| Preflight | not on `main`; worktree dirty; `main` ≠ `origin/main`; tag already exists; `VERSION` is not semver |
| Changelog | `CHANGELOG.md` has no `## <version>` entry |
| Merge law | a green, non-draft pull request is still open (override: `ALLOW_UNMERGED_GREEN=1`) |
| CI | any check on **this exact commit** is pending or not green |
| Tag | — annotated `v<version>`, pushed |
| Publish | GitHub release with the changelog entry as its notes, plus a `git archive` tarball and its SHA-256 |
| Deploy | `.deploy.local` missing; the box unreachable without a password |
| Sync | rsync of the tree, excluding `.env.production`, `certbot/`, and `docker/nginx/upstream/app.conf` |
| Build | `docker build` **on the box**, tagged `hamyar-app:<9-char-sha>`, `APP_RELEASE` baked in |
| Cut over | `bin/deploy` — blue/green, health-checked before the cutover (`docs/deploy.md` §3) |
| Prove | `bin/smoke` against the live site fails ⇒ the release is reported as **not verified** |

`bin/release --deploy` is re-runnable. If it stops anywhere after the tag, run it again —
see §5. And read what it says about the cutover: a `bin/deploy` failure at step 8 or 9
happens *after* traffic has moved, so the box's own "nothing was cut over" is false there.
`bin/release` prints the two commands that establish which actually happened.

The image is built on the box on purpose. The laptop speaks Docker only through a Linux
VM, so a build there is minutes of pinned cores for an artefact the box produces natively —
and the owner's standing instruction is that nothing heavy runs on the laptop.

## 4. What "proved" means

`bin/smoke <apex>` runs from outside, over the real certificate:

- `/health` reports ok, and — with `X-Health-Secret` — reports **the version and the exact
  image tag** that answered. This is the check that turns "is my change live?" into a fact.
- The apex serves the landing; `app.<apex>` serves `/login` and `/register`; the apex does
  **not** serve `/login` (ADR 0017 held).
- **The links a person clicks are followed, not matched.** «ثبت نام» on the login page and
  the landing's calls to action are fetched and must return 200, and sign-up must stay on
  the sign-in origin. Asserting the anchor exists passes on the broken page; only
  following it does not.
- TLS has more than 21 days left. The wildcard renews through a DNS-01 plugin and the way
  that fails is silently, so a deploy doubles as a renewal check.

No hostname appears in the script. `bin/release` reads `APP_DOMAIN` and `HEALTH_SECRET`
from the box's own `.env.production` and passes them in — golden rule 1b, and `HEALTH_SECRET`
is never printed, logged or written.

## 5. Published but not deployed — resume, do not re-cut

Everything after the tag can fail with the tag on origin and the GitHub release public:
the rsync, a cold `docker build` running past the poll budget, the cutover, a dropped
link. When that happens the box is still serving the previous release and the tag is
still correct.

**Re-run the same command.** `bin/release --deploy` resumes when the tag already points at
`HEAD`: it re-pushes the tag if needed, refreshes the release assets instead of failing on
an existing release, and carries on to the sync. Preflight runs again in full, on purpose —
it is cheap, it is still true about the commit being shipped, and skipping it would make
resuming a way around the gates.

**Do not delete the tag and do not burn a version number.** A tag that points somewhere
other than `HEAD` is the one case that still refuses, because that means somebody forgot
to bump `VERSION` — which is what the guard was for.

This is different from a rollback: nothing was cut over, so there is nothing to cut back.

## 6. Rollback

The previous container is **stopped, not removed**, so it still holds the previous image
and a rollback is a cutover rather than a rebuild:

```bash
ssh <box> "cd /srv/hamyar && bin/deploy hamyar-app:<previous-sha> --rollback"
```

`--rollback` skips migrations. **That is the constraint that shapes every migration
here:** blue/green means both releases briefly serve one already-migrated database, so a
migration must be backward-compatible for one release. Additive now, destructive next
release — and the destructive one is a MAJOR (`docs/VERSIONING.md`).

If the release you are leaving added a migration, rolling the code back leaves the schema
ahead of it. Confirm the older code tolerates the newer schema before doing it; if it does
not, roll **forward** with a fix instead.

## 7. Afterwards

Append one line to `docs/PROGRESS.md` — date, what shipped, the version — and tick the
roadmap box. A box ticked before `bin/smoke` passed is claiming something no test said.
