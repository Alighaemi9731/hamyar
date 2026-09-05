/*
 | Hamyar landing — the whole of its JavaScript.
 |
 | There is no second chunk, and there is no motion here at all. The rejected dark
 | direction (ADR 0016) dynamically imported ~50KB of GSAP, ScrollTrigger and Lenis to
 | drive a pinned, scrubbed hero. ADR 0021 removed the last of what replaced it: the
 | reference the owner named has zero elements parked at `opacity: 0` and one transform
 | transition on the whole page, so what reads as a considered scroll is
 | `scroll-behavior: smooth` and section rhythm — both CSS, both already here.
 |
 | The nav's own scroll observer went with it: the bar no longer changes as you scroll.
 | The mobile menu is a `<details>`, so it needs nothing from this file either.
 |
 | Everything left is page FUNCTIONALITY — the pricing toggle, the FAQ and the password
 | reveal all have to work whether or not this file ever arrives.
 */

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
 | ## What this used to do, and why it read as broken
 |
 | It flipped `input.type` and swapped `aria-label`, and that was all. The icon was one
 | static open eye, so nothing on the screen changed except the field's own contents —
 | which means that over an EMPTY field, which is how anybody reviewing a form presses a
 | button for the first time, pressing it did nothing observable whatsoever. Reported by
 | the owner on 2026-09-05 as a button that does not work, and fairly.
 |
 | Now the button owns a state: `aria-pressed` says whether the password is showing, CSS
 | draws the matching icon off that attribute, and the label comes from the markup rather
 | than from two Persian string literals in a script — Persian copy belongs in the view.
 |
 | ## Why it is hidden until this runs
 |
 | The markup ships it `hidden` and the line below is what reveals it, so a control that
 | cannot work is absent rather than inert. `type="button"` stays regardless: with this
 | file gone AND the attribute wrong, the eye would submit a half-typed password.
 */

for (const button of document.querySelectorAll('[data-reveal]')) {
  const input = document.getElementById(button.dataset.reveal);
  if (!input) continue;

  button.hidden = false;

  button.addEventListener('click', () => {
    const shown = input.type !== 'password';
    input.type = shown ? 'password' : 'text';
    button.setAttribute('aria-pressed', String(!shown));
    button.setAttribute(
      'aria-label',
      (shown ? button.dataset.labelShow : button.dataset.labelHide) ?? button.getAttribute('aria-label'),
    );
    // Focus deliberately stays on the button. Sending it to the input would mean a
    // keyboard user could not press the same control again to hide what they just showed.
  });
}

/* ---------------------------------------------- two-factor challenge ------- */
/*
 | The switch between the authenticator code and a recovery code.
 |
 | ## It hides a field rather than revealing one
 |
 | The opposite of the reveal button above, and for the opposite reason. Both fields ship
 | VISIBLE in `auth/two-factor-challenge.blade.php`, because the recovery field needs no
 | script whatsoever: it is a plain input on a plain POST, and `required_without:code` on
 | the controller takes whichever of the two arrives filled. So this tidies a working page
 | down to one field. With this file blocked the challenge is two labelled fields and a
 | submit — busier than intended, and not broken.
 |
 | The BUTTON is what ships `hidden`, and the line below is what reveals it: a switch that
 | cannot switch anything is absent rather than inert.
 |
 | ## Why the hidden field is emptied
 |
 | `required_without` is satisfied by either key, and the controller prefers `code` when it
 | is non-empty. A half-typed code left behind the switch would therefore be the value the
 | server checked, and the recovery code the person actually typed would be ignored — a
 | refusal with no visible cause, on the one screen where a refusal costs them their phone.
 |
 | Persian copy travels on data attributes, as it does for the reveal control: the strings
 | belong to `lang/fa/auth.php`, and a script holding its own copy is a second place to
 | change the wording.
 */
