import { MoonIcon, SunIcon } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { setTheme, useTheme } from '@/hooks/use-theme';

/**
 * Light/dark switch.
 *
 * The initial class is applied by an inline script in `app.blade.php` before first paint;
 * this component only flips it. Re-deriving the theme here would cause a flash on every
 * page load.
 *
 * It reads through `useTheme()` rather than keeping its own `useState` copy, because it was
 * not the only thing that needed to know: the toaster does too, and a private copy is why
 * the two disagreed. One authority — the class on `<html>` — and everything reads it.
 */
export function ThemeToggle() {
  const theme = useTheme();
  const dark = theme === 'dark';

  return (
    <Button
      variant="ghost"
      size="icon"
      onClick={() => setTheme(dark ? 'light' : 'dark')}
      aria-label={dark ? 'حالت روشن' : 'حالت تیره'}
      title={dark ? 'حالت روشن' : 'حالت تیره'}
    >
      {dark ? <SunIcon className="size-4" /> : <MoonIcon className="size-4" />}
    </Button>
  );
}
