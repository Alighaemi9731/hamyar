# MobiShop — Deployment & operations

Local development first, then the production runbook.

**What is proven and what is not.** Everything in §2–§5 is built, parameterised and
committed; nothing in it names a domain, a host or a secret. What has **not** happened is
any of it running on a server, because there is not one yet — so each section says
plainly which of its claims are verified and which are written-and-untried. Lines marked
🔲 need the box; the same list is collected in §7.

---

## 1. Local development

### Prerequisites

- Docker with Compose (Docker Desktop, colima, OrbStack — any of them)
- Node.js LTS + npm (Vite runs on the host; PHP runs in the container)
- Git

PHP and Composer on the host are **not** required.

### First run

```bash
cp .env.example .env
make build          # build the PHP 8.4 image
make up             # start app, nginx, postgres, redis, minio, mailpit
make install        # composer install + npm install
make artisan CMD="key:generate"
make fresh          # migrate + seed
npm run dev         # Vite, on the host
```

Then open <http://app.localhost>.

| Service | URL |
|---|---|
| App (central) | <http://app.localhost> |
| Tenant | `http://<shop>.app.localhost` |
| Design gallery (dev only) | <http://app.localhost/design> |
| Mailpit | <http://localhost:8025> |
| MinIO console | <http://localhost:9001> |

### Local hostnames

macOS and modern browsers resolve `*.localhost` to `127.0.0.1` automatically (RFC
6761), so `demo.app.localhost` works with no configuration. Verify with:

```bash
ping -c1 demo.app.localhost
```

If your platform does not (some Linux distributions, and command-line tools that
bypass the browser resolver), pick one:

**Option A — `/etc/hosts`.** Simple, but every new tenant subdomain needs a line:

```
127.0.0.1  app.localhost demo.app.localhost acme.app.localhost
```

**Option B — dnsmasq.** Wildcard, so new tenants just work:

```bash
brew install dnsmasq
echo 'address=/.localhost/127.0.0.1' >> $(brew --prefix)/etc/dnsmasq.conf
sudo mkdir -p /etc/resolver
echo 'nameserver 127.0.0.1' | sudo tee /etc/resolver/localhost
sudo brew services start dnsmasq
```

### Port 80 needs privileges?

Some Docker runtimes cannot bind ports below 1024 without root. Set
`APP_HTTP_PORT=8080` in `.env`, `make up` again, and browse
<http://app.localhost:8080>.

### Everyday commands

```bash
make sh                          # shell in the app container
make test                        # the full quality gate
make test-isolation              # cross-tenant suite only
make artisan CMD="route:list"
make composer CMD="require foo/bar"
make psql                        # superuser — NOTE: bypasses RLS, shows all tenants
make psql-app                    # application role — RLS enforced
make logs
make destroy                     # stop and DELETE all volumes
```

### Database roles

Two roles, and the difference matters
([ADR 0002](adr/0002-single-db-tenancy-rls.md)):

| Role | Superuser | Used by |
|---|---|---|
| `mobishop_app` | **No** | Everything: requests, workers, migrations, seeders, tests |
| `mobishop` | Yes | `make psql`, backups, manual surgery only |

Querying as `mobishop` shows **every tenant's data** — RLS does not apply to
superusers. That is deliberate (backups and incident response need it) and is exactly
why the application never connects as it.

---

## 2. Production topology

```
            ┌──────────── nginx (TLS, HTTP/2) ────────────┐
 Internet ─▶│  <apex>   *.<apex>          :80 → :443      │
            └───────┬─────────────────────────────────────┘
                    │  upstream mobishop_app  ← one file, rewritten at cutover
        ┌───────────┴───────────┐
        ▼                       ▼
   app_blue (php-fpm)     app_green (php-fpm)      ← only one is live
        │                       │
        ├──▶ PostgreSQL 16 ──▶ WAL archive ──▶ offsite (nightly)
        ├──▶ Redis 7 (cache, sessions' sibling, queue)
        └──▶ S3-compatible object store (per-tenant prefixes)

   horizon (queue supervisors) · scheduler (schedule:work) · certbot (renewal)
```

