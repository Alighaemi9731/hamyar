/*
 | MobiYar landing — scroll choreography.
 |
 | Imported dynamically by landing.js, after first paint, and only when motion has
 | already been judged appropriate. Nothing in here is required for the page to be
 | read, priced or navigated.
 |
 | Two pins on the whole page and no more: the hero receipt, and the module stage.
 | Beyond about two, pinning stops reading as cinema and starts reading as a page that
 | will not let you leave — and on a phone it is simply broken. The five flagship
 | modules therefore share ONE pinned stage rather than taking a pin each.
 */

import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

gsap.registerPlugin(ScrollTrigger);

const DESKTOP = '(min-width: 900px) and (pointer: fine)';

export function init() {
  document.documentElement.setAttribute('data-anim', 'on');

  /*
  | FONTS FIRST, THEN refresh(). Do not remove this.
  |
  | Every ScrollTrigger start/end is a pixel offset computed from the document height at
  | the moment it is created. Estedad and Vazirmatn are ~90KB of Persian webfont that
  | land AFTER this module runs, and when they swap in, every heading on the page
  | changes height. Triggers created before that keep their stale offsets, so scenes
  | fire early, the pin releases in the wrong place, and the hero receipt finishes
  | printing halfway up the next section.
  |
  | It fails silently and only on a cold cache — which is to say, only for first-time
  | visitors, which is the entire audience for a landing page. A warm reload looks
  | perfect and hides it completely.
  |
  | document.fonts.ready resolves once the faces in use have loaded; the catch is for
  | browsers where it rejects rather than resolving, in which case a stale layout is
  | still better than no animation.
  */
  const ready = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();

  ready.catch(() => {}).then(() => {
    build();
    ScrollTrigger.refresh();
    // Images below the fold are lazy and also change document height as they arrive.
    window.addEventListener('load', () => ScrollTrigger.refresh(), { once: true });
  });
}

function build() {
  const mm = gsap.matchMedia();

  /* ------------------------------------------------------------ reveals ---- */
  // Cheap, everywhere, both tiers. Small y so it reads as a fade rather than a slide.
  gsap.utils.toArray('.l-reveal').forEach((el) => {
    gsap.to(el, {
      opacity: 1,
      y: 0,
      duration: 0.55,
      ease: 'power2.out',
      scrollTrigger: { trigger: el, start: 'top 88%', once: true },
    });
  });

  /* ------------------------------------------------------- hero receipt ---- */
  /*
  | The signature. The receipt prints line by line, tied to the scrollbar rather than
  | to a clock, so the visitor is doing the printing. Three acts, matching a real job
  | through the shop: پذیرش تعمیر ← پیامک آماده تحویل ← تسویه.
  |
  | Pinned on desktop. On a phone it is NOT pinned — the receipt still prints, driven
  | by the section scrolling past, which keeps the story without taking the scroll away
  | from someone using a thumb.
  */
  const receipt = document.querySelector('[data-print]');

  if (receipt) {
    receipt.setAttribute('data-print', 'on');

    // Acts two and three only — the intake block is already printed. See the note in
    // landing.blade.php: a receipt that is blank when you arrive is not a signature,
    // it is a sheet of paper.
    const rows = receipt.querySelectorAll('[data-act]');

    mm.add(
      {
        desktop: DESKTOP,
        handheld: '(max-width: 899px), (pointer: coarse)',
      },
      (ctx) => {
        const { desktop } = ctx.conditions;

        const tl = gsap.timeline({
          scrollTrigger: {
            trigger: '[data-hero]',
            start: desktop ? 'top top' : 'top 70%',
            end: desktop ? '+=120%' : 'bottom 40%',
            scrub: 0.8,
            pin: desktop,
            anticipatePin: desktop ? 1 : 0,
            invalidateOnRefresh: true,
          },
        });

        // A thermal head prints a line at a time; it does not fade a paragraph in.
        tl.to(rows, { opacity: 1, duration: 0.4, stagger: 0.55, ease: 'none' });
      },
    );
  }

  /* -------------------------------------------------------- module stage --- */
  /*
  | ONE pin, five scenes. Desktop only — below 900px the markup is already an ordinary
  | stacked list and needs no help.
  */
  mm.add(DESKTOP, () => {
    const stage = document.querySelector('[data-stage]');
    if (!stage) return;

    const tabs = gsap.utils.toArray('[data-scene-tab]');
    const frames = gsap.utils.toArray('[data-scene-frame]');
    if (!frames.length) return;

    const show = (i) => {
      tabs.forEach((t, n) => t.setAttribute('data-active', String(n === i)));
      frames.forEach((f, n) => gsap.to(f, { autoAlpha: n === i ? 1 : 0, duration: 0.3, ease: 'power1.out' }));
    };

    gsap.set(frames, { autoAlpha: 0 });
    show(0);

    ScrollTrigger.create({
      trigger: stage,
      start: 'top top',
      end: () => '+=' + frames.length * 62 + '%',
      pin: '[data-stage-inner]',
      anticipatePin: 1,
      invalidateOnRefresh: true,
      onUpdate: (self) => {
        // Clamped so the last scene does not flicker off at progress === 1.
        const i = Math.min(frames.length - 1, Math.floor(self.progress * frames.length));
        show(i);
      },
    });
  });

  /* ---------------------------------------------------------- IMEI spot ---- */
  // The 15 digits arrive one at a time, then sit still. Fifteen elements is well under
  // the point where a stagger starts to feel laggy at the tail.
  const digits = gsap.utils.toArray('[data-imei] span');

  if (digits.length) {
    gsap.from(digits, {
      opacity: 0,
      y: 14,
      duration: 0.4,
      stagger: 0.035,
      ease: 'power2.out',
      scrollTrigger: { trigger: '[data-imei]', start: 'top 80%', once: true },
    });
  }

  /* -------------------------------------------------------------- lenis ---- */
  /*
  | Smooth scroll, desktop pointers only.
  |
  | Never on touch: a phone already has momentum scrolling that the operating system
  | tunes, and replacing it with a JS loop is exactly the "scroll-jacking" the brief
  | rules out. It also fights the browser's own address-bar behaviour.
  |
  | Imported here rather than at module top so a touch visitor who somehow reaches this
  | file never downloads it.
  */
  mm.add(DESKTOP, () => {
    let lenis;
    let frame;

    import('lenis').then(({ default: Lenis }) => {
      lenis = new Lenis({ duration: 1.05, smoothWheel: true, syncTouch: false });
      lenis.on('scroll', ScrollTrigger.update);
      const raf = (t) => {
        lenis.raf(t);
        frame = requestAnimationFrame(raf);
      };
      frame = requestAnimationFrame(raf);
    });

    // matchMedia hands back a cleanup for when the query stops matching — a laptop
    // resized narrow, or a tablet rotated. Without it the rAF loop runs forever
    // against a destroyed layout.
    return () => {
      if (frame) cancelAnimationFrame(frame);
      if (lenis) lenis.destroy();
    };
  });
}
