import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { AppShell } from '@/layouts/app-shell';

import { ApiNotice } from './Pending';

interface Step {
  key: string;
  label: string;
  hint: string;
  answer: 'confirmed' | 'skipped' | null;
  note: string | null;
  answered_at: string | null;
  actor: string | null;
}

interface Props {
  unit: {
    id: number;
    imei: string;
    product: string;
    condition: string;
    hamta_status: 'not_required' | 'pending' | 'done';
    hamta_status_label: string;
    activation_id: string | null;
    transferred_at: string | null;
    note: string | null;
    url: string;
  };
  steps: Step[];
  can_manage: boolean;
}

/**
 * The guided transfer checklist for one device.
 *
 * ## «انجام نشد» is a first-class button, not a failure
 *
 * A seller who is not the registered owner, a customer who never forwards the SMS — these
 * happen constantly. A checklist that only records success forces the salesperson to either
 * tick something untrue or abandon the record entirely, and the shop's protection in a
 * dispute is the honest version: we asked, and here is what happened.
 *
 * ## The recorded answers are shown with who and when
 *
 * That attribution IS the evidence. An answer with no name against it protects nobody.
 */
export default function HamtaChecklist({ unit, steps, can_manage: canManage }: Props) {
  const done = unit.hamta_status === 'done';

  return (
    <AppShell title="چک‌لیست همتا">
      <Head title={`چک‌لیست همتا — ${unit.imei}`} />

      <div className="space-y-6">
        <header className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 className="text-2xl font-bold">چک‌لیست انتقال همتا</h1>
            <p className="mt-1 text-sm text-muted-foreground">
              {unit.product} · {unit.condition} ·{' '}
              <span className="font-mono tabular-nums" dir="ltr">
                {unit.imei}
              </span>
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-3">
            <span
              className={`rounded-full px-3 py-1 text-sm ${
                done ? 'bg-success/10 text-success' : 'bg-warning/10 text-warning'
              }`}
            >
              {unit.hamta_status_label}
            </span>
            <Link href={unit.url} className="text-sm text-primary hover:underline">
              شناسنامهٔ دستگاه
            </Link>
          </div>
        </header>

        <ApiNotice />

        <div className="grid gap-6 lg:grid-cols-[2fr_1fr]">
          <StepsForm unitId={unit.id} steps={steps} canManage={canManage} />
          <TransferPanel unit={unit} canManage={canManage} />
        </div>
      </div>
    </AppShell>
  );
}

function StepsForm({
  unitId,
  steps,
  canManage,
}: {
  unitId: number;
  steps: Step[];
  canManage: boolean;
}) {
  const [answers, setAnswers] = useState<Record<string, { answer: string; note: string }>>({});
  const [saving, setSaving] = useState(false);

  const set = (key: string, answer: string) => {
    setAnswers((current) => ({
      ...current,
      [key]: { answer, note: current[key]?.note ?? '' },
    }));
  };

  const note = (key: string, value: string) => {
    setAnswers((current) => ({
      ...current,
      [key]: { answer: current[key]?.answer ?? 'confirmed', note: value },
    }));
  };

  const save = () => {
    setSaving(true);
    router.post(
      `/hamta/${unitId}/checklist`,
      { answers },
      {
        preserveScroll: true,
        onFinish: () => setSaving(false),
        onSuccess: () => setAnswers({}),
      },
    );
  };

  return (
    <section className="space-y-4">
      <ol className="space-y-3">
        {steps.map((step, index) => {
          const pending = answers[step.key];
          const current = pending?.answer ?? step.answer;

          return (
            <li key={step.key} className="rounded-card border p-4">
              <div className="flex gap-3">
                <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-surface-muted text-sm tabular-nums">
                  {index + 1}
                </span>

                <div className="min-w-0 flex-1 space-y-2">
                  <p className="font-medium text-pretty">{step.label}</p>
                  <p className="text-sm text-muted-foreground text-pretty">{step.hint}</p>

                  {step.answered_at ? (
                    <p className="text-xs text-muted-foreground">
                      {step.answer === 'confirmed' ? 'ثبت شد' : 'انجام نشد'} ·{' '}
                      {step.answered_at}
                      {step.actor ? ` · ${step.actor}` : ''}
                      {step.note ? ` · ${step.note}` : ''}
                    </p>
                  ) : null}

                  {canManage ? (
                    <div className="flex flex-wrap items-center gap-2 pt-1">
                      <Button
                        size="sm"
                        variant={current === 'confirmed' ? 'default' : 'outline'}
                        onClick={() => set(step.key, 'confirmed')}
                      >
                        انجام شد
                      </Button>
                      <Button
                        size="sm"
                        variant={current === 'skipped' ? 'default' : 'outline'}
                        onClick={() => set(step.key, 'skipped')}
                      >
                        انجام نشد
                      </Button>

                      {pending ? (
                        <Input
                          value={pending.note}
                          onChange={(event) => note(step.key, event.target.value)}
                          placeholder="توضیح (اختیاری)"
                          className="h-9 max-w-xs"
                          maxLength={500}
                        />
                      ) : null}
                    </div>
                  ) : null}
                </div>
              </div>
            </li>
          );
        })}
      </ol>

      {canManage ? (
        <Button onClick={save} disabled={saving || Object.keys(answers).length === 0}>
          {saving ? 'در حال ثبت…' : 'ثبت پاسخ‌ها'}
        </Button>
      ) : null}
    </section>
  );
}