One well-specified VPS. Scale in this order, only when measurement says so: separate the
database host → add app containers behind a load balancer → add a read replica →
partition `sms_messages` and `stock_movements` by month.

**Kubernetes is an explicit anti-goal.** Do not introduce it.

### The files, and what each one owns

| file | owns |
|---|---|
| `compose.prod.yaml` | the services, and every value that differs per machine as an env var with no working default |
| `.env.production.example` | every one of those values, each with a `CHANGE-ME` and a reason |
| `docker/app/Dockerfile.prod` | the image: source, vendor, built assets, at one commit |
| `docker/app/php.prod.ini` | opcache, error display, memory — the production overrides only |
| `docker/nginx/templates/default.conf.template` | the vhost, TLS, static caching |
| `docker/nginx/upstream/app.conf` | **which container is live** — rewritten by `bin/deploy`, nothing else. Tracked *and* runtime state; see the warning below |
| `docker/postgres/postgresql.prod.conf` | tuning, WAL archiving, `pg_stat_statements` |
| `bin/deploy` | the release |
| `bin/backup-nightly` | the offsite copy |
| `bin/restore-drill` | proving the offsite copy is real |

### The domain is written down exactly once

`APP_DOMAIN` in `.env.production`. From there:

- **nginx** substitutes it into `server_name` and the certificate paths at container
  start, via the image's own `envsubst` pass over `/etc/nginx/templates/`.
- **PHP** reads it through `config('app.domain')` — route domain constraints, Horizon's
  dashboard host, the mail from-address.
- **Tenants** resolve from `domains.hostname` rows, not from the apex, so onboarding a
  shop touches no configuration at all.

Changing the apex is therefore this one line plus a data migration over `domains` —
never a code change, never an image rebuild (golden rule 1b). `bin/check-apex-domain`
runs in CI and fails the build on a hostname literal.

> **The envsubst filter is load-bearing.** `compose.prod.yaml` sets
> `NGINX_ENVSUBST_FILTER='^APP_'`. Without it envsubst substitutes **every** `$name` in
> the template — including nginx's own `$uri`, `$host`, `$request_uri` and
> `$fastcgi_script_name` — with empty strings, because they are not environment
> variables. The result parses, starts, and serves nothing correctly.

### TLS

One wildcard covers every tenant: `<apex>` and `*.<apex>`.

Wildcard issuance requires a **DNS-01** challenge, so certbot needs API credentials for
the DNS provider rather than a webroot — the plugin depends on the registrar. Port 80
still serves `/.well-known/acme-challenge/` for anything that needs HTTP-01 later.

Two things carry that, and both were wrong when this was written down and unexercised.

**`CERTBOT_IMAGE`, not `certbot/certbot`.** The plain image ships **no DNS plugins**, so
a wildcard renewal in it exits with `Could not select or initialize the requested
authenticator dns-cloudflare` — twice a day, quietly, into a container log nobody reads.
The plugin is a separate image per provider (`certbot/dns-cloudflare`,
`certbot/dns-route53`, …), so it is an env var beside `APP_DOMAIN` rather than a literal
in `compose.prod.yaml`. Credentials go in `certbot/secrets/`, mounted `:ro` into certbot
**and nowhere else** — `certbot/conf/` is also mounted into nginx, and a token that can
edit the zone's DNS has no business being readable by the internet-facing container.

**nginx reloads itself every six hours, and that is the renewal mechanism.** nginx caches
the certificate it read at start-up. certbot's `--deploy-hook` touched
`/etc/letsencrypt/.renewed` and **nothing anywhere read that file**, so the real sequence
was: certbot renews, reports success, the files on disk are valid, every check that
inspects the *file* is green — and sixty days later the certificate nginx is still
holding expires. Only a probe of the served TLS handshake would have seen it coming. The
reload is unconditional rather than flag-driven because `/etc/letsencrypt` is read-only
in nginx: it can see the flag and cannot clear it, so "reload if the flag exists" reloads
forever after the first renewal. A graceful reload drops no connection and has no state
to get wrong.

