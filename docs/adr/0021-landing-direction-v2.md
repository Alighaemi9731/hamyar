# ADR 0021 — Landing direction v2: the category standard, executed at a named bar

- **Status**: accepted
- **Date**: 2026-09-04
- **Decides**: DECISION GATE 5's composition half (`docs/ROADMAP.md` 16.2). The brand
  layer is ADR 0020.
- **Supersedes**: ADR 0016's landing composition, including its thermal-receipt hero.

## Context

Two landing directions had already been rejected live before this gate: ADR 0016's dark
cinematic scroll page on taste, then the white minimal page it became, judged «بی‌روح»,
then the navy-banded revision judged unprofessional on 2026-09-03. The hero's own source
comments record the «paper slips» panel being rejected three times.

So the gate did not ask an abstract question. Three directions were built as real markup
under local-only routes `/design/landing/{a,b,c}` on the brand layer, with the fresh
product captures in them, and rendered at 1440 and 390:

- **A** «جعبه و برچسب» — assigned by the impeccable direction roll (Persuade, seed
  `fd28c358`, world 6 of 7): the page as the label on the box, the IMEI passport as that
  label. My recommendation.
- **B** «دفتر» — one ruled sheet, the red margin, today's real rows.
- **C** — the category standard played straight, always on the board as the standing exit.

## Decision

**The owner chose C**, and named a reference for the bar it has to clear:
`https://cellivo.com/` — a POS product for phone shops, so the same category and the same
reader.

The interesting half is the second part. "Direction C" on its own is the direction most
likely to produce exactly what was rejected: the category standard is also the shape an
AI page defaults to. A named, measured reference converts C from a style into a target.

### What the reference actually is, measured

Read with a browser on 2026-09-04, at 1440 and 390:

| | value |
|---|---|
| nav | 64px, fixed, `rgba(255,255,255,.8)` + `blur(12px)`, constant 1px shadow, no scroll state change |
| nav shape | wordmark · a **pill-shaped group** holding the links with a white pill behind the active one · «log in» text + one filled action |
| h1 | 64px/64px (1.0 leading), weight 700, tracking −0.025em; 30px on a phone |
| h2 | 48px/48px, weight 700, centred |
| hero | text left, **the product in a browser-chrome frame** right, with four small floating cards overlapping it: an IMEI scan, a paid invoice, a profit delta, a repair at 65% |
| section header | brand-blue uppercase eyebrow · centred h2 whose **second half is in the accent** · a short centred rule · centred sub-copy |
| cards | 1px hairline, 12px radius, white, no shadow, a 40px tinted icon tile per card |
| grounds | white alternating with grey-100 at 35–60% alpha |
| hairline | `#e5e7eb`, on 1004 elements — one line, everywhere |
| ink / accent | `#0f1729` (a navy) / `#3e56e0` |
| scroll | `scroll-behavior: smooth`, **zero elements parked at `opacity: 0`**, one transform transition on the page |
| height | 9,878px at 1440; 12,847px at 390 |

Two of those measurements changed my plan:

1. **There is no scroll choreography.** The "nice scrolling" the owner liked is one CSS
   line plus section rhythm. Our landing has a `.reveal` system; it is not what makes a
   page feel considered, and a page whose content is parked at `opacity: 0` waiting for
   an observer is worse than one at rest. The reveal system goes.
2. **Length was never the defect.** The 16.0 baseline set a target of ≤7,000px at 390
   against our 11,002px. The reference is 12,847px and reads better. The real targets are
   a mobile nav that exists, no sideways overflow, and every screen earning its height.
   The ≤7,000px target is withdrawn.

Their ink `#0f1729` also lands within two steps of the navy the owner picked
independently in ADR 0020, which is a small confirmation that the ink is a category
convention rather than a personal taste.

### What we take

Structure and convention, none of which is anybody's property:

- the fixed frosted nav with a pill link group and a lit active pill (mirrored for RTL);
- the wordmark treatment — the product's name set as a mark, not typed as a heading;
- the hero split: copy on the reading-start side, **the real product in a frame** on the
  other, with floating cards naming real domain moments;
- the section header block — eyebrow, two-tone heading, short rule, sub-copy;
- hairline card grids with tinted icon tiles, one tint per section's mood;
- alternating white and tinted grounds as the only separator between sections;
- `scroll-behavior: smooth` and generous, consistent vertical rhythm.

### What we do not take

- **Their invented proof.** Their fold carries «Used by 500+ Phone Shops Worldwide». We
  have no such number, and `docs/brand/voice.md` rule 3 forbids inventing one. Our
  eyebrow states a true product fact until the owner supplies pilot shops with consent.
  This is the single most tempting thing on the page to copy and the one that would make
  the whole page a lie.
- Their copy, images, logo and code.
- Their accent. Ours stays `#0066cc` (ADR 0008) — measured against our grounds.
- Their 100vh hero. Our hero is sized to what it holds, so the first still frame shows
  the product rather than pushing it below the fold.

### Consequences

- The thermal-receipt hero of ADR 0016 is retired, and with it the `.mesh` grid overlay
  the 16.0 baseline flagged as a generated-UI signature.
- The landing is rebuilt on the named-line page grid (`content` / `wide` / `full`), in
  Blade, with `lang/fa/landing.php` carrying the strings.
- The floating hero cards are fed by the same real captures `bin/shots` produces, so they
  cannot go stale silently.
- Directions A and B are not lost work: they are recorded as **captures** in
  `docs/walks/v2/gate-16.2/` (each direction at 1280 and 390) and in
  `docs/walks/v2/gate-16.2.md`, and the reasoning that produced them is why C is now
  being executed against a measured bar rather than from memory.

  **Amended 2026-09-05.** This line used to point at the live routes
  `/design/landing/{a,b,c}`, and those routes are now gone — retired with the phase they
  served, per the "disposable" note their own registration carried. They were deleted only
  after being photographed, because this sentence made them the record and deleting a
  record is not a cleanup. The three templates also still drew the retired symbol beside
  «همیار» set as text, so leaving them would have left the old brand alive in the
  repository after every shipping surface had moved on. `resources/brand/mark-c.svg` went
  with them; it had no other consumer.
