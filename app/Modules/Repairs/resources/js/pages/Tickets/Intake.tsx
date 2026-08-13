import { Head, router } from '@inertiajs/react';
import { CameraIcon, CheckCheckIcon, XIcon } from 'lucide-react';
import { useRef, useState } from 'react';

import { Money } from '@/components/domain/money';
import { type PartyOption, PartyPicker } from '@/components/domain/party-picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { AppShell } from '@/layouts/app-shell';
import { useTenantSettings } from '@/hooks/use-tenant-settings';
import { toLatinDigits } from '@/lib/digits';
import { cn } from '@/lib/utils';
import type { MoneyValue } from '@/types';

interface ChecklistItem {
  item_key: string;
  label: string;
  options: string[];
}

interface Props {
  branch: { id: number; name: string };
  technicians: Array<{ id: number; name: string }>;
  checklist: ChecklistItem[];
  accessories: string[];
  approval_cap: MoneyValue;
}

/**
 * قبض پذیرش — taking a device in.
 *
 * ## Written for somebody with a customer standing in front of them
 *
 * That single constraint decides every layout choice here. The shopkeeper is not sitting
 * at a desk; they are at a counter, on a phone or a small tablet, holding somebody
 * else's cracked handset, and the customer is watching them type. Anything that takes a
 * second longer than it needs to gets skipped in real use — and a checklist that gets
 * skipped is the checklist that was supposed to settle «صفحه از قبل شکسته بود» three
 * weeks later.
 *
 * So:
 *
 * - **One column, always.** Not a responsive grid that collapses; the desktop layout is
 *   the phone layout, because the phone is the real one.
 * - **The checklist is tap targets, not selects.** Eight dropdowns is eight taps plus
 *   eight scrolls plus eight confirms. Segmented buttons are one tap each, and the whole
 *   condition of a device is visible at a glance without opening anything.
 * - **«همه سالم» sets them all in one tap**, and then the operator overrides the
 *   exceptions. That is deliberately a positive assertion — one press meaning "I looked
 *   and they are fine" — rather than a default that quietly claims the same thing about
 *   a device nobody examined.
 * - **Photos come straight from the camera.** `capture="environment"` opens the rear
 *   camera directly on a phone rather than a file browser; on a desktop the same input
 *   is an ordinary picker.
 *
 * ## Nothing is pre-answered
 *
 * The checklist starts blank. A form that arrives with every item set to «سالم» produces
 * a signed record asserting a device was in perfect condition, created by nobody looking
 * at it — which is worse than having no checklist, because it looks like evidence.
 */
