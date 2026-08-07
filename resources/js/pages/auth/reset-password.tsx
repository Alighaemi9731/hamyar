import { Head, useForm } from '@inertiajs/react';
import { LoaderIcon } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AuthLayout } from '@/layouts/auth-layout';

interface Props {
  token: string;
  identifier: string;
}

export default function ResetPassword({ token, identifier }: Props) {
  const form = useForm({
    token,
    identifier,
    password: '',
    password_confirmation: '',
  });

  return (
    <AuthLayout title="رمز عبور تازه" description="رمز جدیدی برای حساب خود انتخاب کنید.">
      <Head title="رمز عبور تازه" />

      <form
        className="space-y-5"
        onSubmit={(e) => {
          e.preventDefault();
          form.post('/reset-password');
        }}
      >
        {form.errors.token && (
          <p className="rounded-control bg-destructive/10 p-3 text-xs text-destructive">
            {form.errors.token}
          </p>
        )}

        <div className="space-y-1.5">
          <Label htmlFor="password">رمز عبور جدید</Label>
          <Input
            id="password"
            type="password"
            dir="ltr"
            className="ltr-value"
            autoFocus
            value={form.data.password}
            onChange={(e) => form.setData('password', e.target.value)}
          />
          {form.errors.password && (
            <p className="text-2xs text-destructive">{form.errors.password}</p>
          )}
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="password_confirmation">تکرار رمز عبور</Label>
          <Input
            id="password_confirmation"
            type="password"
            dir="ltr"
            className="ltr-value"
            value={form.data.password_confirmation}
            onChange={(e) => form.setData('password_confirmation', e.target.value)}
          />
        </div>

        <Button type="submit" className="h-11 w-full text-base" disabled={form.processing}>
          {form.processing && <LoaderIcon className="size-4 animate-spin" />}
          تغییر رمز عبور
        </Button>
      </form>
    </AuthLayout>
  );
}