🔲 **Renewal must still be watched.** An expired wildcard takes *every* tenant offline
simultaneously, and the two fixes above remove two silent failures rather than the need
for an alarm. The renewal loop runs twice a day (Let's Encrypt's own recommendation); the
alert that it has *stopped* — and the check on the **served** certificate, not the file —
are part of the uptime setup in §5.

#### First boot is a chicken-and-egg, and it will bite

nginx **will not start** without the certificate files, and certbot cannot obtain them
until it can run — so the very first `up` on a fresh box fails with
`cannot load certificate … no such file or directory`, which reads like a broken config
rather than a missing prerequisite. Issue the certificate before nginx is ever started:

```bash
mkdir -p certbot/conf certbot/www release/public

# Bring up only what does not depend on TLS.
docker compose -f compose.prod.yaml --env-file .env.production up -d postgres redis

# Wildcard ⇒ DNS-01 ⇒ a provider plugin and its API credentials. The plugin depends on
# the registrar (certbot-dns-cloudflare, -route53, …); with an Iranian registrar that
# has no plugin, use `--manual --preferred-challenges dns` and add the TXT record by
# hand — it works, it just cannot auto-renew, which makes the §5 expiry alert essential
# rather than a nicety.
# The credentials file the plugin reads. 0600, and never in the repository.
mkdir -p certbot/secrets
printf 'dns_cloudflare_api_token = %s\n' "<token>" > certbot/secrets/cloudflare.ini
chmod 600 certbot/secrets/cloudflare.ini

docker compose -f compose.prod.yaml --env-file .env.production run --rm --entrypoint "" certbot \
  certbot certonly --agree-tos --no-eff-email -m <ops-email> \
  --dns-cloudflare --dns-cloudflare-credentials /secrets/cloudflare.ini \
  --dns-cloudflare-propagation-seconds 30 \
  -d "$APP_DOMAIN" -d "*.$APP_DOMAIN"

# Only now.
docker compose -f compose.prod.yaml --env-file .env.production up -d nginx
bin/deploy <image>
```

`release/public` is created here for the same reason: `bin/deploy` writes into it, and
Docker would otherwise create the bind-mount target as a root-owned empty directory.

**Mind the umask on these.** `release/public` and `certbot/www` are read by nginx's
*worker* processes, which are unprivileged; `certbot/secrets` is read by certbot alone and
should stay `0700`. Creating the first three under a restrictive umask leaves them `0700`
and nginx cannot stat them — and the symptom points nowhere near the cause, because
php-fpm serves from its own copy of `public/` inside the image. Dynamic routes keep
answering while every static asset 403s, so the site renders unstyled and scriptless and
reads as a corrupted build. `bin/deploy` now asserts the mode on `release/` itself; the
certbot directories are created once, here.

---

## 3. Releasing

```bash
bin/deploy ghcr.io/<owner>/mobishop-app:<sha>
bin/deploy ghcr.io/<owner>/mobishop-app:<previous-sha> --rollback
```

Blue/green, on one box. The order is the design: **every irreversible step happens after
every reversible one**, so a failure before the cutover leaves the previous release
serving and exits non-zero.

1. Pull the image.
2. Start the **idle** container beside the live one.
3. `migrate --force` *(skipped on `--rollback`)*.
4. `config:cache`, `route:cache`, `view:cache` — **here, on the box, not at image build
   time**. Caching config in the Dockerfile bakes the build machine's environment into
   the artefact, starting with `APP_DOMAIN`.
5. Copy `public/` out of the new container into `release/public`, **additively**. Vite
   fingerprints filenames, so a page already served by the old container must still be
   able to fetch what it references during the overlap.
6. `artisan health:check` against the new container — not an HTTP request, because
   php-fpm speaks FastCGI and going through nginx would mean cutting over first.
7. **Cutover**: rewrite `docker/nginx/upstream/app.conf`, `nginx -t`, `nginx -s reload`.
   Reload rather than restart, so workers finish in-flight requests on the old config.
8. Recreate `horizon` and `scheduler` on the new image — *after* the cutover, never
   before.
9. Stop (do not remove) the old container. It still holds the previous image, which is
   what makes rollback a cutover rather than a rebuild.

🔲 Never run end to end. It is the one script whose failure mode is an outage; the first
run belongs on staging, watched.

