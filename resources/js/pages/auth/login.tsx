import { Head } from '@inertiajs/react';
import { StoreIcon } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * Login placeholder.
 *
 * The real per-tenant auth flow (rate limits, 2FA, password reset, remember-me) is
 * Phase 1.4. This page exists so the auth layout, RTL form rules and the LTR-input
 * convention are settled before the flow is built on top of them.
 */
export default function Login() {
  return (
    <div className="flex min-h-dvh items-center justify-center bg-background px-4 py-10">
      <Head title="ورود" />

      <div className="w-full max-w-sm">
        <div className="mb-6 flex flex-col items-center gap-2 text-center">
          <span className="flex size-11 items-center justify-center rounded-card bg-primary text-primary-foreground">
            <StoreIcon className="size-5" />
          </span>
          <h1 className="text-lg">ورود به پنل فروشگاه</h1>
          <p className="text-xs text-muted-foreground">
            برای ادامه، شماره موبایل و رمز عبور خود را وارد کنید.
          </p>
        </div>

        <form
          className="space-y-4 rounded-card border border-border bg-surface p-6 shadow-low"
          onSubmit={(event) => event.preventDefault()}
        >
          <div className="space-y-1.5">
            <Label htmlFor="mobile">شماره موبایل</Label>
            {/* Phone numbers are inherently LTR: the label layout stays RTL while the
                value reads left-to-right (design-system rule 3). */}
            <Input
              id="mobile"
              name="mobile"
              type="tel"
              dir="ltr"
              inputMode="numeric"
              autoComplete="username"
              placeholder="09121234567"
              className="ltr-value tabular"
            />
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="password">رمز عبور</Label>
            <Input
              id="password"
              name="password"
              type="password"
              dir="ltr"
              autoComplete="current-password"
              className="ltr-value"
            />
          </div>

          <Button type="submit" className="w-full">
            ورود
          </Button>

          <p className="text-center text-2xs text-muted-foreground">
            فروشگاه ندارید؟ <span className="text-primary">ثبت‌نام ۱۴ روز رایگان</span>
          </p>
        </form>
      </div>
    </div>
  );
}
