/*
 | MobiYar landing — the signature element's whole script.
 |
 | It writes ONE number. `--fold` goes 0 → 1 across the first 45% of a viewport of
 | scrolling, and `sections/hero.css` spends it on `rotate`, `translate` and one
 | `opacity`: the day's paper slips lying in a pile on the counter square up into the
 | ledger's rows and take their ثبت marks.
 |
 | ## What it deliberately does not do
 |
 | No library. No pin, no scrub, no scroll hijack, no second chunk — ADR 0016 records
 | that ~50KB of GSAP, ScrollTrigger and Lenis drove the direction that was built,
 | deployed and rejected. One passive listener, throttled to one read per frame, and the
 | read (`window.scrollY`) and the write (`setProperty`) are the only two things in it.
 |
 | ## Why the finished state is the default
 |
 | The CSS declares `--fold: 1` — the filed, complete ledger — and only the presence of
 | `data-signature="on"` on <html> introduces the pile. This file sets that attribute,
 | so the unfinished state exists exactly as long as something is guaranteed to finish
 | it. Blocked, broken, or never shipped, the panel is simply a rendered ledger.
 |
 | That is also the ADR 0016 lesson stated as code: the previous direction drove a whole
 | receipt from scroll position zero, and a visitor at the top of the page got a blank
 | sheet of paper. Here scroll changes where four slips SIT. Every one of them, and every
 | word on them, is painted before this file runs.
 |
 | ## Two gates
 |
 | · `prefers-reduced-motion` — nothing runs, and hero.css pins `--fold` as well.
 | · Below 900px — the page's single reflow line, `--bp-m` in landing.css, and the width
 |   at which .pitch__grid becomes two columns. Keep the two numbers equal: the pile is
 |   composed for the spread, so if they ever drift apart one of them is wrong. Below it
 |   the hero is a single column and the panel is full width; the pile was
 |   composed for the two-column layout, so a phone gets the still. The gate is live: a
 |   window dragged across the breakpoint picks up or drops the effect, and dropping it
 |   restores the finished state rather than freezing a half-squared pile.
 */

const panel = document.querySelector('[data-counter]');

if (panel && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
  const wide = window.matchMedia('(min-width: 900px)');

  let queued = false;

  const paint = () => {
    queued = false;
    // The panel sits at the top of the document, so document scroll IS its progress.
    // 45% of a viewport, so the squaring finishes while the panel is still fully in
    // view rather than as it leaves.
    const span = window.innerHeight * 0.45 || 1;
    const fold = Math.min(1, Math.max(0, window.scrollY / span));
    panel.style.setProperty('--fold', String(fold));
  };

  const onScroll = () => {
    if (queued) return;
    queued = true;
    requestAnimationFrame(paint);
  };

  const sync = () => {
    if (wide.matches) {
      /*
      | Suppress the slip transition for exactly one frame while the pile is introduced.
      |
      | This is a module script, so it runs AFTER first paint. First paint is the FINISHED
      | ledger (the stylesheet's `--fold: 1` default, which is also the no-JS state).
      | Setting `data-signature` flips `--fold` to 0 — and with a 120ms transition on the
      | slips, every desktop visitor watched the tidy ledger visibly FALL APART on load,
      | which is the exact opposite of what the headline beside it claims.
      |
      | `data-boot` kills the transition, the flip happens invisibly, and the attribute is
      | dropped on the next frame so scrolling still animates.
      */
      panel.setAttribute('data-boot', '');
      document.documentElement.setAttribute('data-signature', 'on');
      // Re-adding an identical listener is a no-op, so `sync` is safe to call as often
      // as the breakpoint changes.
      window.addEventListener('scroll', onScroll, { passive: true });
      window.addEventListener('resize', onScroll, { passive: true });
      paint(); // covers a restored scroll position on a reload part-way down the page
      requestAnimationFrame(() => panel.removeAttribute('data-boot'));
      return;
    }

    window.removeEventListener('scroll', onScroll);
    window.removeEventListener('resize', onScroll);
    document.documentElement.removeAttribute('data-signature');
    // Hand the property back to the stylesheet, whose default is the finished state.
    panel.style.removeProperty('--fold');
  };

  sync();
  wide.addEventListener('change', sync);
}