export default function TicketIntake({
  branch,
  technicians,
  checklist,
  accessories,
  approval_cap: approvalCap,
}: Props) {
  const settings = useTenantSettings();
  const toman = settings.currency_display === 'toman';
  const factor = toman ? 10 : 1;

  const [party, setParty] = useState<PartyOption | null>(null);
  const [answers, setAnswers] = useState<Record<string, string>>({});
  const [notes, setNotes] = useState<Record<string, string>>({});
  const [chosenAccessories, setChosenAccessories] = useState<string[]>([]);
  const [photos, setPhotos] = useState<File[]>([]);
  const [processing, setProcessing] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const [form, setForm] = useState({
    device_brand: '',
    device_model: '',
    device_imei: '',
    device_colour: '',
    reported_issue: '',
    technician_id: '',
    priority: '2',
    promised_at: '',
    estimate: 0,
    prepaid: 0,
    passcode: '',
  });

  const cameraInput = useRef<HTMLInputElement>(null);

  function set<K extends keyof typeof form>(key: K, value: (typeof form)[K]): void {
    setForm((current) => ({ ...current, [key]: value }));
  }

  function money(input: string): number {
    const digits = toLatinDigits(input).replace(/\D/g, '');

    return digits === '' ? 0 : Number(digits) * factor;
  }

  function submit(event: React.FormEvent): void {
    event.preventDefault();
    setProcessing(true);
    setErrors({});

    const payload = new FormData();

    payload.append('branch_id', String(branch.id));
    payload.append('unit', 'rial');
    if (party) payload.append('party_id', String(party.id));
    payload.append('device_brand', form.device_brand);
    payload.append('device_model', form.device_model);
    payload.append('device_imei', form.device_imei);
    payload.append('device_colour', form.device_colour);
    payload.append('reported_issue', form.reported_issue);
    if (form.technician_id) payload.append('technician_id', form.technician_id);
    payload.append('priority', form.priority);
    if (form.promised_at) payload.append('promised_at', form.promised_at);
    payload.append('estimate_amount', String(form.estimate));
    payload.append('prepaid_amount', String(form.prepaid));
    payload.append('device_passcode', form.passcode);

    chosenAccessories.forEach((item, index) => payload.append(`accessories[${index}]`, item));

    // Only answered items are sent. An unanswered row is not "unknown condition" — it is
    // a row nobody looked at, and inventing an answer for it would fabricate evidence.
    checklist
      .filter((item) => answers[item.item_key])
      .forEach((item, index) => {
        payload.append(`checklist[${index}][item_key]`, item.item_key);
        payload.append(`checklist[${index}][label]`, item.label);
        payload.append(`checklist[${index}][answer]`, answers[item.item_key]!);
        if (notes[item.item_key]) {
          payload.append(`checklist[${index}][note]`, notes[item.item_key]!);
        }
      });

    photos.forEach((photo, index) => payload.append(`photos[${index}]`, photo));

    router.post('/repairs/intake', payload, {
      forceFormData: true,
      onError: (received) => setErrors(received as Record<string, string>),
      onFinish: () => setProcessing(false),
    });
  }

  const answered = checklist.filter((item) => answers[item.item_key]).length;

  return (
    <AppShell title="پذیرش دستگاه">
      <Head title="پذیرش دستگاه" />

      {/* Capped narrow even on a desktop: this form is used on a phone, and a
          full-width version of it would be a different screen to learn. */}
      <form onSubmit={submit} className="mx-auto max-w-xl space-y-6 pb-24">
        {/*
          Every error, not just the ones with a field to sit next to. A form that
          redirects back and silently changes nothing is the worst possible outcome with
          a customer standing at the counter — the operator presses the button again,
          harder, and concludes the software is broken. That is exactly what a missing
          `accessories` key did here until a browser pass caught it.
        */}
        {Object.keys(errors).length > 0 && (
          <div
            role="alert"
            data-testid="intake-errors"
            className="space-y-1 rounded-card border border-destructive/40 bg-destructive/5 p-4 text-sm text-destructive"
          >
            <p className="font-medium">پذیرش ثبت نشد:</p>
            <ul className="list-inside list-disc">
              {Object.entries(errors).map(([field, message]) => (
                <li key={field}>{message}</li>
              ))}
            </ul>
          </div>
        )}
        <Section title="مشتری">
          <PartyPicker id="intake-party" value={party} onChange={setParty} kind="customer" />
          <p className="text-2xs text-muted-foreground">
            برای مشتری گذری لازم نیست؛ بدون آن پیامک وضعیت ارسال نمی‌شود.
          </p>
        </Section>

        <Section title="دستگاه">
          <div className="grid grid-cols-2 gap-3">
            <Field label="برند" id="brand">
              <Input
                id="brand"
                value={form.device_brand}
                onChange={(e) => set('device_brand', e.target.value)}
                placeholder="اپل"
              />
            </Field>

            <Field label="مدل" id="model" error={errors.device_model}>
              <Input
                id="model"
                required
                value={form.device_model}
                onChange={(e) => set('device_model', e.target.value)}
                placeholder="آیفون ۱۳"
                aria-invalid={Boolean(errors.device_model)}
              />
            </Field>
          </div>

          <Field label="IMEI" id="imei">
            <Input
              id="imei"
              dir="ltr"
              inputMode="numeric"
              className="tabular"
              value={form.device_imei}
              onChange={(e) => set('device_imei', e.target.value)}
              placeholder="356938035643809"
            />
          </Field>

          <Field label="رنگ" id="colour">
            <Input
              id="colour"
              value={form.device_colour}
              onChange={(e) => set('device_colour', e.target.value)}
            />
          </Field>

          <Field label="ایراد اعلام‌شده مشتری" id="issue" error={errors.reported_issue}>
            <textarea
              id="issue"
              required
              rows={3}
              value={form.reported_issue}
              onChange={(e) => set('reported_issue', e.target.value)}
              className="w-full rounded-control border border-input bg-transparent px-3 py-2 text-base outline-none focus-visible:border-ring"
              placeholder="روشن نمی‌شود، بعد از افتادن در آب"
            />
          </Field>
        </Section>

        <Section
          title="وضعیت دستگاه هنگام تحویل"
          aside={
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() =>
                setAnswers(
                  Object.fromEntries(checklist.map((item) => [item.item_key, item.options[0]!]))
                )
              }
            >
              <CheckCheckIcon className="size-4" aria-hidden />
              همه سالم
            </Button>
          }
        >
          <p className="text-2xs text-muted-foreground">
            این همان چیزی است که سه هفته بعد سر «از قبل شکسته بود» به کار می‌آید.{' '}
            <span className={cn(answered < checklist.length && 'text-warning')}>
              {answered} از {checklist.length} ثبت شد.
            </span>
          </p>

          <ul className="space-y-3">
            {checklist.map((item) => (
              <li key={item.item_key} className="space-y-1.5">
                <span className="text-sm font-medium">{item.label}</span>

                {/* Tap targets, not a select. Eight dropdowns is eight taps plus eight
                    scrolls; this is one tap each and the whole device is visible. */}
                <div className="flex flex-wrap gap-1.5" role="group" aria-label={item.label}>
                  {item.options.map((option) => {
                    const active = answers[item.item_key] === option;

                    return (
                      <button
                        key={option}
                        type="button"
                        aria-pressed={active}
                        onClick={() =>
                          setAnswers((current) => ({ ...current, [item.item_key]: option }))
                        }
                        className={cn(
                          'min-h-11 rounded-pill border px-3 text-sm transition-colors',
                          active
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-border hover:bg-muted'
                        )}
                      >
                        {option}
                      </button>
                    );
                  })}
                </div>

                {answers[item.item_key] && answers[item.item_key] !== item.options[0] && (
                  <Input
                    aria-label={`توضیح ${item.label}`}
                    placeholder="توضیح (اختیاری)"
                    className="h-9"
                    value={notes[item.item_key] ?? ''}
                    onChange={(e) =>
                      setNotes((current) => ({ ...current, [item.item_key]: e.target.value }))
                    }
                  />
                )}
              </li>
            ))}
          </ul>
        </Section>

        <Section title="عکس دستگاه">
          {/* `capture="environment"` opens the rear camera directly on a phone. On a
              desktop the same input is an ordinary file picker. */}
          <input
            ref={cameraInput}
            type="file"
            accept="image/*"
            capture="environment"
            multiple
            className="sr-only"
            onChange={(e) => {
              /*
              | Read the files NOW, not inside the updater.
              |
              | `setPhotos(current => …)` does not run its callback immediately — React
              | queues it and calls it during the next render. The line below clears the
              | input first (deliberately, so the same file can be picked twice in a row),
              | and clearing `value` empties `files`. An updater that read `e.target.files`
              | would therefore read an empty FileList every time, and `photos` would stay
              | empty no matter how many pictures were taken.
              |
              | It failed silently in exactly the way that costs a shop an argument: no
              | thumbnail, no error, and an intake posted with zero photos. Found by
              | walking the screen; every server-side test passed because they build
              | `UploadedFile` arrays in PHP and never touch this handler.
              */
              const picked = Array.from(e.target.files ?? []);

              setPhotos((current) => [...current, ...picked]);
              e.target.value = '';
            }}
          />

          <Button
            type="button"
            variant="outline"
            className="w-full"
            onClick={() => cameraInput.current?.click()}
          >
            <CameraIcon className="size-4" aria-hidden />
            گرفتن عکس
          </Button>

          {photos.length > 0 && (
            <ul className="grid grid-cols-3 gap-2">
              {photos.map((photo, index) => (
                <li key={`${photo.name}-${index}`} className="relative">
                  <img
                    src={URL.createObjectURL(photo)}
                    alt=""
                    className="aspect-square w-full rounded-control object-cover"
                  />
                  <button
                    type="button"
                    aria-label={`حذف عکس ${index + 1}`}
                    onClick={() => setPhotos((current) => current.filter((_, i) => i !== index))}
                    className="absolute end-1 top-1 rounded-full bg-background/90 p-1"
                  >
                    <XIcon className="size-3.5" />
                  </button>
                </li>
              ))}
            </ul>
          )}
        </Section>

        <Section title="همراه دستگاه">
          <div className="flex flex-wrap gap-1.5">
            {accessories.map((item) => {
              const active = chosenAccessories.includes(item);

              return (
                <button
                  key={item}
                  type="button"
                  aria-pressed={active}
                  onClick={() =>
                    setChosenAccessories((current) =>
                      active ? current.filter((a) => a !== item) : [...current, item]
                    )
                  }
                  className={cn(
                    'min-h-11 rounded-pill border px-3 text-sm transition-colors',
                    active
                      ? 'border-primary bg-primary text-primary-foreground'
                      : 'border-border hover:bg-muted'
                  )}
                >
                  {item}
                </button>
              );
            })}
          </div>
        </Section>

        <Section title="رمز دستگاه">
          <Input
            id="passcode"
            dir="ltr"
            autoComplete="off"
            className="tabular"
            value={form.passcode}
            onChange={(e) => set('passcode', e.target.value)}
            placeholder="۱۲۳۴ یا الگو: ۱-۲-۳-۶-۹"
          />
          <p className="text-2xs text-muted-foreground">
            رمزنگاری‌شده ذخیره می‌شود و فقط با دسترسی مخصوص و با ثبت در گزارش فعالیت قابل مشاهده
            است.
          </p>
        </Section>

        <Section title="برآورد و تحویل">
          <div className="grid grid-cols-2 gap-3">
            <Field label="برآورد هزینه" id="estimate">
              <Input
                id="estimate"
                dir="ltr"
                inputMode="numeric"
                className="tabular"
                onChange={(e) => set('estimate', money(e.target.value))}
              />
            </Field>

            <Field label="پیش‌پرداخت" id="prepaid">
              <Input
                id="prepaid"
                dir="ltr"
                inputMode="numeric"
                className="tabular"
                onChange={(e) => set('prepaid', money(e.target.value))}
              />
            </Field>
          </div>

          {form.estimate > approvalCap.value && (
            <p className="rounded-control border border-warning/40 bg-warning/5 px-3 py-2 text-xs text-warning">
              برآورد از سقف مجاز (<Money rial={approvalCap.value} digits="latin" />) بیشتر است؛ تا
              ثبت تأیید مشتری، کار روی دستگاه شروع نمی‌شود.
            </p>
          )}

          <div className="grid grid-cols-2 gap-3">
            <Field label="اولویت" id="priority">
              <Select value={form.priority} onValueChange={(v) => set('priority', v)}>
                <SelectTrigger id="priority" className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent dir="rtl">
                  <SelectItem value="1">فوری</SelectItem>
                  <SelectItem value="2">عادی</SelectItem>
                  <SelectItem value="3">کم‌اولویت</SelectItem>
                </SelectContent>
              </Select>
            </Field>

            <Field label="تعمیرکار" id="technician">
              <Select value={form.technician_id} onValueChange={(v) => set('technician_id', v)}>
                <SelectTrigger id="technician" className="w-full">
                  <SelectValue placeholder="بعداً" />
                </SelectTrigger>
                <SelectContent dir="rtl">
                  {technicians.map((tech) => (
                    <SelectItem key={tech.id} value={String(tech.id)}>
                      {tech.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </Field>
          </div>
        </Section>

        {/* Sticky, because on a phone the submit is otherwise a scroll away from
            wherever the operator finished typing. */}
        <div className="fixed inset-x-0 bottom-0 border-t border-border bg-background/95 p-3 backdrop-blur">
          <div className="mx-auto max-w-xl">
            <Button type="submit" className="h-12 w-full" disabled={processing}>
              ثبت پذیرش و چاپ قبض
            </Button>
          </div>
        </div>
      </form>
    </AppShell>
  );
}

function Section({
  title,
  aside,
  children,
}: {
  title: string;
  aside?: React.ReactNode;
  children: React.ReactNode;
}) {
  return (
    <section className="space-y-3 rounded-card border border-border p-4">
      <div className="flex items-center justify-between gap-2">
        <h2 className="text-sm font-semibold">{title}</h2>
        {aside}
      </div>
      {children}
    </section>
  );
}

function Field({
  label,
  id,
  error,
  children,
}: {
  label: string;
  id: string;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="space-y-2">
      <Label htmlFor={id}>{label}</Label>
      {children}
      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
    </div>
  );
}
