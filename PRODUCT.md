# Product

<!-- impeccable:product-schema 1 -->

Product truth for «سامانه همیار» (slug `hamyar`). This file feeds the `impeccable` design
tooling; it records what is confirmed and what is still open. Visual decisions do not live
here — see `DESIGN.md`, `docs/design-system.md` and the ADRs. Facts marked *(inferred)* were
read from the repository and the owner's answers on 2026-09-03 rather than stated by the
owner in those words.

## Platform

web

## Users

- **Primary:** the owner of a mobile-phone shop in Iran — usually one or two people behind
  a counter in a پاساژ, a customer waiting, a mid-range Android phone in hand and a desktop
  at the till. They sell handsets by IMEI, repair devices, sell on instalments and take
  cheques. Persian, RTL, Jalali calendar, rial/toman.
- **Also:** the shop's staff (seller, technician) with narrower permissions; a second branch
  of the same shop; the shop's customer, who only ever sees a public page — a repair-tracking
  link, an electronic invoice, a price list.
- **Never:** an enterprise buyer, an accountant-first user, a developer. There is no API
  audience.

## Product Purpose

A cloud platform where a phone shop subscribes and gets the tools of its day in one place:
POS/sales, serialized IMEI inventory, repairs workflow, CRM, cheques, instalments, treasury,
SMS, reports, HAMTA and Moadian record-keeping. Success is a shop that records its day in
همیار instead of in a notebook and a drawer — and can answer, for any handset, «از کی
خریدم، به کی فروختم، کِی تعمیر شد، الان کجاست» *(inferred from the IMEI passport, the
product's central object)*.

## Positioning

- **The mechanism a neighbour cannot truthfully copy:** every handset is a serialized unit
  with its own history (bought from whom, at what cost → sold to whom → repaired when →
  where it is now), so profit is true per-device profit (cost captured at sale), not "sales
  minus today's price". Everything else in the product hangs off that row.
- **Commercial position:** every module is open to every shop; a plan sells *how much work a
  shop may record in a Jalali month* (ADR 0018). The first rung is free and needs no bank
  card; a lapsed shop falls back to the free plan and is never locked out of its data.
- **Honesty is part of the position:** HAMTA has no public API, so همیار keeps status and
  reminds; the final registration is the shop's own act. The product says so on its own
  landing page and inside the module. No claim is made that the product cannot keep.

## Operating Context

- A sale happens with the customer standing there: scan/type an IMEI, the unit lands on the
  invoice, trade-in, discount, several payment methods, print a thermal receipt (80 mm) or
  A4/A5. Keyboard-first at the till (F-keys).
- Repairs: an intake receipt with a QR tracking link; a board of tickets across real workshop
  states; a delivery step with settlement; abandoned devices («رسوبی»).
- Money: cheques and instalments with due dates; a daily close (Z report); a treasury of cash
  accounts and POS terminal accounts; Excel export of everything.
- Paper matters: receipts, invoices, labels, instalment sheets are printed and handed over.
  Anything printed with a wrong hostname is "paper in somebody's pocket pointing at a domain
  that does not exist" (CLAUDE.md 1b).
- Connectivity: Iranian mobile networks; mid-range Android in the field; the site must be
  fast on 4G and must not depend on foreign CDNs.
- Rituals the audience knows: yellow price labels, IMEI on the box, the parts drawer, the
  thermal roll, the customer's phone number as identity, the daily cash count.

## Capabilities and Constraints

- Stack is locked (PHP 8.4 / Laravel 12 / PostgreSQL 16 with RLS / Inertia v2 + React +
  Tailwind v4 / shadcn with `rtl: true`); public pages are Blade; production runs no Node.
- 18 modules under `app/Modules`; cross-module calls only via events or bound interfaces.
- Money is integer IRR; stock and balances are ledger sums, never stored totals.
- Dates stored UTC, rendered Jalali. Persian digits in prose, Latin tabular digits in tables,
  IMEI/phone Latin and LTR-isolated.
- Every tenant table is RLS-protected; a public page is a signed or tokened route.
- Nine CI guards refuse shapes that shipped as bugs (physical direction classes, hostname
  literals, forms with no error region, mirrored arrows, …). See `CLAUDE.md`.
- **Undecided (owner):** signed public invoice link after the single-host change (`#120`);
  invoice line order (`#99`); POS confirm placement (`#128`).

## Brand Commitments

- Name: «سامانه همیار» when introduced, «همیار» in running prose; slug `hamyar`; apex domain
  from `config('app.domain')` only.
- Voice (owner, 2026-09-03): **professional and confident, in the register of top-tier
  Iranian SaaS** — clear, concrete, benefit-led, formal-friendly; no literary metaphors, no
  slang, every sentence a provable claim. Rules in `docs/brand/voice.md`.
- No invented social proof, ever: no fabricated customer counts, logos, testimonials or
  benchmarks. Trust material is **pilot shops that can be named or counted, with consent**
  (owner, 2026-09-03), true product facts, and honest limits.
- Fonts: free OFL faces only unless the owner buys a FontIran web license (IRANSansX +
  Yekan Bakh). No unlicensed commercial faces (B-series print fonts are out).
- Taste evidence that binds future visual work: a dark cinematic scroll landing was built and
  **rejected on taste** (ADR 0016); a white minimal one was judged lifeless; the owner's own
  lesson is that the landing must feel like the tool. The product's visual language is being
  replaced in the 2026-09 "Redesign v2" programme (plan outside the repo; ADR 0020/0021 record
  the outcome).

## Evidence on Hand

- Real product screens, capturable from the seeded demo tenant (`make fresh`) — the only
  legitimate imagery. The six landing screenshots under `resources/landing/shots/` are from
  2026-08-22 and predate the current product; regenerate before use.
- Plans and quotas are real database rows (`PlanCatalogue`), rendered on the landing from the
  DB; prices must never be typed into a template.
- Twenty product captures under `docs/walks/redesign/` (2026-09-03, light + dark).
- **Absent, must not be fabricated:** paying customers, customer counts, testimonials,
  press, uptime figures, a company name/Enamad (owner to supply), contact channels (owner to
  supply). Pilot-shop names and consent: pending from the owner.

## Product Principles

1. **Record the day, don't describe it.** Every screen exists so a shop can record a real
   event in seconds at a counter; the product proves itself by showing its own screens.
2. **One handset, one row, one history.** The IMEI passport is the product's centre; design
   and copy return to it.
3. **Never claim what we cannot keep.** HAMTA, Moadian, support, uptime: say exactly what the
   product does.
4. **Paper is a first-class output.** What prints must be right in both themes and on cheap
   printers.
5. **Fast on the phone the shopkeeper actually owns.** Budgets are hard limits, not
   aspirations.

## Accessibility & Inclusion

- AA contrast on every token pair, measured (`docs/design-system.md`).
- Touch targets ≥ 40 px for anything tapped (WCAG 2.5.8 inline-link exception).
- `prefers-reduced-motion` honoured everywhere; no scroll-jacking.
- Keyboard: every register row reachable; POS keyboard map; visible focus ring.
- Language: `lang="fa"`, `dir="rtl"` on every document; Persian error messages by default.
