import { useSyncExternalStore } from 'react';

export type Theme = 'light' | 'dark';

/** The key `app.blade.php`'s pre-paint script reads. Both sides must agree. */
const STORAGE_KEY = 'hamyar.theme';

/**
 * The app's theme, read from the one place that actually decides it.
 *
 * ## Why this exists instead of `next-themes`
 *
 * This app already has a complete theme mechanism and it is not a React one. The inline
 * script in `app.blade.php` sets `class="dark"` on `<html>` before first paint — from
 * `localStorage['hamyar.theme']`, falling back to `prefers-color-scheme` — because a theme
 * decided during hydration is a theme the user watches flash. `ThemeToggle` then flips that
 * same class. The document element is the source of truth; React is downstream of it.
 *
 * `next-themes` was a dependency for exactly one consumer, `ui/sonner.tsx`, which called
 * `useTheme()` — and **no `ThemeProvider` was ever mounted**. Without one, next-themes'
 * `useTheme()` returns `{ setTheme() {}, themes: [] }`: an object with no `theme` key at
 * all. So `const { theme = 'system' } = useTheme()` fell to its default on every render,
 * and sonner treats `'system'` as "read `prefers-color-scheme`".
 *
 * The result a shopkeeper met: a phone with a light OS, Hamyar switched to dark, a black
 * page — and white toasts. Nothing errored, nothing logged, and the toast was the one
 * surface in the product that never followed the switch.
 *
 * Mounting `ThemeProvider` would have fixed the symptom by installing a *second* theme
 * authority next to the first — its own storage key, its own class management, racing the
 * pre-paint script that exists precisely to win that race. One authority is the fix; this
 * hook is how React reads it.
 *
 * ## Why a MutationObserver rather than shared state
 *
 * Whoever changes the class is observed — the toggle, the pre-paint script, a future
 * settings screen, or devtools. A React store would only see writes that went through
 * React, which is not where this app's theme lives.
 */
function subscribe(onStoreChange: () => void): () => void {
  const observer = new MutationObserver(onStoreChange);

  observer.observe(document.documentElement, {
    attributes: true,
    attributeFilter: ['class'],
  });

  return () => observer.disconnect();
}

function getSnapshot(): Theme {
  return document.documentElement.classList.contains('dark') ? 'dark' : 'light';
}

/**
 * SSR is off (`config/inertia.php`), but `useSyncExternalStore` demands this whenever the
 * snapshot touches the DOM, and leaving it out is how the hook breaks the day SSR is turned
 * on rather than the day it is written. Light matches the `:root` default in `app.css`, so
 * a server render agrees with a first paint that has nothing stored.
 */
function getServerSnapshot(): Theme {
  return 'light';
}

export function useTheme(): Theme {
  return useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
}

/**
 * Switch the theme, and persist it for the pre-paint script to find next time.
 *
 * Writing the class is what notifies every `useTheme()` caller — via the observer above —
 * so nothing here has to know who is listening.
 */
export function setTheme(next: Theme): void {
  document.documentElement.classList.toggle('dark', next === 'dark');

  try {
    localStorage.setItem(STORAGE_KEY, next);
  } catch {
    // Private browsing: the theme applies now and just will not survive a reload.
  }
}