### Do not sync the repository over the deploy directory

`docker/nginx/upstream/app.conf` is the one file that is **both** tracked and runtime
state. It is committed with the blue slot so a fresh box has something valid to read, and
`bin/deploy` rewrites it at every cutover. An `rsync`, a `git pull`, a fresh checkout or a
config-management run therefore resets it to `app_blue` while `app_green` may be the slot
actually serving.

Before this was guarded, that swapped `LIVE` and `IDLE` and sent step 2's
`--force-recreate` at the container **currently taking traffic** — ahead of the health
check and ahead of the cutover, so the ordering guarantee above bought nothing. It is not
hypothetical: it happened on the first staging box, between the first deploy and the
second.

`bin/deploy` now derives the live slot from which container is actually running and treats
the file as a first-boot fallback, warning when the two disagree. Sync the repository
anyway if you must — but read the warning when it appears, because it means something in
the deploy path is resetting state it does not own.

### The migration rule the script cannot enforce

For a few seconds **both releases serve traffic against one already-migrated database**.
So a migration must be backward-compatible for one release:

> add a column → deploy → start writing it → deploy → *then* stop reading the old one.

Dropping a column in the same release that stops writing it takes the site down for the
length of the overlap — and the 500s come from the **old** container, which reads as a
reason to roll back rather than as the new release's fault.

---

## 4. Backups

Single shared database, so taking a backup is simple and **restore granularity is the
hard part**: a per-tenant restore means extracting rows by `tenant_id`, not restoring a
file.

```
17 2 * * *  cd /srv/mobishop && ./bin/backup-nightly >> /var/log/mobishop-backup.log 2>&1
```

**Host prerequisite: `postgresql-client`, matching the server's major version.** The dump
is taken *inside* the container, but `bin/backup-nightly` verifies the archive with
`pg_restore --list` on the **host** — and that verification is the step that catches an
RLS-filtered empty backup, which is the worst outcome in this document. Without the client
the job dies there. `apt-get install postgresql-client-16` on Ubuntu 24.04.

Two levels, for two different disasters:

| | recovers | survives |
|---|---|---|
| **WAL archive** (local volume, continuous) | a dropped table or a bad migration, to the second | nothing — it is on the same machine |
| **Nightly `pg_dump -Fc`** (offsite S3) | the whole platform to last night | losing the machine |

`archive_timeout = 300` forces a segment every five minutes even when idle, so the
recovery window is bounded by *time* rather than by write volume — a quiet Friday night
must not mean "recoverable to Friday lunchtime".

> **The archive directory must be owned by `postgres`, and this is not a detail.** It is a
> named volume, so Docker creates its mount point root-owned while the server runs as
> `postgres`. On the first staging box every `archive_command` had failed with
> `Permission denied` since the volume was created — `archived_count = 0`,
> `failed_count = 3595` — and **nothing else looked wrong**. Postgres retries an archive
> failure forever and serves every query normally, so two things were quietly true:
> there was no point-in-time recovery at all, and `pg_wal` had grown to **14.5GB across
> 927 segments**, because a segment that has not been archived cannot be recycled. Left
> alone that ends with a full disk and a database that stops accepting writes — an outage
> whose cause is a counter nobody reads. `compose.prod.yaml` now chowns the directory in a
> wrapped entrypoint on every start. Check it with:
>
> ```bash
> docker compose -f compose.prod.yaml --env-file .env.production exec -T postgres \
>   psql -U "$DB_ROOT_USERNAME" -d "$DB_DATABASE" \
>   -c "select archived_count, failed_count, last_archived_time from pg_stat_archiver;"
> ```
>
> A `failed_count` that climbs is the alert; §5 should carry it.

### The failure this is built to catch

The application connects as `mobishop_app`, a **NOSUPERUSER** role, so that RLS is a real
boundary (ADR 0002). A dump taken as that role is filtered by those same policies: with
no tenant pinned RLS fails closed, and the backup contains **zero rows from every tenant
table** — while exiting 0 and reporting a plausible size.

So `bin/backup-nightly` dumps as the superuser, and then reads its own archive back and
**refuses one with no `stock_movements` data in it**. A green backup job producing a file
that restores an empty platform is the worst outcome available in this document.

