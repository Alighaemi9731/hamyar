/*
 | MobiYar landing — entry.
 |
 | Deliberately small. Everything in this file must work with no GSAP, no Lenis and no
 | network beyond the initial document: the sticky nav, the pricing toggle and the FAQ
 | are page FUNCTIONALITY, not decoration, and a visitor whose effects chunk never
 | arrives must still be able to read a price and open a question.
 |
 | The choreography lives in ./effects.js and is imported dynamically, after first
 | paint, and only when motion is appropriate. That is the whole of the performance
 | story: the page is readable and usable before ~50KB of animation library is even
 | requested.
 */

/* ------------------------------------------------------------------ nav ---- */

const nav = document.querySelector('[data-nav]');

if (nav) {
  const sentinel = document.createElement('div');
  sentinel.setAttribute('aria-hidden', 'true');
  nav.parentNode.insertBefore(sentinel, nav);

  // An IntersectionObserver rather than a scroll listener: the browser does the work
  // off the main thread, and there is no handler firing on every frame of a page whose
  // whole point is smooth scrolling.
  new IntersectionObserver(
    ([entry]) => nav.setAttribute('data-stuck', String(!entry.isIntersecting)),
    { threshold: 0 },
  ).observe(sentinel);
}

/* -------------------------------------------------------------- pricing ---- */
/*
 | Monthly ⇄ yearly.
 |
 | The yearly figure is derived in the markup (data-yearly), not computed here, because
 | the price a customer is shown should be a value somebody chose rather than the
 | output of an expression in a script.
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

/* ---------------------------------------------------------------- FAQ ------ */
/*
 | <details> is already accessible and keyboard-operable with no script at all; this
 | only enforces one-open-at-a-time, which is a preference rather than a requirement.
 | If it never runs, every question still opens.
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

/* --------------------------------------------------------------- enter ----- */
/*
 | Every shop lives on its own hostname, so there is no single login URL to link to
 | from the apex — `/login` exists on `<shop>.<apex>` and nowhere else. This turns the
 | shop name into that address.
 |
 | Client-side on purpose: the apex must not be able to confirm whether a given shop
 | exists. Posting this to the server would turn it into an oracle for enumerating
 | tenant names, and the honest answer for a wrong name is the tenant middleware's own
 | 404, on the shop's own hostname.
 */

const enter = document.querySelector('[data-enter]');

if (enter) {
  enter.addEventListener('submit', (event) => {
    event.preventDefault();
    const raw = new FormData(enter).get('shop') || '';
    // A shopkeeper may paste the whole address; keep only the label.
    const slug = String(raw).trim().toLowerCase().replace(/^https?:\/\//, '').split('.')[0].replace(/[^a-z0-9-]/g, '');
    if (slug) window.location.href = `${window.location.protocol}//${slug}.${window.location.host}/login`;
  });
}

/* ------------------------------------------------------------- effects ----- */
/*
 | Three gates before a byte of animation library is fetched:
 |
 |   1. prefers-reduced-motion — a complete, finished page is the correct experience,
 |      not a degraded one. Nothing is imported at all.
 |   2. Coarse pointer under 900px — the mobile tier. The hero receipt still prints
 |      (it is the story), but the pinned module stage does not exist there, so most of
 |      the choreography has nothing to drive and Lenis is never engaged. No scroll
 |      jacking on touch, per the brief.
 |   3. Data saver / 2G — an Iranian shopkeeper on a bad connection gets the page,
 |      not the show.
 |
 | requestIdleCallback keeps the import off the critical path so it cannot compete with
 | the LCP element, which is the hero heading.
 */

const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const conn = navigator.connection;
const thrifty = Boolean(conn && (conn.saveData || /(^|-)2g$/.test(conn.effectiveType || '')));

if (!reduced && !thrifty) {
  const start = () =>
    import('./effects.js')
      .then((m) => m.init())
      // A failed chunk must leave a readable page, not a half-hidden one. Nothing was
      // hidden before this point — see the data-anim note in landing.css — so there is
      // nothing to restore.
      .catch(() => {});

  if ('requestIdleCallback' in window) {
    requestIdleCallback(start, { timeout: 2500 });
  } else {
    window.addEventListener('load', () => setTimeout(start, 200));
  }
}
