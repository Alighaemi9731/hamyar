/*
 | MobiYar landing — the whole of its JavaScript.
 |
 | There is no second chunk. The rejected dark direction (ADR 0016) dynamically imported
 | ~50KB of GSAP, ScrollTrigger and Lenis to drive a pinned, scrubbed hero; this
 | direction asks typography and whitespace to carry the page, so the only motion left is
 | a 220ms fade-and-rise on section entry — which is an IntersectionObserver and a CSS
 | transition.
 |
 | Everything here is page FUNCTIONALITY except the observer: the sticky-nav hairline,
 | the pricing toggle, the FAQ and the shop-entry form all have to work whether or not
 | this file ever arrives.
 */

/* ------------------------------------------------------------------ nav ---- */
/*
 | A hairline appears under the nav once the page has scrolled. An observer on a
 | sentinel rather than a scroll listener: the browser does the work, and there is no
 | handler running on every frame.
 */

const nav = document.querySelector('[data-nav]');

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

/* ---------------------------------------------------------------- enter ---- */
/*
 | Every shop lives on its own hostname, so there is no single login URL to link to from
 | the apex — `/login` exists on `<shop>.<apex>` and nowhere else. This turns a shop name
 | into that address.
 |
 | Client-side on purpose: the apex must not be able to confirm whether a given shop
 | exists. Posting this to the server would make it an oracle for enumerating tenant
 | names, and the honest answer for a wrong name is the tenant middleware's own 404, on
 | the shop's own hostname.
 */

const enter = document.querySelector('[data-enter]');

if (enter) {
  enter.addEventListener('submit', (event) => {
    event.preventDefault();
    const raw = new FormData(enter).get('shop') || '';
    // A shopkeeper may paste the whole address; keep only the label.
    const slug = String(raw)
      .trim()
      .toLowerCase()
      .replace(/^https?:\/\//, '')
      .split('.')[0]
      .replace(/[^a-z0-9-]/g, '');
    if (slug) window.location.href = `${window.location.protocol}//${slug}.${window.location.host}/login`;
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
 */

if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
  const targets = document.querySelectorAll('.rise');

  if (targets.length && 'IntersectionObserver' in window) {
    document.documentElement.setAttribute('data-io', 'on');

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
}
