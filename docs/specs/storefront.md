# Storefront & reseller price list

**Phase 10** · Module `app/Modules/Storefront`

## Purpose

Two public-facing surfaces, both read-only:

1. A simple shop page with a live catalogue — an Iranian phone shop's answer to "do
   you have it and how much?".
2. A **reseller price list link** — the feature colleagues actually ask for. A shop
   sends a link to another trader; it shows reseller-level prices, is password
   protected, and expires.

Full online ordering is an explicit anti-goal. This sells the phone call, not the
checkout.

## Data

- `storefront_settings` — per tenant: enabled, slug, display name, logo, about text,
  address, phones, WhatsApp number, working hours, which categories to show, whether
  to show out-of-stock items.
- `price_list_links` — `token`, `price_level_id`, `password_hash` (nullable),
  `expires_at`, `categories` (JSON), `view_count`, `last_viewed_at`, `created_by`,
  `revoked_at`.
- `price_list_views` — an access log: token, IP, user agent, timestamp.

## Behaviour

### Public catalogue

At `<shop>.hamyar.ir/shop` (or a custom domain later). Shows active products with
consumer prices, availability as a coarse indicator (in stock / call us) rather than
an exact count, and contact CTAs including WhatsApp.

**It exposes nothing private**: no costs, no other price levels, no customer data, no
IMEIs, no stock quantities.

### Reseller price list

A signed link, optionally password-protected, always with an expiry (default 7 days).
It shows the chosen price level for the chosen categories.

Security rules, all tested:

- Expired token → 410, and never the prices.
- Wrong password → 403, rate-limited to defeat brute force.
- The token grants **only** the price level it was minted with. Changing the URL
  cannot escalate to another level.
- Revoking a link takes effect immediately.
- Every view is logged, so a shop can see when a list leaked further than intended.

PDF export of the same list, for sending over WhatsApp where a link would not be
opened.

### SEO and performance

Blade + Tailwind, no React, server-rendered. Public pages must be fast on an Iranian
mobile connection ([design-system.md](../design-system.md#landing) sets the budget).

## Screens

Storefront settings · public catalogue · price-list link manager (create, copy, revoke,
view log) · public price-list page · PDF export.

## Events

Emits: `PriceListLinkCreated`, `PriceListViewed`, `PriceListLinkRevoked`.

## Acceptance

- The public catalogue leaks no cost, no non-consumer price level and no customer data.
- Expired, wrong-password and revoked links all fail closed.
- A token cannot be manipulated to reveal a different price level.
- Views are logged with timestamp and IP.
- The PDF matches the web list exactly.
- Rate limiting holds under a brute-force attempt on the password.
- Cross-tenant isolation: one shop's link never renders another shop's catalogue.
- **Quota.** `storefront.price_list_links` is a standing capacity — how many links are live
  at once, not how many were ever minted — so revoking a leaked price list gives the slot
  back. That is deliberate: revoking a leak is the one act that must never be rationed
  (ADR 0018).

## Out of scope

Cart and checkout. Payments. Stock reservation from the storefront. Custom domains
(a later phase).
