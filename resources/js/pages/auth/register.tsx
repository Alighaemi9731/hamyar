import { Head, useForm } from '@inertiajs/react';
import { CheckIcon, LoaderIcon, StoreIcon, XIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toLatinDigits } from '@/lib/digits';
import { cn } from '@/lib/utils';

interface Props {
  domain: string;
}

type Availability =
  | { state: 'idle' }
  | { state: 'checking' }
  | { state: 'ok' }
  | { state: 'taken'; reason: string };

/**
 * Shop onboarding.
 *
 * One page rather than a multi-step wizard: it is five fields, and a shop owner
 * abandoning at step 2 of 4 is a lost customer. The subdomain check is live because
 * discovering your shop name is taken *after* submitting is the most annoying way to
 * find out.
 */
export default function Register({ domain }: Props) {
  const form = useForm({
    name: '',
    subdomain: '',
    owner_name: '',
    owner_mobile: '',
    owner_email: '',
    password: '',
    password_confirmation: '',
    accept_terms: false,
  });

  const [availability, setAvailability] = useState<Availability>({ state: 'idle' });
  const subdomain = form.data.subdomain;

  useEffect(() => {
    if (subdomain.length < 3) {
      setAvailability({ state: 'idle' });
      return;
    }

    setAvailability({ state: 'checking' });

    // Debounced: one request per pause in typing, not one per keystroke.
    const timer = window.setTimeout(() => {
      const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

      void fetch('/register/check-subdomain', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
        body: JSON.stringify({ subdomain }),
      })
        .then((response) => response.json() as Promise<{ ok: boolean; reason?: string }>)
        .then((result) => {
          setAvailability(
            result.ok ? { state: 'ok' } : { state: 'taken', reason: result.reason ?? '' }
          );
        })
        .catch(() => setAvailability({ state: 'idle' }));
    }, 400);

    return () => window.clearTimeout(timer);
  }, [subdomain]);

  return (
    <div className="flex min-h-dvh items-center justify-center bg-background px-5 py-16">
      <Head title="ساخت فروشگاه" />

      <div className="w-full max-w-lg">
        <div className="reveal mb-10 flex flex-col items-center gap-3 text-center">
          <span className="flex size-12 items-center justify-center rounded-card bg-primary text-primary-foreground shadow-low">
            <StoreIcon className="size-5" />
          </span>
          <h1 className="text-2xl font-bold">۱۴ روز رایگان شروع کنید</h1>
          <p className="text-sm text-muted-foreground">بدون کارت بانکی. در کمتر از یک دقیقه.</p>
        </div>

        <form
          className="reveal reveal-delay-1 space-y-5 rounded-card border border-border bg-surface p-7 shadow-low sm:p-8"
          onSubmit={(event) => {
            event.preventDefault();
            form.post('/register');
          }}
        >
          <Field label="نام فروشگاه" error={form.errors.name} htmlFor="name">
            <Input
              id="name"
              value={form.data.name}
              onChange={(e) => form.setData('name', e.target.value)}
              placeholder="مثلاً موبایل ایرانیان"
              autoFocus
            />
          </Field>

          <Field label="نشانی فروشگاه" error={form.errors.subdomain} htmlFor="subdomain">
            <div className="flex items-stretch gap-0" dir="ltr">
              <Input
                id="subdomain"
                dir="ltr"
                value={form.data.subdomain}
                onChange={(e) =>
                  form.setData('subdomain', e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''))
                }
                placeholder="iranian-mobile"
                className="ltr-value rounded-e-none text-end"
              />
              <span className="flex items-center rounded-e-control border border-s-0 border-input bg-muted px-3 text-2xs text-muted-foreground">
                .{domain}
              </span>
            </div>

            <AvailabilityHint availability={availability} />
          </Field>

          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="نام شما" error={form.errors.owner_name} htmlFor="owner_name">
              <Input
                id="owner_name"
                value={form.data.owner_name}
                onChange={(e) => form.setData('owner_name', e.target.value)}
              />
            </Field>

            <Field label="شماره موبایل" error={form.errors.owner_mobile} htmlFor="owner_mobile">
              <Input
                id="owner_mobile"
                dir="ltr"
                inputMode="numeric"
                placeholder="09121234567"
                className="ltr-value tabular"
                value={form.data.owner_mobile}
                // Normalised here as well as on the server: a Persian keyboard emits
                // Persian digits and the field would otherwise look wrong as you type.
                onChange={(e) => form.setData('owner_mobile', toLatinDigits(e.target.value))}
              />
            </Field>
          </div>

          <Field
            label="ایمیل (اختیاری)"
            error={form.errors.owner_email}
            htmlFor="owner_email"
          >
            <Input
              id="owner_email"
              type="email"
              dir="ltr"
              className="ltr-value"
              value={form.data.owner_email}
              onChange={(e) => form.setData('owner_email', e.target.value)}
            />
          </Field>

          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="رمز عبور" error={form.errors.password} htmlFor="password">
              <Input
                id="password"
                type="password"
                dir="ltr"
                className="ltr-value"
                value={form.data.password}
                onChange={(e) => form.setData('password', e.target.value)}
              />
            </Field>

            <Field label="تکرار رمز عبور" htmlFor="password_confirmation">
              <Input
                id="password_confirmation"
                type="password"
                dir="ltr"
                className="ltr-value"
                value={form.data.password_confirmation}
                onChange={(e) => form.setData('password_confirmation', e.target.value)}
              />
            </Field>
          </div>

          <label className="flex items-start gap-2 text-2xs text-muted-foreground">
            <input
              type="checkbox"
              className="mt-0.5 size-4 accent-[var(--primary)]"
              checked={form.data.accept_terms}
              onChange={(e) => form.setData('accept_terms', e.target.checked)}
            />
            <span>قوانین و شرایط استفاده و سیاست حریم خصوصی را می‌پذیرم.</span>
          </label>
          {form.errors.accept_terms && (
            <p className="text-2xs text-destructive">{form.errors.accept_terms}</p>
          )}

          <Button type="submit" className="h-11 w-full text-base" disabled={form.processing}>
            {form.processing && <LoaderIcon className="size-4 animate-spin" />}
            ساخت فروشگاه
          </Button>
        </form>
      </div>
    </div>
  );
}

function Field({
  label,
  htmlFor,
  error,
  children,
}: {
  label: string;
  htmlFor: string;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="space-y-1.5">
      <Label htmlFor={htmlFor}>{label}</Label>
      {children}
      {error && <p className="text-2xs text-destructive">{error}</p>}
    </div>
  );
}

function AvailabilityHint({ availability }: { availability: Availability }) {
  if (availability.state === 'idle') {
    return null;
  }

  return (
    <p
      className={cn(
        'flex items-center gap-1 text-2xs',
        availability.state === 'ok' && 'text-success',
        availability.state === 'taken' && 'text-destructive',
        availability.state === 'checking' && 'text-muted-foreground'
      )}
    >
      {availability.state === 'checking' && <LoaderIcon className="size-3 animate-spin" />}
      {availability.state === 'ok' && <CheckIcon className="size-3" />}
      {availability.state === 'taken' && <XIcon className="size-3" />}

      {availability.state === 'checking' && 'در حال بررسی…'}
      {availability.state === 'ok' && 'این نشانی آزاد است.'}
      {availability.state === 'taken' && availability.reason}
    </p>
  );
}
