# ADR 0005 — RTL is enforced by a build gate that matches Tailwind value syntax

- **Status:** Accepted
- **Date:** 2026-08-07
- **Deciders:** Project owner + lead engineer
- **Approved by:** `docs/PROGRESS.md`, 2026-08-07: “ADR 0004 … and ADR 0005 (RTL gate …) written and **approved**”, and `docs/ROADMAP.md` task 0.2 marks it “(approved 2026-08-07)”.

## Context

The entire product is right-to-left Persian. Golden rule 9 requires *logical* Tailwind
utilities (`ms-` `me-` `ps-` `pe-` `start-` `end-` `text-start` `text-end` `border-s`
`border-e`) and forbids physical ones (`ml-` `mr-` `pl-` `pr-` `left-` `right-`
`text-left` `text-right` `float-left`).

The failure mode is nasty because it is invisible to the author. A physical class
written by someone thinking in LTR renders *fine on their screen* and mirrors wrongly
for the user — a back button on the wrong side, a table column aligned into the
gutter, an icon pointing the wrong way. Nothing throws, no test fails, and it is only
caught by someone actually reading Persian looking at the screen.

Code review does not catch this reliably. It is a single token buried in a
200-character `className` string, and reviewers habituate.

We also could not rely on shadcn's `"rtl": true` alone: it converts components the CLI
generates, but says nothing about the code we write ourselves, which is where most
classes come from.

## Decision

**A grep-style gate, `bin/check-direction-classes`, fails the build on any physical
direction class.** It runs inside `composer test` (before static analysis, because it
is cheap) and as its own CI job.

The critical design decision is *how* it matches. A naive pattern — the physical prefix
followed by "any word characters" — is unusable, and we proved it: the first version
flagged

- `left-to-right` and `right-pointing` in **English comments**, and
- `slide-in-from-left-2`, a legitimate compound animation utility,

while simultaneously **missing** `-ml-8`, because the leading `-` of a negative
utility was not a recognised token boundary.

A gate with false positives is worse than no gate: it trains everyone to sprinkle
`rtl-allow` reflexively, and the real violations then sail through with the noise.

So the matcher models real Tailwind syntax:

- Each rule declares its shape — `value` (prefix requires a value: `ml-4`,
  `left-[3px]`), `optional` (`border-l`, `border-l-2`), or `exact` (`text-left`).
- A value must actually look like a Tailwind value: a numeric scale step, a keyword
  (`auto` `full` `px` `screen` `min` `max` `fit`), an arbitrary `[…]` value, or a CSS
  variable `(--x)`. `pointing` and `to-right` are not values.
- Token boundaries include the start of line, whitespace, quotes, backticks, `{`, `[`
  and Tailwind variant separators (`sm:` `hover:` `rtl:`), **plus** a leading `-` that
  is itself at one of those boundaries — which catches `-ml-8` without matching the
  `-left-2` inside `slide-in-from-left-2`.

**The gate has its own test suite** (`tests/Unit/DirectionClassGateTest.php`, 39
cases): physical classes it must flag, logical classes it must not, English prose it
must ignore, compound utilities it must ignore, and both forms of the escape hatch.
A linter without tests drifts.

### Escape hatch

`rtl-allow` in a comment on the same line, or the line immediately above (long
`className` strings are one very long line, so an inline comment is often impossible).
It requires a stated reason and is legitimate only for genuinely physical APIs — Radix's
`Sheet side="left"` must pin to the physical left edge — and almost nothing else.
There is exactly one use in the codebase today.

## Alternatives considered

**An ESLint rule.** Better tooling in principle, but it would cover only `.tsx`. Our
physical classes can also appear in Blade print templates and emails, which is where
`@media print` and paper-edge thinking makes LTR habits strongest. A file-based gate
covers `resources/`, `app/` and `routes/` uniformly.

**Rely on `shadcn migrate rtl`.** Covers generated components once. Says nothing about
new code, which is the actual risk surface.

**A Tailwind plugin that removes physical utilities.** Would make them silently no-op
rather than loudly wrong — the author gets no feedback and the layout is subtly broken
instead of obviously broken.

**Review checklist only.** This is what the rule was before the gate. It does not
survive a deadline.

## Consequences

- **Positive.** The class of bug is eliminated at build time rather than discovered by
  a Persian-reading user.
- **Positive.** The rule is executable, so it is unambiguous. "Is this okay?" has an
  answer you can run.
- **Positive.** Cheap — a regex pass over ~90 files, well under a second.
- **Negative.** It is pattern matching, not parsing, so it can in principle be fooled
  by dynamically composed class names (`` `m${side}-4` ``). Accepted: such code is
  already a review smell, and the design-system rules forbid constructing utility
  names at runtime.
- **Negative.** New physical utilities added by future Tailwind versions need adding to
  `RULES`. Accepted; the list is short and the file is well-commented.
- **Binding.** Removing the gate from `composer test` or from CI requires superseding
  this ADR.
