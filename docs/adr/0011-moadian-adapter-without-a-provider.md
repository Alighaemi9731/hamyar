# ADR 0011 — Moadian ships as an adapter with no real provider

- **Status:** **Accepted** at DECISION GATE 4 (part 2), 2026-08-16
- **Date:** 2026-08-16
- **Deciders:** Project owner + lead engineer
- **Approved by:** [DECISION GATE 4 — part 2](../ROADMAP.md): “**NO real Moadian provider
  for launch.** Build 10.4 only to the FakeProvider skeleton … then STOP. No provider
  research, no sandbox, no real driver.”

## Context

Iran's tax authority requires electronic invoices to be submitted to سامانه مودیان, and
several intermediary providers (کیسان، مالیتور و…) sit between shops and the authority.
`docs/specs/moadian.md` was written on the assumption that v1 ships one real driver.

Two facts changed the calculation at the gate:

1. **The customers this launches to are mostly on presumptive taxation** (مالیات مقطوع) and
   will not be filing e-invoices at first. The feature has no user at launch.
2. **Choosing a provider is a commitment with a price.** Each intermediary has its own
   contract, its own credential handling, its own sandbox and its own onboarding. Picking
   one before a paying tenant has asked means buying an integration, maintaining it against
   a specification that changes, and discovering at the first real request that the shop's
   accountant already uses a different provider.

The temptation is to build the real driver anyway "so it is ready". That is the expensive
version of being ready: an untested-in-anger integration against a moving specification,
maintained for nobody.

## Decision

**Build the adapter. Do not choose a provider.**

Ships in Phase 10.4:

- The `MoadianDriver` contract as specified in `docs/specs/moadian.md`.
- `FakeMoadianDriver` — the only implementation, covering accept, reject and transport
  failure.
- Invoice → e-invoice **payload mapping**, pure and unit-tested against fixture invoices,
  with money as integer rial.
- The **queue** path with backoff, and the guarantee that a failed submission never blocks
  invoice finalisation.
- The **status inbox**: submissions list, rejections in actionable Persian, idempotent
  resend.

Explicitly **not** in scope, by ruling rather than by omission:

- No provider research, no sandbox account, no credentials, no real driver.
- No partial "nearly a driver" — an HTTP client with a `TODO: pick a base URL` is worse
  than nothing, because it looks finished.

**Feature-flagged OFF for every plan at launch.** Plan copy says «به‌زودی». `MOADIAN_ENABLED`
stays `false`; no development or production machine submits a tax document.

**Post-launch backlog:** *when the first paying tenant requests Moadian, select a provider
and build the real driver against the existing contract.* That request is the trigger, and
the tenant naming their accountant's provider is the input the decision was missing.

## Why the adapter is still worth building now

Because the part that is expensive to retrofit is not the HTTP call — it is everything
around it. Invoice finalisation has to enqueue rather than block; a rejection needs somewhere
to land that a shop owner can act on; a resend has to be idempotent; the payload has to be a
pure function of an invoice so it can be tested without a network. Those are decisions about
*this* system, and they are the same whichever provider is eventually chosen.

Building them now against a fake also keeps the contract honest. A driver interface designed
around one vendor's API is that vendor's API wearing an interface; one designed against a
fake and a specification stays a boundary.

## Consequences

- **The module ships disabled and visibly unfinished, on purpose.** «به‌زودی» in plan copy is
  a true statement. A toggle a shop can switch on to reach a fake would be a false one.
- **`tests/Arch` and the driver contract tests are the whole safety net** for a code path no
  customer exercises. That is acceptable while the flag is off, and it is exactly the
  situation golden-rule-8's isolation tests exist for when the flag is turned on.
- **The mapping may be wrong in ways only a real submission reveals.** Accepted knowingly.
  It is unit-tested against the published field list and fixture invoices, and the first real
  driver will find whatever the specification did not say. Pretending otherwise would be the
  claim this ADR exists to avoid.
- The Phase 10.6 acceptance line "Moadian driver contract tests with a fake" is fully met;
  the spec's "one real intermediary" line is deferred with this ADR named beside it.

## Alternatives rejected

**Pick a provider now and build the driver.** Rejected at the gate: no customer has asked,
the field is unsettled, and the first real request is likely to name a provider we did not
pick. Buying an integration before knowing which one is needed is how the money is spent
twice.

**Ship nothing — no module, no adapter.** Rejected. The queue, the inbox and the
finalisation-must-not-block guarantee are structural, and retrofitting them into Sales after
launch means touching the invoice path under pressure from a shop that has just been told it
must file electronically.

**Ship the adapter with the flag ON and the fake wired up**, so the screens are reachable.
Rejected: a shop would see submissions "accepted" by nothing. A tax feature that reports
success without submitting is the single worst outcome in this module — the spec already
says silent failure is worse than loud failure, and this would be silent *success*.
