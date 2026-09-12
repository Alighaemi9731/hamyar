import { Head, useForm } from '@inertiajs/react';
import { KeyRoundIcon } from 'lucide-react';

import { FormErrors } from '@/components/domain/form-errors';
import { PageHeader } from '@/components/domain/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { AppShell } from '@/layouts/app-shell';

interface Props {
  memory_id: string | null;
  economic_code: string | null;
  /**
   * Whether a key is stored — never the key itself.
   *
   * The server does not send it, masked or otherwise: `MoadianSetting` hides it from
   * `toArray()` and the controller does not reach for it. A boolean is all this screen
   * needs, and it is the difference between "somebody could read the key out of an
   * Inertia payload" and "they could not".
   */
  has_private_key: boolean;
  is_enabled: boolean;
  platform_enabled: boolean;
}

export default function Settings({
  memory_id,
  economic_code,
  has_private_key,
  is_enabled,
  platform_enabled,
}: Props) {
  const form = useForm({
    memory_id: memory_id ?? '',
    economic_code: economic_code ?? '',
    // Always blank on arrival. See `has_private_key` above and the controller's note:
    // blank means "unchanged", so this field is safe to submit empty every time.
    private_key: '',
    is_enabled,
  });

  return (
    <AppShell>
      <Head title="تنظیمات مودیان" />

      <PageHeader
        title="سامانهٔ مودیان"
        description="شناسهٔ حافظهٔ مالیاتی و کلید خصوصی فروشگاه. یک بار وارد می‌کنید و بعد از آن کاری ندارید."
      />

      <form
        onSubmit={(event) => {
          event.preventDefault();
          form.put('/moadian/settings', { preserveScroll: true });
        }}
      >
        <Card className="max-w-2xl space-y-6">
          <FormErrors
            errors={form.errors}
            handled={['memory_id', 'economic_code', 'private_key', 'is_enabled']}
          />

          <div className="space-y-2">
            <Label htmlFor="memory_id">شناسهٔ حافظهٔ مالیاتی</Label>
            {/* `ltr-value`: an id is Latin and reorders under bidi without it. */}
            <Input
              id="memory_id"
              className="ltr-value"
              value={form.data.memory_id}
              onChange={(event) => form.setData('memory_id', event.target.value)}
            />
            {form.errors.memory_id ? (
              <p className="text-sm text-danger">{form.errors.memory_id}</p>
            ) : null}
          </div>

          <div className="space-y-2">
            <Label htmlFor="economic_code">کد اقتصادی</Label>
            <Input
              id="economic_code"
              className="ltr-value"
              value={form.data.economic_code}
              onChange={(event) => form.setData('economic_code', event.target.value)}
            />
            {form.errors.economic_code ? (
              <p className="text-sm text-danger">{form.errors.economic_code}</p>
            ) : null}
          </div>

          <div className="space-y-2">
            <Label htmlFor="private_key">کلید خصوصی</Label>
            <textarea
              id="private_key"
              rows={4}
              dir="ltr"
              className="ltr-value w-full rounded-control border border-border bg-background p-3 font-mono text-sm"
              value={form.data.private_key}
              onChange={(event) => form.setData('private_key', event.target.value)}
              placeholder={has_private_key ? '••••••••  (ذخیره شده)' : ''}
            />
            {/*
              The one sentence that makes the blank field safe to leave alone. Without it a
              shopkeeper editing the economic code sees an empty key box and reasonably
              concludes the key is gone.
            */}
            <p className="text-sm text-muted-foreground">
              {has_private_key
                ? 'کلید ذخیره شده است و نمایش داده نمی‌شود. این کادر را خالی بگذارید تا همان کلید بماند؛ فقط برای جایگزینی مقدار تازه وارد کنید.'
                : 'کلید خصوصی را از پنل سامانهٔ مودیان بگیرید. رمزگذاری‌شده ذخیره می‌شود و هرگز دوباره نمایش داده نمی‌شود.'}
            </p>
            {form.errors.private_key ? (
              <p className="text-sm text-danger">{form.errors.private_key}</p>
            ) : null}
          </div>

          <div className="flex items-start gap-3 border-t border-border pt-6">
            <Checkbox
              id="is_enabled"
              checked={form.data.is_enabled}
              onCheckedChange={(checked: boolean | 'indeterminate') =>
                form.setData('is_enabled', checked === true)
              }
            />
            <div className="space-y-1">
              <Label htmlFor="is_enabled">ارسال صورتحساب‌ها به سامانهٔ مودیان</Label>
              {/*
                Two switches, reported apart — the same honesty the submissions screen
                keeps. A shop that turns theirs on while ours is off submits nothing, and
                saying so here is cheaper than the support call that follows silence.
              */}
              <p className="text-sm text-muted-foreground">
                {platform_enabled
                  ? 'صورتحساب‌های تأییدشده پس از ثبت، در صف ارسال قرار می‌گیرند.'
                  : 'ارسال هنوز در سطح سامانه فعال نشده است؛ با روشن کردن این گزینه، فروشگاه شما آمادهٔ ارسال می‌شود و به‌محض فعال شدن، ارسال آغاز می‌شود.'}
              </p>
            </div>
          </div>

          <div className="flex items-center gap-3">
            <Button type="submit" disabled={form.processing}>
              <KeyRoundIcon className="size-4" aria-hidden />
              ذخیرهٔ تنظیمات
            </Button>
            {form.recentlySuccessful ? (
              <span className="text-sm text-success">ذخیره شد.</span>
            ) : null}
          </div>
        </Card>
      </form>
    </AppShell>
  );
}
