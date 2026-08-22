/*
 | MobiYar landing — section 4, «شناسنامهٔ IMEI».
 |
 | The whole of the section's behaviour, and deliberately none of the page's.
 |
 | ## What it does NOT do
 |
 | No scroll listener, no IntersectionObserver, no resize handler, no timers. The page's
 | motion budget (one observer for `.rise`, one throttled scroll handler for the spine)
 | belongs to landing.js and is untouched here. This file reacts to a click and a
 | keystroke, and between those it costs nothing.
 |
 | ## What happens if it never arrives
 |
 | The section still works as a section. Blade renders all three records and leaves the
 | first one open, so a visitor with JS blocked, broken, or still downloading reads a
 | complete handset file rather than an empty frame. That is not a nicety: the rejected
 | dark direction (ADR 0016) drove its signature object entirely from script and shipped
 | a blank sheet of paper to anyone who arrived before it loaded.
 |
 | The honest cost is that the two unselected picker buttons do nothing without this
 | file. They are real `<button type="button">` elements so they are inert rather than
 | wrong — a link to nowhere would be worse.
 */

const stage = document.querySelector('[data-imei-stage]');

if (stage) {
  const panels = [...stage.querySelectorAll('[data-imei-panel]')];
  const miss = stage.querySelector('[data-imei-miss]');
  const say = stage.querySelector('[data-imei-say]');
  const input = document.querySelector('[data-imei-input]');
  const buttons = [...document.querySelectorAll('[data-imei-pick]')];

  /*
   | There is deliberately no `matchMedia('(prefers-reduced-motion: reduce)')` read in
   | this file. There used to be one, and it gated the `data-fresh` write — which meant
   | the stylesheet's reduced-motion branch, the 140ms fade written to replace the
   | timeline cascade, could never run: the attribute it hangs off was never written for
   | exactly the users it was written for. They got an instant, unannounced swap, i.e.
   | the one outcome the branch existed to prevent.
   |
   | The attribute is a statement of FACT — "this record was just chosen" — not a
   | request for an animation. Facts belong in the markup and policy belongs in the
   | stylesheet, where `@media (prefers-reduced-motion: reduce)` already lives and
   | cannot fall out of step with a boolean read once at load. Note the old read was
   | also stale by construction: a visitor who turns the preference on after the page
   | loads kept the cascade, because nothing was listening for the change.
   */

  /*
   | Persian (U+06F0) and Arabic-Indic (U+0660) digits both land on a numeric keypad in
   | Iran depending on the keyboard, and a pasted serial can carry either. They are the
   | same number; the matcher should not care which shape arrived.
   */
  const latin = (value) =>
    value
      .replace(/[۰-۹]/g, (d) => String.fromCharCode(d.charCodeAt(0) - 0x06f0 + 48))
      .replace(/[٠-٩]/g, (d) => String.fromCharCode(d.charCodeAt(0) - 0x0660 + 48))
      .replace(/\D/g, '');

  /**
   * Show one record.
   *
   * `fresh` marks the panel as just-chosen; what that looks like is the stylesheet's
   * decision — the timeline cascade normally, a 140ms fade of the whole record under
   * reduced motion. Removing and re-adding the attribute in the same frame does nothing
   * on its own — the browser coalesces both writes and the animation never restarts —
   * so the layout read between them is load-bearing, not a leftover, and it is needed
   * for either branch.
   */
  const show = (panel, fresh) => {
    for (const other of panels) {
      other.hidden = other !== panel;
      other.removeAttribute('data-fresh');
    }

    if (miss) miss.hidden = true;

    for (const button of buttons) {
      button.setAttribute('aria-pressed', String(button.dataset.imeiPick === panel.dataset.imei));
    }

    if (fresh) {
      void panel.offsetWidth; // force a layout so the animation is re-entered
      panel.setAttribute('data-fresh', '');
    }

    if (say && fresh) {
      say.textContent = `پروندهٔ ${panel.dataset.imeiName} نمایش داده شد.`;
    }
  };

  const showMiss = () => {
    for (const panel of panels) {
      panel.hidden = true;
      panel.removeAttribute('data-fresh');
    }
    for (const button of buttons) button.setAttribute('aria-pressed', 'false');
    if (miss) miss.hidden = false;
    if (say) say.textContent = 'این شناسه بین نمونه‌ها پیدا نشد.';
  };

  /* Clicking a sample also fills the field, so the two controls read as one control:
     the button is a shortcut for typing, not a separate mechanism. */
  for (const button of buttons) {
    button.addEventListener('click', () => {
      const panel = panels.find((p) => p.dataset.imei === button.dataset.imeiPick);
      if (!panel) return;
      if (input) input.value = panel.dataset.imei;
      show(panel, true);
    });
  }

  if (input) {
    input.addEventListener('input', () => {
      const digits = latin(input.value);

      /* An empty field is not a failed search — it is a visitor who cleared it. Fall
         back to the first record rather than accusing them of a typo. */
      if (!digits) {
        show(panels[0], true);
        return;
      }

      const hit = panels.find((panel) => panel.dataset.imei.startsWith(digits));
      if (hit) {
        show(hit, hit.hidden);
      } else {
        showMiss();
      }
    });
  }
}