function TransferPanel({ unit, canManage }: { unit: Props['unit']; canManage: boolean }) {
  const { data, setData, post, processing, errors } = useForm({
    activation_id: unit.activation_id ?? '',
    note: '',
    reopen: false as boolean,
  });

  const submit = (reopen: boolean) => {
    setData('reopen', reopen);

    // The next tick, so `reopen` is in `data` before the request serialises it.
    setTimeout(() => post(`/hamta/${unit.id}/transfer`, { preserveScroll: true }), 0);
  };

  const done = unit.hamta_status === 'done';

  return (
    <aside className="h-fit space-y-4 rounded-card border p-4">
      <h2 className="font-semibold">ثبت انتقال</h2>

      {Object.keys(errors).length > 0 ? (
        <div
          role="alert"
          className="rounded-control border border-danger/25 bg-danger/5 p-3 text-sm text-danger"
        >
          {Object.values(errors).map((message) => (
            <p key={message}>{message}</p>
          ))}
        </div>
      ) : null}

      {done ? (
        <div className="rounded-control bg-success/5 p-3 text-sm">
          <p>
            ثبت‌شده در {unit.transferred_at}
            {unit.activation_id ? (
              <>
                {' '}
                · شناسه:{' '}
                <span className="font-mono" dir="ltr">
                  {unit.activation_id}
                </span>
              </>
            ) : null}
          </p>
          {unit.note ? <p className="mt-1 text-muted-foreground">{unit.note}</p> : null}
        </div>
      ) : null}

      {canManage ? (
        <>
          <div className="grid gap-1.5">
            <Label htmlFor="activation_id">شناسهٔ فعال‌سازی</Label>
            <Input
              id="activation_id"
              value={data.activation_id}
              onChange={(event) => setData('activation_id', event.target.value)}
              dir="ltr"
              className="font-mono"
              maxLength={64}
            />
            <p className="text-xs text-muted-foreground">
              از روی پیامک تأیید مشتری. اختیاری است — اگر انتقال انجام شده ولی پیامک هنوز
              نرسیده، بدون شناسه هم می‌توانید ثبت کنید.
            </p>
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="note">توضیح</Label>
            <Textarea
              id="note"
              value={data.note}
              onChange={(event) => setData('note', event.target.value)}
              rows={3}
              maxLength={1000}
            />
          </div>

          <div className="flex flex-wrap gap-2">
            <Button onClick={() => submit(false)} disabled={processing}>
              {done ? 'به‌روزرسانی ثبت' : 'ثبت انتقال'}
            </Button>

            {done ? (
              <Button variant="outline" onClick={() => submit(true)} disabled={processing}>
                باز کردن دوباره
              </Button>
            ) : null}
          </div>
        </>
      ) : (
        <p className="text-sm text-muted-foreground">
          برای ثبت انتقال به دسترسی «تعدیل موجودی» نیاز دارید.
        </p>
      )}
    </aside>
  );
}