const twoFactorToggle = document.querySelector('[data-two-factor-toggle]');
const twoFactorFields = {
  code: document.querySelector('[data-two-factor-field="code"]'),
  recovery: document.querySelector('[data-two-factor-field="recovery"]'),
};

if (twoFactorToggle && twoFactorFields.code && twoFactorFields.recovery) {
  const hint = document.querySelector('[data-two-factor-hint]');
  let mode = 'code';

  const applyTwoFactorMode = (moveFocus) => {
    const showing = twoFactorFields[mode];
    const hiding = mode === 'code' ? twoFactorFields.recovery : twoFactorFields.code;

    showing.hidden = false;
    hiding.hidden = true;

    const stale = hiding.querySelector('input');
    if (stale) stale.value = '';

    // Offer the OTHER way in, and describe the one now on screen.
    twoFactorToggle.textContent =
      mode === 'code' ? twoFactorToggle.dataset.labelRecovery : twoFactorToggle.dataset.labelCode;

    if (hint) {
      hint.textContent =
        mode === 'code' ? twoFactorToggle.dataset.hintCode : twoFactorToggle.dataset.hintRecovery;
    }

    // Only on a press. On load the code field's own `autofocus` has it, and stealing it
    // back here would scroll a phone to the field it was already on.
    if (moveFocus) showing.querySelector('input')?.focus();
  };

  applyTwoFactorMode(false);
  twoFactorToggle.hidden = false;

  twoFactorToggle.addEventListener('click', () => {
    mode = mode === 'code' ? 'recovery' : 'code';
    applyTwoFactorMode(true);
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

/* ------------------------------------------------------- security code ---- */
/*
 | The refresh control beside the «کد امنیتی» drawing.
 |
 | Progressive, like everything else here: without this file the control is a button that
 | does nothing visible, and the visitor still has a working code — reloading the page
 | draws a new one. With it, the drawing is swapped in place so a mistyped code does not
 | cost the mobile number and password already entered.
 |
 | `response.text()` into `innerHTML` on OUR OWN endpoint, which returns image/svg+xml
 | generated by `App\Support\SecurityCode` from a fixed alphabet — no user input reaches
 | it. The endpoint is throttled server-side; this button is not the rate limit.
 */
const securityRefresh = document.querySelector('[data-security-refresh]');
const securityImage = document.querySelector('[data-security-image]');
const securityStatus = document.querySelector('[data-security-status]');
const securityField = document.getElementById('security_code');

if (securityRefresh && securityImage) {
  securityRefresh.addEventListener('click', async () => {
    securityRefresh.disabled = true;

    try {
      const response = await fetch(securityRefresh.dataset.securityRefresh, {
        headers: { Accept: 'image/svg+xml' },
        credentials: 'same-origin',
      });

      if (!response.ok) {
        // Throttled (the endpoint allows 30 a minute) or offline. Say so, because the
        // drawing on screen is still the valid one and the visitor needs to know that
        // rather than to keep pressing a button that appears to do nothing.
        announce('کد تازه‌ای گرفته نشد. همان کد روی صفحه معتبر است.');
        return;
      }

      securityImage.innerHTML = await response.text();

      // The answer on screen is now a different one, so whatever was typed is wrong.
      if (securityField) securityField.value = '';
      securityField?.focus();
      announce('کد امنیتی تازه‌ای نشان داده شد.');
    } catch {
      announce('کد تازه‌ای گرفته نشد. همان کد روی صفحه معتبر است.');
    } finally {
      securityRefresh.disabled = false;
    }
  });
}

/*
 | The swap has no sound and, to a screen reader, no visible change either: the drawing is
 | one `role="img"` whose name does not change when its contents do. The live region is the
 | only thing that reports it. Persian copy stays out of this file where it can — these two
 | are the exception, because they describe an outcome the markup cannot know in advance.
 */
function announce(message) {
  if (!securityStatus) return;
  securityStatus.textContent = message;
}
