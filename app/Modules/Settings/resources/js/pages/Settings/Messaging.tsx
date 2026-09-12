import { Head, useForm } from '@inertiajs/react';
import { MoonStarIcon } from 'lucide-react';
import type { FormEvent } from 'react';

import { FormErrors } from '@/components/domain/form-errors';
import { Num } from '@/components/domain/num';
import { PageHeader } from '@/components/domain/page-header';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { AppShell } from '@/layouts/app-shell';

interface Automation {
  key: string;
  label: string;
  enabled: boolean;
  /** Date-driven: found by the daily sweep, and the only kind quiet hours bind. */
  swept: boolean;
}

interface Props {
  automations: Automation[];
  quiet: { until_hour: number; from_hour: number };
  can_update: boolean;
}

/** «۰۹:۰۰» — a wall-clock hour, through `<Num>` so it follows the shop's digit setting. */
function Hour({ value }: { value: number }) {
  return <Num value={`${String(value).padStart(2, '0')}:00`} variant="prose" />;
}

const HOURS = Array.from({ length: 24 }, (_, hour) => hour);

/**
 * «پیامک» — which automatic messages the shop sends, and the hours it will not send in.
 *
 * ## Everything starts off, and the screen does not pretend otherwise
 *
 * `MessagingSettings` defaults every automation to OFF and reads `=== true` rather than a
 * truthy check, so that no shop ever wakes up to messages it did not authorise, paid for
 * out of its own SMS credit. A shop that has never been here therefore sees nine unticked
 * boxes — which is the truth about its account, not a screen that failed to load.
 *
 * ## Two groups, because quiet hours only bind one of them
 *
 * The daily sweep asks `isQuietAt()`; the event listeners never do. A repair marked ready
 * at 9pm texts immediately, because the customer is waiting for exactly that message,
 * while a birthday greeting waits for morning. Listing all nine under one quiet-hours
 * control would say something untrue about four of them, so the split is structural rather
 * than decorative — it comes from `AutomationKey::isSwept()`.
 *
 * ## The window is stated back as a sentence
 *
 * Two hour pickers are a rule nobody can read. The line underneath says what the pair
 * currently means, in the shop's own terms, and it updates as the pickers move — so
 * «تا ۲۱ و از ۹» never has to be decoded. The server refuses a window that closes before
 * it opens, because that particular pair silences the shop entirely with nothing on any
 * screen to explain it.
 */
