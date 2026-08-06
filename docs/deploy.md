# MobiShop — Deployment & operations

Local development first, then the production runbook. Sections marked *(Phase 11)* are
placeholders the hardening phase fills in and verifies — they are not yet proven.

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

## 2. Production topology *(Phase 11)*

```
            ┌──────────── Nginx (TLS, HTTP/2) ────────────┐
 Internet ─▶│  app.mobishop.ir   *.mobishop.ir            │
            └───────┬─────────────────────────────────────┘
                    ▼
            PHP-FPM 8.4 (N containers)  ──▶  PostgreSQL 16  (primary + WAL archive)
            Horizon workers             ──▶  Redis 7        (cache/session/queue)
                                        ──▶  S3-compatible  (per-tenant prefixes)
```

Start on a single well-specified VPS. Scale in this order, only when measurement says
so: separate the database host → add app containers behind the load balancer → add a
read replica → partition `sms_messages` and `stock_movements` by month.

**Kubernetes is an explicit anti-goal.** Do not introduce it.

### TLS

One wildcard certificate covers every tenant:

```
mobishop.ir, *.mobishop.ir
```

Wildcard issuance needs a DNS-01 challenge, so the ACME client requires API
credentials for the DNS provider. Renewal must be automated and monitored — an
expired wildcard takes **every** tenant offline at once.

### Deployment

Zero-downtime, two-container swap:

1. Build and tag the image in CI.
2. Start the new container alongside the old one.
3. `php artisan migrate --force` (migrations must be backward-compatible for one
   release — never drop a column in the same deploy that stops writing it).
4. Health-check the new container.
5. Switch nginx upstream, drain, stop the old container.
6. `php artisan horizon:terminate` so workers restart on the new code.

---

## 3. Backups *(Phase 11)*

Single shared database, so backup is simple and **restore granularity is the hard
part**: a per-tenant restore means extracting rows by `tenant_id`, not restoring a
file.

- Nightly `pg_dump` → offsite S3, 30-day retention.
- Continuous WAL archiving for point-in-time recovery.
- File storage replicated to a second S3 bucket.
- **Monthly restore drill**, logged in `docs/restore-drills/`. A backup that has never
  been restored is not a backup — Phase 11 performs one and commits the log.

Per-tenant recovery procedure (write and test in Phase 11): restore the dump to a
scratch database, `COPY` the tenant's rows out per table in dependency order, and
re-insert with RLS disabled on the target.

---

## 4. Monitoring *(Phase 11)*

| Signal | Tool | Alert when |
|---|---|---|
| Errors | Sentry | New issue, or spike |
| Uptime | External probe on `/up` | Two consecutive failures |
| Queue | Horizon | Wait time > 60s on `sms` |
| Database | `pg_stat_statements` | p95 query > 300ms |
| Disk | Host | > 80% |
| SMS credit | Application | Tenant below threshold |

Iranian payment and SMS gateways have outages. Every external call retries with
backoff and, after repeated failure, lands in an error inbox the shop owner can see
and resend from — never silently dropped.

---

## 5. Go-live checklist *(Phase 11)*

- [ ] `APP_DEBUG=false`, `APP_ENV=production`
- [ ] `APP_KEY` set and backed up separately from the database
- [ ] Wildcard TLS installed, auto-renewal verified
- [ ] Security headers + CSP
- [ ] Rate limits on login, OTP, public tracking and price-list pages
- [ ] RLS verified on **every** tenant table (`php artisan tenancy:check`)
- [ ] Isolation suite green against the production schema
- [ ] Backups running; restore drill performed and logged
- [ ] Sentry receiving events; uptime probe live
- [ ] Horizon supervisors configured and auto-restarting
- [ ] Load test report committed
- [ ] Terms and privacy pages published
