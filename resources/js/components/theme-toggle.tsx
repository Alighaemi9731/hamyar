import { MoonIcon, SunIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';

const STORAGE_KEY = 'mobishop.theme';

/**
 * Light/dark switch.
 *
 * The initial class is applied by an inline script in app.blade.php before first
 * paint; this component only keeps React's idea of the theme in sync with the DOM
 * so the icon is right. Re-deriving it here would cause a flash on every page load.
 */
export function ThemeToggle() {
  const [dark, setDark] = useState(false);

  useEffect(() => {
    setDark(document.documentElement.classList.contains('dark'));
  }, []);

  function toggle() {
    const next = !dark;

    document.documentElement.classList.toggle('dark', next);

    try {
      localStorage.setItem(STORAGE_KEY, next ? 'dark' : 'light');
    } catch {
      // Private browsing: the theme just will not persist across reloads.
    }

    setDark(next);
  }

  return (
    <Button
      variant="ghost"
      size="icon"
      onClick={toggle}
      aria-label={dark ? 'حالت روشن' : 'حالت تیره'}
      title={dark ? 'حالت روشن' : 'حالت تیره'}
    >
      {dark ? <SunIcon className="size-4" /> : <MoonIcon className="size-4" />}
    </Button>
  );
}
