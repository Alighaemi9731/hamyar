#!/usr/bin/env node
/**
 * Capture the product screenshots the landing page ships.
 *
 * The landing claims its images come from the running software. On 2026-09-03 that claim
 * was false: the six shipped images were nine days older than a redesign that changed
 * every screen in them, and nobody noticed because re-capturing was a manual afternoon.
 * This makes it one command, and `tests/Feature/LandingShotsTest.php` refuses a manifest
 * that has drifted from the tour.
 *
 * Usage
 *   node scripts/shots/capture.mjs              # every screen in screens.mjs
 *   node scripts/shots/capture.mjs pos imei     # only these ids
 *   SHOTS_BASE_URL=http://app.localhost:8000 node scripts/shots/capture.mjs
 *
 * `bin/shots` is the wrapper that seeds, builds and then runs this.
 *
 * Hostnames: never a literal (golden rule 1b). The base URL is derived from APP_DOMAIN in
 * `.env`, or given by SHOTS_BASE_URL. Chromium resolves any `*.localhost` to loopback
 * itself, so no hosts-file entry is needed locally.
 *
 * Determinism, because a screenshot diff is worthless when the renderer wobbles:
 *   · light theme, forced before first paint through the same localStorage key the
 *     pre-paint script in `app.blade.php` reads;
 *   · `reducedMotion: 'reduce'`, so `.reveal` renders its final state instead of
 *     whatever frame the capture happened to land on;
 *   · `fa-IR` and Asia/Tehran, so Jalali dates and Persian digits are the shop's;
 *   · every shot waits for `document.fonts.ready` AND a witness selector with content,
 *     never for a timeout. `tests/Browser/SmokeTest.php` records what measuring an empty
 *     React root costs.
 */

import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { chromium } from 'playwright';

import { encode } from './encode.mjs';
import { SCREENS, VIEWPORT } from './screens.mjs';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const OUT_DIR = join(ROOT, 'resources/landing/shots');
const RAW_DIR = join(ROOT, 'storage/app/shots');

const OWNER = { mobile: '09121234567', password: 'password' };

/** APP_DOMAIN from .env — the app lives at `app.<apex>` (ADR 0017). */
function baseUrl() {
  if (process.env.SHOTS_BASE_URL) return process.env.SHOTS_BASE_URL.replace(/\/$/, '');

  const envPath = join(ROOT, '.env');

  if (!existsSync(envPath)) {
    throw new Error('No .env and no SHOTS_BASE_URL: nothing says where the app is served.');
  }

  const domain = /^APP_DOMAIN=(.+)$/m.exec(readFileSync(envPath, 'utf8'))?.[1]?.trim();

  if (!domain) {
    throw new Error('APP_DOMAIN is not set in .env, and SHOTS_BASE_URL was not given.');
  }

  return `http://app.${domain}`;
}

function gitSha() {
  try {
    return execFileSync('git', ['rev-parse', 'HEAD'], { cwd: ROOT }).toString().trim();
  } catch {
    return null;
  }
}

async function login(page, base) {
  await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded' });
  await page.locator('#mobile').fill(OWNER.mobile);
  await page.locator('#password').fill(OWNER.password);
  await Promise.all([page.waitForURL(/\/dashboard/, { timeout: 20000 }), page.locator('form button[type="submit"], form button:not([type])').first().click()]);
}

/** A seeded in-stock IMEI, so the till can be photographed with something in the basket. */
async function findImei(page, base) {
  await page.goto(`${base}/inventory/units`, { waitUntil: 'networkidle' });

  return page.evaluate(() => {
    const cells = [...document.querySelectorAll('td, .ltr-value')];
    for (const cell of cells) {
      const text = (cell.textContent ?? '').replace(/[^0-9]/g, '');
      if (text.length === 15) return text;
    }
    return null;
  });
}

