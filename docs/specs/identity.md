# Identity

**Phase 1** · Module `app/Modules/Identity` · ★ security-critical

## Purpose

Who may enter a shop's panel and what they may do there. Also owns onboarding — the
first ninety seconds of the product, where a shop owner turns a signup into a working
tenant.

## Data

- `users` — tenant-scoped, with RLS. `name`, `email`, `mobile`, `password`
  (Argon2id), `is_active`, `two_factor_secret`, `two_factor_recovery_codes`,
  `last_login_at`.
- `roles`, `permissions`, and spatie's pivots with **`teams = tenant_id`**.
- `user_branch` — branch restrictions (branches arrive in Phase 3; the pivot is ready
  from Phase 1).
- `invitations` — `email`/`mobile`, `role`, `token`, `expires_at`, `accepted_at`.
- `activity_log` — spatie, tenant-scoped.

## Behaviour

### Onboarding wizard

Shop name → subdomain (live availability check, reserved-word list) → owner user →
demo-data toggle. Completing it creates the tenant, the domain, the owner with the
`Owner` role, and — if asked — a seeded demo dataset the owner can safely delete.

Subdomain rules: lowercase, alphanumeric plus hyphen, 3–30 characters, not in the
reserved list (`www`, `api`, `admin`, `app`, `mail`, `static`, …), unique globally.

### Authentication

Per-tenant login on the tenant's subdomain. Argon2id. Rate limits on login and OTP.
Password reset by email or SMS. Optional TOTP 2FA with recovery codes. A session
management screen listing active sessions with the ability to revoke.

### Roles

Seven seeded roles, each with a default permission set:

| Role | Scope |
|---|---|
| Owner | Everything, including billing and user management |
| Manager | Everything operational; no billing |
| Cashier | POS, payments, daily close |
| Salesperson | POS and customers; no cost prices, no reports |
| Technician | Repairs queue and their own tickets; no financials |
| Accountant | Treasury, cheques, installments, reports; no stock edits |
| Warehousekeeper | Stock, transfers, counts, purchasing; no prices |

Permissions are named `module.action` — `sales.create`, `repairs.reveal_passcode`,
`inventory.view_cost`. Cost visibility is a permission of its own: a salesperson
seeing purchase prices is how margins leak to competitors.

**The role list requires sign-off at DECISION GATE 1.**

### Tenant context

Resolved from the subdomain by middleware, which also issues
`SET LOCAL app.tenant_id`. Unknown subdomain → 404, never a fallback to another
tenant. Queue jobs serialise the tenant id and restore context before `handle()`.

## Screens

Onboarding wizard · login · password reset · 2FA setup and challenge · user list ·
invite · role assignment · session management · activity log viewer.

## Events

Emits: `TenantOnboarded`, `UserInvited`, `UserActivated`, `UserDeactivated`,
`LoginSucceeded`, `LoginFailed`, `TwoFactorEnabled`.

## Acceptance

- Full onboarding creates tenant, domain, owner and roles.
- Login, reset and 2FA flows.
- Permission denial matrix: every role × every guarded endpoint.
- **Isolation suite v1** — tenant B requesting tenant A's user or resource gets
  404/403.
- Raw-SQL test proving RLS blocks the leak with no Eloquent scope in play.
- A queued job runs under the correct tenant, and a worker handling tenant A then
  tenant B leaks no context.
- Reserved and duplicate subdomains are rejected.

## Out of scope

SSO/OAuth. Per-field permissions beyond the cost-visibility case. Customer-facing
accounts (the repair tracking page is anonymous and signed).
