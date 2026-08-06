import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { LoaderIcon, StoreIcon } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toLatinDigits } from '@/lib/digits';
import type { SharedProps } from '@/types';

/**
 * Per-tenant login. The shop's own name is shown so a user who followed the wrong
 * bookmark notices before typing their password into another shop's page.
 */
export default function Login() {
  const { tenant } = usePage<SharedProps>().props;

  const form = useForm({
    mobile: '',
    password: '',
    remember: false,
  });

  return (
    <div className="flex min-h-dvh items-center justify-center bg-background px-5 py-16">
      <Head title="ورود" />

      <div className="w-full max-w-sm">
        <div className="reveal mb-10 flex flex-col items-center gap-3 text-center">
          <span className="flex size-12 items-center justify-center rounded-card bg-primary text-primary-foreground shadow-low">
            <StoreIcon className="size-5" />
          </span>
          <h1 className="text-2xl font-bold">{tenant?.name ?? 'ورود به پنل فروشگاه'}</h1>
          <p className="text-sm text-muted-foreground">
            برای ادامه، شماره موبایل و رمز عبور خود را وارد کنید.
          </p>
        </div>

        <form
          className="reveal reveal-delay-1 space-y-5 rounded-card border border-border bg-surface p-7 shadow-low sm:p-8"
          onSubmit={(event) => {
            event.preventDefault();
            form.post('/login', { onFinish: () => form.reset('password') });
          }}
        >
          <div className="space-y-1.5">
            <Label htmlFor="mobile">شماره موبایل</Label>
            <Input
              id="mobile"
              name="mobile"
              type="tel"
              dir="ltr"
              inputMode="numeric"
              autoComplete="username"
              placeholder="09121234567"
              className="ltr-value tabular"
              autoFocus
              value={form.data.mobile}
              onChange={(e) => form.setData('mobile', toLatinDigits(e.target.value))}
            />
            {form.errors.mobile && (
              <p className="text-2xs text-destructive">{form.errors.mobile}</p>
            )}
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
              value={form.data.password}
              onChange={(e) => form.setData('password', e.target.value)}
            />
            {form.errors.password && (
              <p className="text-2xs text-destructive">{form.errors.password}</p>
            )}
          </div>

          <div className="flex items-center justify-between gap-3">
            <label className="flex items-center gap-2 text-2xs text-muted-foreground">
            <input
              type="checkbox"
              className="size-4 accent-[var(--primary)]"
              checked={form.data.remember}
              onChange={(e) => form.setData('remember', e.target.checked)}
            />
              مرا به خاطر بسپار
            </label>

            <Link href="/forgot-password" className="text-2xs text-primary">
              رمز عبور را فراموش کرده‌اید؟
            </Link>
          </div>

          <Button type="submit" className="h-11 w-full text-base" disabled={form.processing}>
            {form.processing && <LoaderIcon className="size-4 animate-spin" />}
            ورود
          </Button>
        </form>
      </div>
    </div>
  );
}
