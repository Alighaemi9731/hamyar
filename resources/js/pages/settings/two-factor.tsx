import { Head, useForm, usePage } from '@inertiajs/react';
import { ShieldCheckIcon, ShieldOffIcon } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AppShell } from '@/layouts/app-shell';
import type { SharedProps } from '@/types';

interface Props {
  enabled: boolean;
  recoveryCodesRemaining: number;
}

interface TwoFactorFlash {
  twoFactorSetup?: { secret: string; uri: string };
  recoveryCodes?: string[];
}

export default function TwoFactorSettings({ enabled, recoveryCodesRemaining }: Props) {
  const page = usePage<SharedProps & TwoFactorFlash>();
  const setup = page.props.twoFactorSetup;
  const recoveryCodes = page.props.recoveryCodes;

  const begin = useForm({ password: '' });
  const confirm = useForm({ code: '' });
  const disable = useForm({ password: '' });

  return (
    <AppShell title="ورود دومرحله‌ای">
      <Head title="ورود دومرحله‌ای" />

      <div className="max-w-2xl space-y-6">
        <div className="flex items-center gap-4 rounded-card border border-border bg-surface p-6 sm:p-7">
          {enabled ? (
            <ShieldCheckIcon className="size-5 text-success" />
          ) : (
            <ShieldOffIcon className="size-5 text-muted-foreground" />
          )}
          <div>
            <p className="text-sm font-medium">{enabled ? 'فعال است' : 'غیرفعال است'}</p>
            <p className="text-sm text-muted-foreground">
              {enabled
                ? `${recoveryCodesRemaining} کد بازیابی باقی مانده است.`
                : 'با فعال‌کردن آن، ورود به حساب نیاز به کد یک‌بارمصرف دارد.'}
            </p>
          </div>
        </div>

        {/* Shown ONCE, on confirmation. We never store a retrievable copy — that is
            what makes a recovery code a real second factor rather than a password. */}
        {recoveryCodes && (
          <div className="rounded-card border border-warning/25 bg-warning/10 p-6 sm:p-7">
            <p className="mb-3 text-sm font-medium text-warning">
              این کدها فقط همین یک‌بار نمایش داده می‌شوند. آن‌ها را جایی امن ذخیره کنید.
            </p>
            <ul className="ltr-value grid grid-cols-2 gap-2 font-mono text-xs" dir="ltr">
              {recoveryCodes.map((code) => (
                <li key={code}>{code}</li>
              ))}
            </ul>
          </div>
        )}

        {!enabled && !setup && (
          <form
            className="space-y-5 rounded-card border border-border bg-surface p-6 sm:p-7"
            onSubmit={(e) => {
              e.preventDefault();
              begin.post('/settings/two-factor');
            }}
          >
            <p className="text-sm leading-relaxed text-muted-foreground">
              برای شروع، رمز عبور فعلی خود را وارد کنید.
            </p>
            <div className="space-y-1.5">
              <Label htmlFor="begin-password">رمز عبور فعلی</Label>
              <Input
                id="begin-password"
                type="password"
                dir="ltr"
                className="ltr-value"
                value={begin.data.password}
                onChange={(e) => begin.setData('password', e.target.value)}
              />
              {begin.errors.password && (
                <p className="text-2xs text-destructive">{begin.errors.password}</p>
              )}
            </div>
            <Button type="submit" disabled={begin.processing}>
              فعال‌سازی ورود دومرحله‌ای
            </Button>
          </form>
        )}

        {setup && (
          <form
            className="space-y-5 rounded-card border border-border bg-surface p-6 sm:p-7"
            onSubmit={(e) => {
              e.preventDefault();
              confirm.post('/settings/two-factor/confirm');
            }}
          >
            <p className="text-sm leading-relaxed text-muted-foreground">
              این کلید را در برنامه احرازهویت خود ثبت کنید، سپس کد ۶ رقمی را وارد کنید.
            </p>
            <code
              className="ltr-value block rounded-control bg-muted p-3 font-mono text-xs"
              dir="ltr"
            >
              {setup.secret}
            </code>
            <div className="space-y-1.5">
              <Label htmlFor="confirm-code">کد تأیید</Label>
              <Input
                id="confirm-code"
                dir="ltr"
                inputMode="numeric"
                className="ltr-value tabular"
                value={confirm.data.code}
                onChange={(e) => confirm.setData('code', e.target.value)}
              />
              {confirm.errors.code && (
                <p className="text-2xs text-destructive">{confirm.errors.code}</p>
              )}
            </div>
            <Button type="submit" disabled={confirm.processing}>
              تأیید و فعال‌سازی
            </Button>
          </form>
        )}

        {enabled && (
          <form
            className="space-y-5 rounded-card border border-border bg-surface p-6 sm:p-7"
            onSubmit={(e) => {
              e.preventDefault();
              disable.delete('/settings/two-factor');
            }}
          >
            <div className="space-y-1.5">
              <Label htmlFor="disable-password">رمز عبور فعلی</Label>
              <Input
                id="disable-password"
                type="password"
                dir="ltr"
                className="ltr-value"
                value={disable.data.password}
                onChange={(e) => disable.setData('password', e.target.value)}
              />
              {disable.errors.password && (
                <p className="text-2xs text-destructive">{disable.errors.password}</p>
              )}
            </div>
            <Button type="submit" variant="destructive" disabled={disable.processing}>
              غیرفعال‌کردن
            </Button>
          </form>
        )}
      </div>
    </AppShell>
  );
}
