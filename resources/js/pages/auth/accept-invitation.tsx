import { Head, useForm } from '@inertiajs/react';
import { LoaderIcon } from 'lucide-react';

import { Num } from '@/components/domain/num';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AuthLayout } from '@/layouts/auth-layout';

interface Props {
  token: string;
  name: string;
  mobile: string;
}

export default function AcceptInvitation({ token, name, mobile }: Props) {
  const form = useForm({ token, password: '', password_confirmation: '' });

  /*
   * The token travels in the PATH now, not the body — `tenant.public` reads it as a
   * route parameter to pin the shop that issued the invitation (ADR 0017), and the
   * controller takes it from there and from nowhere else.
   *
   * It stays in the form's SHAPE because the server's "this invitation is invalid or
   * expired" message comes back under the `token` key, and `errors` is typed from the
   * form's own keys — drop it and the message below has nowhere to render.
   *
   * It is stripped from the PAYLOAD because a failed password validation flashes the
   * request body into `sessions.payload` in clear (see App\Support\SensitiveInput), and
   * this token is a live bearer credential — a working invitation link.
   */
  form.transform((data) => ({
    password: data.password,
    password_confirmation: data.password_confirmation,
  }));

  return (
    <AuthLayout title={`${name} عزیز، خوش آمدید`} description="برای ورود، یک رمز عبور انتخاب کنید.">
      <Head title="پذیرش دعوت" />

      <div className="rounded-control bg-muted p-3 text-2xs text-muted-foreground">
        شماره موبایل شما: <Num value={mobile} variant="ltr" />
      </div>

      <form
        className="space-y-5"
        onSubmit={(e) => {
          e.preventDefault();
          form.post(`/invitations/accept/${token}`);
        }}
      >
        {form.errors.token && (
          <p className="rounded-control bg-destructive/10 p-3 text-xs text-destructive">
            {form.errors.token}
          </p>
        )}

        <div className="space-y-1.5">
          <Label htmlFor="password">رمز عبور</Label>
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
          ساخت حساب و ورود
        </Button>
      </form>
    </AuthLayout>
  );
}
