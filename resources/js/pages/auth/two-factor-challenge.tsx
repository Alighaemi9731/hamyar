import { Head, useForm } from '@inertiajs/react';
import { LoaderIcon } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AuthLayout } from '@/layouts/auth-layout';
import { toLatinDigits } from '@/lib/digits';

export default function TwoFactorChallenge() {
  const [useRecovery, setUseRecovery] = useState(false);
  const form = useForm({ code: '', recovery_code: '' });

  return (
    <AuthLayout
      title="تأیید دومرحله‌ای"
      description={
        useRecovery
          ? 'یکی از کدهای بازیابی خود را وارد کنید. هر کد فقط یک‌بار قابل استفاده است.'
          : 'کد ۶ رقمی را از برنامه احرازهویت خود وارد کنید.'
      }
    >
      <Head title="تأیید دومرحله‌ای" />

      <form
        className="space-y-5"
        onSubmit={(e) => {
          e.preventDefault();
          form.post('/two-factor/challenge');
        }}
      >
        {useRecovery ? (
          <div className="space-y-1.5">
            <Label htmlFor="recovery_code">کد بازیابی</Label>
            <Input
              id="recovery_code"
              dir="ltr"
              className="ltr-value"
              autoFocus
              value={form.data.recovery_code}
              onChange={(e) => form.setData('recovery_code', e.target.value)}
            />
          </div>
        ) : (
          <div className="space-y-1.5">
            <Label htmlFor="code">کد تأیید</Label>
            <Input
              id="code"
              dir="ltr"
              inputMode="numeric"
              autoComplete="one-time-code"
              placeholder="۱۲۳۴۵۶"
              className="ltr-value tabular text-center text-lg tracking-widest"
              autoFocus
              value={form.data.code}
              onChange={(e) => form.setData('code', toLatinDigits(e.target.value))}
            />
          </div>
        )}

        {form.errors.code && <p className="text-2xs text-destructive">{form.errors.code}</p>}

        <Button type="submit" className="h-11 w-full text-base" disabled={form.processing}>
          {form.processing && <LoaderIcon className="size-4 animate-spin" />}
          تأیید و ورود
        </Button>

        <button
          type="button"
          className="w-full text-center text-2xs text-muted-foreground underline"
          onClick={() => setUseRecovery((v) => !v)}
        >
          {useRecovery ? 'استفاده از کد برنامه احرازهویت' : 'دسترسی به برنامه ندارم — کد بازیابی'}
        </button>
      </form>
    </AuthLayout>
  );
}