async function settle(page, ready) {
  await page.waitForLoadState('networkidle');
  await page.evaluate(() => document.fonts.ready);

  if (ready) {
    await page.locator(ready).first().waitFor({ state: 'visible', timeout: 20000 });
  }

  // Entrance motion is disabled by `reducedMotion`, but a deferred Inertia prop can still
  // land a frame later; one animation frame is cheaper than a blind sleep.
  await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))));
}

async function main() {
  const wanted = process.argv.slice(2).filter((arg) => !arg.startsWith('-'));
  const screens = wanted.length > 0 ? SCREENS.filter((s) => wanted.includes(s.id)) : SCREENS;

  if (screens.length === 0) {
    throw new Error(`No screen matches ${wanted.join(', ')}. Known: ${SCREENS.map((s) => s.id).join(', ')}`);
  }

  const base = baseUrl();
  mkdirSync(RAW_DIR, { recursive: true });
  mkdirSync(OUT_DIR, { recursive: true });

  const browser = await chromium.launch();
  const context = await browser.newContext({
    viewport: VIEWPORT,
    deviceScaleFactor: 2,
    locale: 'fa-IR',
    timezoneId: 'Asia/Tehran',
    colorScheme: 'light',
    reducedMotion: 'reduce',
  });

  // Beat the pre-paint theme script rather than toggling afterwards: a toggle repaints,
  // and a repaint mid-capture is exactly the flicker the script exists to prevent.
  await context.addInitScript(() => {
    try {
      localStorage.setItem('hamyar.theme', 'light');
    } catch {
      /* private mode */
    }
  });

  const page = await context.newPage();
  const consoleErrors = [];
  page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(`${page.url()} — ${message.text()}`);
  });

  console.log(`  base   ${base}`);
  await login(page, base);
  console.log('  login  ok');

  const imei = await findImei(page, base);
  console.log(`  imei   ${imei ?? 'none found — the till will be photographed empty'}`);

  const captured = [];

  for (const screen of screens) {
    await page.goto(`${base}${screen.path}`, { waitUntil: 'domcontentloaded' });
    await settle(page, screen.ready);

    if (screen.prepare) {
      await screen.prepare(page, { imei });
      await settle(page, screen.ready);
    }

    const raw = join(RAW_DIR, `${screen.id}.png`);
    await page.screenshot({ path: raw, scale: 'device' });

    const written = await encode({ raw, outDir: OUT_DIR, id: screen.id, phone: screen.phone, viewport: VIEWPORT });
    captured.push({ id: screen.id, path: screen.path, files: written });
    console.log(`  shot   ${screen.id.padEnd(13)} ${written.join('  ')}`);
  }

  await browser.close();

  // The manifest is what makes staleness detectable: `LandingShotsTest` reads it and
  // fails when the tour references a shot that is not in it, and the sha says which
  // build of the product each image actually shows.
  const manifestPath = join(OUT_DIR, 'manifest.json');
  const previous = existsSync(manifestPath) ? JSON.parse(readFileSync(manifestPath, 'utf8')) : { screens: {} };

  const manifest = {
    captured_at: new Date().toISOString(),
    git_sha: gitSha(),
    viewport: VIEWPORT,
    device_scale_factor: 2,
    theme: 'light',
    locale: 'fa-IR',
    screens: { ...previous.screens },
  };

  for (const entry of captured) {
    manifest.screens[entry.id] = { path: entry.path, files: entry.files, captured_at: manifest.captured_at, git_sha: manifest.git_sha };
  }

  writeFileSync(manifestPath, `${JSON.stringify(manifest, null, 2)}\n`);
  console.log(`  manifest resources/landing/shots/manifest.json (${Object.keys(manifest.screens).length} screens)`);

  if (consoleErrors.length > 0) {
    console.log(`\n  ${consoleErrors.length} console error(s) while capturing — the screens have a defect:`);
    for (const error of consoleErrors.slice(0, 10)) console.log(`    ${error}`);
    process.exitCode = 1;
  }
}

main().catch((error) => {
  console.error(`\n  capture failed: ${error.message}\n`);
  process.exitCode = 1;
});
