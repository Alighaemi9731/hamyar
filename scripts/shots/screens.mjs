/**
 * What the screenshot pipeline captures, and where each shot is used.
 *
 * The landing page claims «تصویرها از خود نرم‌افزار گرفته شده‌اند — نه ماکت، نه طرح», and
 * on 2026-09-03 that claim was false: the six images shipped were captured on 22 August,
 * nine days before a sixteen-phase redesign changed every one of the screens in them. The
 * fix is not "re-capture them once"; it is this file plus `capture.mjs`, so re-capturing
 * is one command and `LandingShotsTest` refuses a manifest that has drifted from the tour.
 *
 * Each entry:
 *   id       the file stem — `resources/landing/shots/<id>.webp`. The tour's `$screens`
 *            array in `resources/views/landing/sections/tour.blade.php` reads these ids;
 *            renaming one here without renaming it there breaks the landing.
 *   path     the route to visit, relative to the app host. Never a hostname — the base
 *            URL comes from APP_DOMAIN (golden rule 1b applies to scripts too).
 *   ready    a CSS selector that must exist AND be non-empty before the shot is taken.
 *            Not a timeout: `tests/Browser/SmokeTest.php` records what measuring an empty
 *            React root costs, and a screenshot of a skeleton is worse than none.
 *   prepare  optional async (page) => {} run after `ready`, for a screen that only shows
 *            its point once something is typed or opened (the till with an empty basket
 *            photographs as an empty basket).
 *   phone    a crop rectangle in CSS pixels for the phone-sized variant, or null to skip
 *            it. The landing currently fakes these with a CSS zoom over the desktop image
 *            (`focus`/`zoom` in the tour), which shows 44% of a 1440px screen inside a
 *            358px frame — the caption promises payment methods and the crop cuts them
 *            off. A real crop of the real region replaces that.
 *   note     why this screen is on the landing at all. If a screen cannot answer this it
 *            does not belong in the tour.
 */

export const VIEWPORT = { width: 1440, height: 900 };

/** Everything the pipeline knows how to capture, in tour order. */
export const SCREENS = [
  {
    id: 'pos',
    path: '/sales/pos',
    ready: '#pos-scan',
    async prepare(page, { imei }) {
      // A till with an empty basket is a picture of nothing. Scan a real seeded IMEI so
      // the invoice has a serialized line, a total and a payment split on it.
      if (!imei) return;
      const box = page.locator('#pos-scan');
      await box.fill(imei);
      await box.press('Enter');
      // The basket row is the witness: wait for it rather than for a duration.
      await page
        .locator('table tbody tr')
        .first()
        .waitFor({ state: 'visible', timeout: 8000 })
        .catch(() => {});
    },
    phone: { x: 430, y: 120, width: 680, height: 760 },
    note: 'The counter itself — the screen a shop lives in all day.',
  },
  {
    id: 'repairs',
    path: '/repairs/board',
    ready: 'main',
    phone: { x: 700, y: 120, width: 420, height: 760 },
    note: 'The workshop board: intake → in repair → ready → abandoned, as cards.',
  },
  {
    id: 'installments',
    path: '/installments/collections',
    ready: 'main',
    phone: null,
    note: 'The collections desk — who owes what today, instead of a paper booklet.',
  },
  {
    id: 'profit',
    path: '/reporting/profit',
    ready: 'main',
    phone: null,
    note: 'True per-device profit, because cost is captured at the moment of sale.',
  },
  {
    id: 'sms',
    path: '/messaging',
    ready: 'main',
    phone: null,
    note: 'Automatic messages fired by the system’s own events, not a list to remember.',
  },
  {
    id: 'imei',
    path: '/inventory/units',
    async prepare(page) {
      // The passport is the product's centre, and it lives one click inside a register.
      const row = page.locator('tbody tr').first();
      if ((await row.count()) === 0) return;
      await row.click();
      await page.waitForLoadState('networkidle');
    },
    ready: 'main',
    phone: { x: 430, y: 100, width: 680, height: 780 },
    note: 'One handset, one row, one history — the claim the whole landing rests on.',
  },
  {
    id: 'dashboard',
    path: '/dashboard',
    ready: 'main',
    phone: { x: 430, y: 100, width: 680, height: 780 },
    note: 'The morning briefing. Not on the tour today; used by the og:image and reviews.',
  },
  {
    id: 'sales',
    path: '/sales',
    ready: 'tbody tr',
    phone: null,
    note: 'The register family at its most ordinary — useful as a device-frame filler.',
  },
  {
    id: 'og',
    host: 'central',
    path: '/design/og',
    ready: '.og__frame img',
    viewport: { width: 1200, height: 630 },
    out: 'resources/landing/og/og.png',
    phone: null,
    note: 'The og:image, rendered from the brand layer and the dashboard capture; a PNG, not a webp — link unfurlers want one.',
  },
];

/** The ids the landing tour actually renders, in its own order. */
export const TOUR_IDS = ['pos', 'repairs', 'installments', 'profit', 'sms', 'imei'];