### Restore drill

```bash
bin/restore-drill /var/backups/mobishop/mobishop-<stamp>.dump
```

Restores into a scratch database (never the live one — it refuses), then asserts four
things and writes `docs/restore-drills/<stamp>.md`:

1. Tenant rows came back at all.
2. `stock_movements` came back — the ledger is the product's source of truth for stock
   (golden rule 3), and a restore without it is not a restore.
3. **RLS is enabled on every `tenant_id` table.** Policies travel with the schema, but a
   restore run as a superuser can leave a table with row security off — and a restored
   platform without RLS has no tenancy boundary, which nothing about it reveals until two
   shops see each other's customers.
4. **The policies actually deny**: the app role, connecting with no tenant pinned, reads
   zero rows. Not "a policy exists" — that it works.

🔲 **Never run.** This is the largest unhardened thing in the project. It reports the
**RTO observed**, and the RTO quoted to anybody must also include provisioning a host and
fetching the dump from the object store, neither of which the drill measures.

### Per-tenant recovery

🔲 Written, untested. Restore the dump to a scratch database, `COPY` the tenant's rows
out per table in dependency order, and re-insert into the live database inside
`TenantContext::runFor()` so `tenant_id` is filled and RLS accepts the writes. The custom
dump format exists for this: `pg_restore -t <table>` pulls single tables out of a 5.5GB
archive without a text-processing exercise.

---

## 5. Monitoring

| Signal | Tool | Alert when |
|---|---|---|
| Errors | Sentry | New issue, or a spike |
| Uptime | External probe on `/health` | Two consecutive failures |
| Queue | Horizon | Wait > 60s on `sms` (configured in `config/horizon.php`) |
| Database | `pg_stat_statements` | p95 query > 300ms |
| Disk | Host | > 80% |
| WAL archiving | `pg_stat_archiver.failed_count` | It increases at all — see §4; the database stays healthy while recovery quietly does not exist |
| `pg_stat_statements` | Extension present | Missing means §5's "the slowest statements are already being recorded" is false |
| TLS | Certificate expiry | < 21 days remaining |
| SMS credit | Application | Tenant below threshold |

### The two health endpoints, and why both

| | costs | answers |
|---|---|---|
| `/up` | booting the framework, nothing else | is this container alive? |
| `/health` | a Postgres round trip, a Redis round trip, a read of the migration repository | are its dependencies there? |

`/health` **grades** what it finds. Database, cache and pending migrations are critical
and return **503**; a queue backlog is reported at **200**. That distinction is the whole
point: grading a backlog critical would pull a healthy web tier out of rotation because a
third-party SMS gateway is slow, turning a delayed text message into a shop that cannot
take payment.

The body is not public. An anonymous caller gets `{"status":"ok"}` and the status code —
everything an uptime probe needs. Details need `X-Health-Secret`, and with no
`HEALTH_SECRET` configured **nobody** gets them: the detailed body names internal
hostnames and driver-level failures, and it reads best exactly when something is already
broken and somebody is already looking.

```bash
make health                                    # locally
docker compose exec app_blue php artisan health:check
curl -s https://<apex>/health                  # {"status":"ok"}
curl -s -H "X-Health-Secret: $HEALTH_SECRET" https://<apex>/health | jq
```

### What Sentry is and is not allowed to send

A crash reporter's job is to take production data somewhere else, so the decision is
written down in `config/sentry.php` rather than left to a vendor default. Three settings
are **hardcoded, not env-driven** — an environment variable is an invitation to flip one
on mid-incident:

- `send_default_pii = false` — no IP, no cookie jar, no `Authorization`, no operator's
  email.
- `breadcrumbs.sql_bindings = false` **and** `tracing.sql_bindings = false` — two
  switches for the same values. A binding array is the single richest leak in the
  product: `select * from parties where national_id = ?` carries the national id, and a
  customer's phone, a handset's IMEI and a repair passcode all reach the database the
  same way, after every model-level protection has already been satisfied.
- Request bodies are scrubbed by `App\Support\SensitiveInput` — the same list
  `dontFlash` uses, so a key added for one door closes the other.

