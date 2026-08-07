import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { LoaderIcon } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AuthLayout } from '@/layouts/auth-layout';
import { toLatinDigits } from '@/lib/digits';
import type { SharedProps } from '@/types';

export default function ForgotPassword() {
  const { flash } = usePage<SharedProps>().props;
  const form = useForm({ identifier: '' });

  return (
    <AuthLayout
      title="بازیابی رمز عبور"
      description="شماره موبایل خود را وارد کنید تا لینک بازیابی برایتان ارسال شود."
      footer={
        <Link href="/login" className="text-primary">
          بازگشت به ورود
        </Link>
      }
    >
      <Head title="بازیابی رمز عبور" />

      {/* Deliberately the same confirmation whether or not the account exists —
          anything else turns this form into an oracle for who works at the shop. */}
      {flash.success && (
        <p className="rounded-control bg-success/10 p-3 text-xs text-success">{flash.success}</p>
      )}

      <form
        className="space-y-5"
        onSubmit={(e) => {
          e.preventDefault();
          form.post('/forgot-password');
        }}
      >
        <div className="space-y-1.5">
          <Label htmlFor="identifier">شماره موبایل</Label>
          <Input
            id="identifier"
            dir="ltr"
            inputMode="numeric"
            placeholder="09121234567"
            className="ltr-value tabular"
            autoFocus
            value={form.data.identifier}
            onChange={(e) => form.setData('identifier', toLatinDigits(e.target.value))}
          />
          {form.errors.identifier && (
            <p className="text-2xs text-destructive">{form.errors.identifier}</p>
          )}
        </div>

        <Button type="submit" className="h-11 w-full text-base" disabled={form.processing}>
          {form.processing && <LoaderIcon className="size-4 animate-spin" />}
          ارسال لینک بازیابی
        </Button>
      </form>
    </AuthLayout>
  );
}
