/*
 | Hamyar landing — the whole of its JavaScript.
 |
 | There is no second chunk. The rejected dark direction (ADR 0016) dynamically imported
 | ~50KB of GSAP, ScrollTrigger and Lenis to drive a pinned, scrubbed hero; this
 | direction asks typography and whitespace to carry the page, so the only motion left is
 | a 220ms fade-and-rise on section entry — which is an IntersectionObserver and a CSS
 | transition.
 |
 | Everything here is page FUNCTIONALITY except the observer and the spine: the
 | sticky-nav hairline, the pricing toggle and the FAQ all have to work whether or not
 | this file ever arrives.
 */

/* ------------------------------------------------------------------ nav ---- */
/*
 | A hairline appears under the nav once the page has scrolled. An observer on a
 | sentinel rather than a scroll listener: the browser does the work, and there is no
 | handler running on every frame.
 */

const nav = document.querySelector('[data-nav]');


import './sections/signature.js';
import './sections/imei.js';

if (nav) {
  const sentinel = document.createElement('div');
  sentinel.setAttribute('aria-hidden', 'true');
  nav.parentNode.insertBefore(sentinel, nav);

  new IntersectionObserver(
    ([entry]) => nav.setAttribute('data-stuck', String(!entry.isIntersecting)),
    { threshold: 0 },
  ).observe(sentinel);
}

/* -------------------------------------------------------------- pricing ---- */
/*
 | Monthly ⇄ yearly. The yearly figure is rendered into the markup by Blade
 | (data-yearly), not computed here: a price a customer is shown should be a value
 | somebody chose, not the output of an expression in a script.
 */

const toggle = document.querySelector('[data-plan-toggle]');

if (toggle) {
  const buttons = toggle.querySelectorAll('button[data-interval]');
  const prices = document.querySelectorAll('[data-monthly]');
  const units = document.querySelectorAll('[data-unit]');
  const saving = document.querySelector('[data-saving]');

  const apply = (interval) => {
    buttons.forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.interval === interval)));
    prices.forEach((el) => {
      el.textContent = interval === 'year' ? el.dataset.yearly : el.dataset.monthly;
    });
    units.forEach((el) => {
      el.textContent = interval === 'year' ? el.dataset.unitYear : el.dataset.unitMonth;
    });
    if (saving) saving.hidden = interval !== 'year';
  };

  buttons.forEach((b) => b.addEventListener('click', () => apply(b.dataset.interval)));
  apply('month');
}

/* ------------------------------------------------------------------ FAQ ---- */
/*
 | <details> is accessible and keyboard-operable with no script at all; this only
 | enforces one-open-at-a-time, which is a preference. If it never runs, every question
 | still opens.
 */

const faq = document.querySelector('[data-faq]');

if (faq) {
  const items = faq.querySelectorAll('details');
  items.forEach((d) =>
    d.addEventListener('toggle', () => {
      if (d.open) items.forEach((other) => other !== d && (other.open = false));
    }),
  );
}

/* --------------------------------------------------------------- reveal ---- */
/*
 | Password show/hide on the auth pages.
 |
 | The button is `type="button"` in the markup so that with this file absent it is inert
 | rather than submitting the form — a reveal toggle that posts your half-typed password
 | is the failure worth designing against.
 */

for (const button of document.querySelectorAll('[data-reveal]')) {
  button.addEventListener('click', () => {
    const input = document.getElementById(button.dataset.reveal);
    if (!input) return;
    const shown = input.type === 'text';
    input.type = shown ? 'password' : 'text';
    button.setAttribute('aria-label', shown ? 'نمایش رمز عبور' : 'پنهان کردن رمز عبور');
  });
}

/* --------------------------------------------------------------- motion ---- */
/*
 | The only animation on the page.
 |
 | `data-io` is set on <html> BEFORE anything is observed, and the CSS hides `.rise`
 | only under that attribute — so the hidden state exists exactly as long as something
 | is guaranteed to reveal it. With this file absent, blocked, or broken, nothing is
 | ever hidden and the page is simply a page.
 |
 | Reduced motion skips the observer entirely rather than animating instantly: there is
 | nothing to reveal because nothing was hidden.
 |
 | ## The stagger, and why it is written here
 |
 | THE ONE RULE (landing.css): `.rise` sits at exactly one level per section — the level
 | at which the content is a list of peers — and each peer's arrival is delayed by
 | `min(index, 3) × 60ms`. The clamp is applied by the CSS; this file only has to say
 | what index a peer is.
 |
 | It is written from JS rather than hand-authored in the markup for one reason: a
 | hand-written `--i` is a number a section author has to remember to renumber, and the
 | day a seventh tour tile or a fourth plan is added, half a list staggers and half of it
 | does not. Counting from the DOM cannot drift.
 |
 | The index resets per PARENT, not per page: `.rise` blocks in different sections are
 | different lists, and continuing the count across them would make the last section on
 | the page wait for a stagger it is not part of.
 */

if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
  const targets = document.querySelectorAll('.rise');

  if (targets.length && 'IntersectionObserver' in window) {
    document.documentElement.setAttribute('data-io', 'on');

    // Index each risen block among its own siblings. Non-`.rise` children are skipped,
    // so a list whose first child is a heading still starts its stagger at zero.
    const counted = new Map();
    for (const el of targets) {
      const parent = el.parentElement;
      const next = (counted.get(parent) ?? 0);
      counted.set(parent, next + 1);
      el.style.setProperty('--i', String(next));
    }

    const io = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (!entry.isIntersecting) continue;
          entry.target.setAttribute('data-seen', 'true');
          io.unobserve(entry.target); // once, never on the way back up
        }
      },
      // A little before the element arrives, so it is finished by the time it is read.
      { rootMargin: '0px 0px -8% 0px', threshold: 0.01 },
    );

    targets.forEach((el) => io.observe(el));
  }

  /* ------------------------------------------------------------- spine ---- */
  /*
  | The scroll line. The page's only scroll-linked effect, and deliberately the cheap
  | kind: a passive listener, throttled to one read per frame, writing a single CSS
  | custom property. No library, and nothing that can hold the scroll.
  |
  | The read (getBoundingClientRect) and the write (style.setProperty) are separated by
  | the rAF boundary so this cannot cause layout thrash — measuring after writing in the
  | same frame is what turns a cheap effect into a janky one.
  */

  const spine = document.querySelector('[data-spine]');
  const fill = document.querySelector('[data-spine-fill]');

  if (spine && fill && window.matchMedia('(min-width: 900px)').matches) {
    let queued = false;

    const nodes = [...document.querySelectorAll('[data-node]')];

    const paint = () => {
      queued = false;
      const box = spine.getBoundingClientRect();
      // Fill in step with where the reader is: 0 when the rail's top reaches the middle
      // of the screen, 1 when its bottom does.
      const middle = window.innerHeight / 2;
      const progress = (middle - box.top) / (box.height || 1);
      fill.style.setProperty('--spine', String(Math.min(1, Math.max(0, progress))));

      // The nodes light from the SAME point the rail fills to, not from their own
      // IntersectionObserver. Driven separately they disagree by most of a screen —
      // a row entering from the bottom lit its node while the line was still half a
      // viewport above it, so the rail visibly trailed its own markers.
      for (const node of nodes) {
        const rect = node.getBoundingClientRect();
        node.toggleAttribute('data-lit', rect.top + rect.height / 2 <= middle);
      }
    };

    const onScroll = () => {
      if (queued) return;
      queued = true;
      requestAnimationFrame(paint);
    };

    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll, { passive: true });
    paint();
  }
}