**`SENTRY_RELEASE` is not in `.env.production`, and must not be put there.**
`Dockerfile.prod` bakes it from `APP_RELEASE` at build time, because the image is the
only thing that knows which commit it contains. Compose's `env_file` **overrides** an
image's `ENV`, so a bare `SENTRY_RELEASE=` line replaces the baked value with an empty
string and every event arrives with no release attached — the one tag that answers
"which deploy broke this". It fails silently and reads as Sentry not reporting the field
rather than as a misconfiguration. Verified on the box: the image alone reports
`7e09522fd`; the same image with `--env-file .env.production` reported nothing.

Events carry `tenant_id` and the shop's slug as **tags**. That answers the question an
incident actually asks — *which shop?* — without carrying anyone's data to answer it.

### Horizon

The dashboard lives on the central domain and is gated on the `platform` guard. **This is
a tenancy boundary, not an admin convenience.** Horizon renders job payloads:
`SendSmsJob` carries a customer's phone number and the text of the message, from every
shop, on one screen — and none of it is a database row, so RLS cannot reach it. A shop
owner who reached this page would read the other forty-nine shops' customers.

One supervisor per queue (`default`, `sms`, `moadian`) rather than one pool over three: a
shared pool lets Moadian, a government endpoint with 180-second timeouts, starve fifty
shops' repair-ready texts.

Iranian payment and SMS gateways have outages. Every external call retries with backoff
and, after repeated failure, lands in an error inbox the shop owner can see and resend
from — never silently dropped.

---

## 6. Go-live checklist

**Configuration**
- [ ] `.env.production` written from `.env.production.example`, every `CHANGE-ME` replaced
- [ ] `APP_DEBUG=false`, `APP_ENV=production`
- [ ] `APP_KEY` set and **backed up separately from the database** — it decrypts
      `device_passcode` and the Moadian private key; a backup holding both is one theft
      rather than two
- [ ] `APP_DOMAIN` is the registered apex; `domains.hostname` rows migrated to it
- [ ] `HEALTH_SECRET` generated (`openssl rand -hex 32`)
- [ ] `SESSION_DOMAIN` **empty** — a leading-dot cookie is shared across every tenant
      subdomain

**Verified by CI already, re-checked against the production schema**
- [ ] `php artisan tenancy:check` green
- [ ] `composer test:isolation` green against production's schema
- [ ] `php bin/check-apex-domain` green
- [ ] Security headers + CSP present on a 404 as well as a real page
- [ ] Rate limits on login, OTP, public tracking and price-list pages

**Needs the box**
- [ ] Wildcard TLS installed, **auto-renewal verified by watching one succeed**
- [ ] `bin/deploy` run end to end, with a deliberate rollback afterwards
- [ ] Backups running; **restore drill performed and its log committed**
- [ ] Sentry receiving events; uptime probe live on `/health`
- [ ] Horizon supervisors running and auto-restarting
- [ ] Load test report committed to `docs/`

**Product**
- [ ] Terms and privacy pages published
- [ ] Final pricing validated against Iranian competitors (roadmap 11.4)

---

## 7. What still needs a server

Collected here because none of it is design work — the point of §2–§5 is that this list
is short and mechanical.

| # | what | why not here |
|---|---|---|
| 1 | **Restore drill** — `bin/restore-drill` | Reports an RTO measured against real hardware; a laptop number is not the number. |
| 2 | **Load test** — `tests/Load/endpoints.js` | The load generator competes with the app for cores on one machine; the result measures Docker. |
| 3 | **Wildcard TLS** | DNS-01 needs the registered domain and the provider's API credentials. |
| 4 | **First `bin/deploy`** | Written and unexercised, and its failure mode is an outage. |
| 5 | **Sentry DSN, `HEALTH_SECRET`, uptime probe** | Configuration, not code. |

Everything else is built and parameterised. Pointing at a real server is writing
`.env.production` and running:

```bash
docker compose -f compose.prod.yaml --env-file .env.production up -d postgres redis
# …obtain the wildcard certificate (§2 "First boot"), then:
docker compose -f compose.prod.yaml --env-file .env.production up -d nginx
bin/deploy <image>
```