export default function MessagingSettings({ automations, quiet, can_update: canUpdate }: Props) {
  const { data, setData, put, processing, errors, isDirty } = useForm({
    // A list of the keys that are ON. `AutomationKey` values contain a dot, which is
    // Laravel's path separator in a validation rule and in the error bag, so a map keyed
    // by them cannot be validated field by field. The server rebuilds the full map.
    automations: automations.filter((item) => item.enabled).map((item) => item.key),
    quiet_until_hour: quiet.until_hour,
    quiet_from_hour: quiet.from_hour,
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();

    put('/settings/messaging', { preserveScroll: true });
  };

  const toggle = (key: string, on: boolean) =>
    setData(
      'automations',
      on ? [...data.automations, key] : data.automations.filter((value) => value !== key)
    );

  const immediate = automations.filter((item) => !item.swept);
  const swept = automations.filter((item) => item.swept);

  const renderSwitch = (item: Automation) => (
    <Checkbox
      key={item.key}
      checked={data.automations.includes(item.key)}
      disabled={!canUpdate}
      onCheckedChange={(checked) => toggle(item.key, checked === true)}
      label={item.label}
    />
  );

  return (
    <AppShell
      header={
        <PageHeader
          title="پیامک"
          description="هیچ پیامک خودکاری تا وقتی اینجا روشنش نکنید ارسال نمی‌شود. هر پیامک از اعتبار پیامکی فروشگاه شما کم می‌کند."
          back={{ href: '/settings', label: 'تنظیمات' }}
        />
      }
    >
      <Head title="پیامک" />

      <form onSubmit={submit} className="max-w-2xl space-y-6">
        <FormErrors errors={errors} />

        {!canUpdate && (
          <p className="rounded-card border border-border bg-surface p-4 text-sm text-muted-foreground">
            شما این تنظیمات را می‌بینید ولی نمی‌توانید تغییرشان دهید. برای ویرایش، از مالک فروشگاه
            بخواهید دسترسی ویرایش تنظیمات فروشگاه را به نقش شما اضافه کند.
          </p>
        )}

        <SettingsSection
          title="پیامک‌های لحظه‌ای"
          description="همان لحظه‌ای که اتفاق می‌افتند ارسال می‌شوند. ساعت خاموشی روی این‌ها اثری ندارد، چون مشتری منتظر همین خبر است."
        >
          {/* No icon tile beside these. A hue names a destination — it is on the card for
              «پیامک» on the hub — and painting one per section here would turn a settings
              form into a highlighter set. The section headings already say which is which. */}
          <div className="space-y-1">{immediate.map(renderSwitch)}</div>
        </SettingsSection>

        <SettingsSection
          title="پیامک‌های زمان‌بندی‌شده"
          description="روزی یک‌بار بررسی می‌شوند؛ چیزی در لحظه اتفاق نمی‌افتد که آن‌ها را راه بیندازد. ساعت خاموشی فقط روی همین گروه اثر دارد."
        >
          <div className="space-y-6">
            <div className="space-y-1">{swept.map(renderSwitch)}</div>

            <div className="border-t border-border pt-6">
              <div className="min-w-0 space-y-4">
                <div>
                  <h3 className="flex items-center gap-2 text-sm font-semibold">
                    <MoonStarIcon className="size-4 text-muted-foreground" aria-hidden />
                    ساعت خاموشی
                  </h3>
                  <p className="mt-1 text-sm leading-relaxed text-muted-foreground">
                    پیامک نیمه‌شب برای مشتری شما مزاحمت است. بازه‌ای را انتخاب کنید که فروشگاه حاضر
                    است در آن پیام بفرستد.
                  </p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                  <div className="space-y-1.5">
                    <Label htmlFor="quiet_until_hour">ساعت شروع ارسال</Label>
                    <Select
                      value={String(data.quiet_until_hour)}
                      disabled={!canUpdate}
                      onValueChange={(value) => setData('quiet_until_hour', Number(value))}
                    >
                      <SelectTrigger id="quiet_until_hour" className="w-full">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {HOURS.map((hour) => (
                          <SelectItem key={hour} value={String(hour)} textValue={String(hour)}>
                            <Hour value={hour} />
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>

                  <div className="space-y-1.5">
                    <Label htmlFor="quiet_from_hour">ساعت پایان ارسال</Label>
                    <Select
                      value={String(data.quiet_from_hour)}
                      disabled={!canUpdate}
                      onValueChange={(value) => setData('quiet_from_hour', Number(value))}
                    >
                      <SelectTrigger id="quiet_from_hour" className="w-full">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {HOURS.map((hour) => (
                          <SelectItem key={hour} value={String(hour)} textValue={String(hour)}>
                            <Hour value={hour} />
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                </div>

                {/* The pair, said back as a sentence. Two numbers on their own are a rule
                      the reader has to decode; this is the rule already decoded, and it
                      moves when the pickers move. */}
                <p className="rounded-control bg-muted/50 p-3 text-sm leading-relaxed">
                  {data.quiet_from_hour > data.quiet_until_hour ? (
                    <>
                      پیامک‌های زمان‌بندی‌شده فقط بین <Hour value={data.quiet_until_hour} /> و{' '}
                      <Hour value={data.quiet_from_hour} /> ارسال می‌شوند. بیرون از این بازه، ارسال
                      تا اولین ساعت مجاز به تعویق می‌افتد.
                    </>
                  ) : (
                    <span className="text-danger">
                      ساعت پایان باید بعد از ساعت شروع باشد. با این دو عدد هیچ پیامک
                      زمان‌بندی‌شده‌ای ارسال نخواهد شد.
                    </span>
                  )}
                </p>
              </div>
            </div>
          </div>
        </SettingsSection>

        {canUpdate && (
          <div className="flex items-center gap-3">
            <Button type="submit" disabled={processing}>
              {processing ? 'در حال ذخیره' : 'ذخیره تنظیمات پیامک'}
            </Button>

            {isDirty && !processing && (
              <span className="text-2xs text-muted-foreground">تغییرات هنوز ذخیره نشده است.</span>
            )}
          </div>
        )}
      </form>
    </AppShell>
  );
}
