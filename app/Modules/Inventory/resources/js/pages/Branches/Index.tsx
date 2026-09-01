import { Head, router, useForm } from '@inertiajs/react';
import { BuildingIcon, PlusIcon, UsersIcon } from 'lucide-react';
import { useState } from 'react';

import { EmptyState } from '@/components/domain/empty-state';
import { FormErrors } from '@/components/domain/form-errors';
import { Num } from '@/components/domain/num';
import { PageHeader } from '@/components/domain/page-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card } from '@/components/ui/card';
import { AppShell } from '@/layouts/app-shell';

interface Branch {
  id: number;
  name: string;
  code: string;
  phone: string | null;
  address: string | null;
  is_default: boolean;
  is_active: boolean;
  warehouses: { id: number; name: string }[];
  user_ids: number[];
}

interface Props {
  branches: Branch[];
  users: { id: number; name: string }[];
  can_manage: boolean;
}

/**
 * Branches, and who works at each one.
 *
 * ## The empty assignment list states the rule instead of showing nothing
 *
 * No rows in `branch_user` means "this person sees every branch" — the right default for a
 * single-branch shop, which must not have to assign anybody to anything. On a screen it
 * reads as missing data unless the screen says otherwise, so the unassigned case is a
 * sentence rather than an empty box.
 *
 * ## The default branch cannot be switched off
 *
 * Documents that reach finalisation without a branch of their own fall back to it, so the
 * control is disabled rather than hidden — hiding it would leave somebody looking for a
 * checkbox they remember seeing on the other branches.
 */
export default function BranchesIndex({ branches, users, can_manage: canManage }: Props) {
  const [creating, setCreating] = useState(false);

  return (
    <AppShell
      header={
        <PageHeader
          title="شعبه‌ها"
          description="هر شعبه شماره‌گذاری اسناد و سربرگ چاپ خودش را دارد. کارکنانی که به یک شعبه اختصاص داده شوند فقط اطلاعات همان شعبه‌ها را می‌بینند؛ کسی که به هیچ شعبه‌ای اختصاص داده نشده، همه شعب را می‌بیند."
          actions={
            canManage ? (
              <Button onClick={() => setCreating((open) => !open)}>
                <PlusIcon aria-hidden />
                شعبه جدید
              </Button>
            ) : null
          }
        />
      }
    >
      <Head title="شعبه‌ها" />

      <div className="space-y-8">
        {creating ? <BranchForm onDone={() => setCreating(false)} /> : null}

        {branches.length === 0 ? (
          <EmptyState
            icon={BuildingIcon}
            title="هنوز شعبه‌ای ثبت نشده است"
            description="اولین شعبه هنگام ساخت فروشگاه ساخته می‌شود. اگر اینجا خالی است، با پشتیبانی تماس بگیرید."
          />
        ) : (
          <div className="space-y-4">
            {branches.map((branch) => (
              <BranchCard key={branch.id} branch={branch} users={users} canManage={canManage} />
            ))}
          </div>
        )}
      </div>
    </AppShell>
  );
}

function BranchCard({
  branch,
  users,
  canManage,
}: {
  branch: Branch;
  users: Props['users'];
  canManage: boolean;
}) {
  const [editing, setEditing] = useState(false);
  const [staffing, setStaffing] = useState(false);

  return (
    <Card asChild padding="lg">
      <section>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <h2 className="flex flex-wrap items-center gap-2 font-semibold">
              {branch.name}
              <span className="rounded-full border border-border px-2 py-0.5 text-xs">
                <Num value={branch.code} variant="ltr" />
              </span>
              {branch.is_default ? (
                <span className="rounded-full bg-primary/10 px-2 py-0.5 text-xs text-primary">
                  پیش‌فرض
                </span>
              ) : null}
              {branch.is_active ? null : (
                <span className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                  غیرفعال
                </span>
              )}
            </h2>

            <p className="mt-1 text-sm text-muted-foreground">
              {branch.address || 'بدون آدرس'}
              {branch.phone ? (
                <>
                  {' · '}
                  <span dir="ltr">{branch.phone}</span>
                </>
              ) : null}
            </p>

            <p className="mt-2 text-sm text-muted-foreground">
              {branch.warehouses.length > 0
                ? `انبارها: ${branch.warehouses.map((w) => w.name).join('، ')}`
                : 'بدون انبار'}
            </p>

            <p className="mt-1 text-sm">
              {branch.user_ids.length === 0 ? (
                <span className="text-muted-foreground">
                  کسی به این شعبه اختصاص داده نشده — یعنی همهٔ کاربران آن را می‌بینند.
                </span>
              ) : (
                <>
                  <UsersIcon className="inline size-4 align-text-bottom" aria-hidden />{' '}
                  {/* Persian prose counts in Persian — design-system rule 4. This was a bare
                    Latin numeral in the middle of a Persian sentence. */}
                  <Num value={branch.user_ids.length} variant="prose" /> کارمند اختصاصی
                </>
              )}
            </p>
          </div>

          {canManage ? (
            <div className="flex shrink-0 gap-2">
              {/*
              Not `size="sm"`. These were 28px, and with twenty branches on screen that is
              forty controls under the floor on one page — the two things anybody comes to
              this screen to press.
            */}
              <Button
                variant="outline"
                aria-expanded={editing}
                onClick={() => setEditing((v) => !v)}
              >
                ویرایش
              </Button>
              <Button
                variant="outline"
                aria-expanded={staffing}
                onClick={() => setStaffing((v) => !v)}
              >
                کارکنان
              </Button>
            </div>
          ) : null}
        </div>

        {editing ? (
          <div className="mt-5 border-t pt-5">
            <BranchForm branch={branch} onDone={() => setEditing(false)} />
          </div>
        ) : null}

        {staffing ? (
          <div className="mt-5 border-t border-border pt-5">
            <StaffForm branch={branch} users={users} onDone={() => setStaffing(false)} />
          </div>
        ) : null}
      </section>
    </Card>
  );
}

function BranchForm({ branch, onDone }: { branch?: Branch; onDone: () => void }) {
  const { data, setData, post, put, processing, errors } = useForm({
    name: branch?.name ?? '',
    code: branch?.code ?? '',
    phone: branch?.phone ?? '',
    address: branch?.address ?? '',
    is_active: branch?.is_active ?? true,
    is_default: branch?.is_default ?? false,
  });

  const submit = (event: React.FormEvent) => {
    event.preventDefault();

    const options = { preserveScroll: true, onSuccess: onDone };

    if (branch) {
      put(`/branches/${branch.id}`, options);
    } else {
      post('/branches', options);
    }
  };

  return (
    <form onSubmit={submit} className="space-y-4">
      {/* Every key of the error bag, not only the ones with an input beside them — a
          failure that renders nowhere makes the submit button look broken. This was a
          hand-rolled copy of the shared region that also printed `quota`, which the shell
          already renders through `<QuotaBlock>` with an upgrade button. */}
      <FormErrors errors={errors} />

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="grid gap-1.5">
          <Label htmlFor={`name-${branch?.id ?? 'new'}`}>نام شعبه</Label>
          <Input
            id={`name-${branch?.id ?? 'new'}`}
            value={data.name}
            onChange={(e) => setData('name', e.target.value)}
            maxLength={120}
          />
        </div>

        <div className="grid gap-1.5">
          <Label htmlFor={`code-${branch?.id ?? 'new'}`}>کد شعبه</Label>
          {/* Inherently LTR: it goes into a document number read left to right. */}
          <Input
            id={`code-${branch?.id ?? 'new'}`}
            value={data.code}
            onChange={(e) => setData('code', e.target.value.toUpperCase())}
            dir="ltr"
            maxLength={10}
            className="font-mono"
          />
          <p className="text-xs text-muted-foreground">
            روی شمارهٔ فاکتورهای همین شعبه می‌نشیند. بعداً تغییرش ندهید.
          </p>
        </div>

        <div className="grid gap-1.5">
          <Label htmlFor={`phone-${branch?.id ?? 'new'}`}>تلفن</Label>
          <Input
            id={`phone-${branch?.id ?? 'new'}`}
            value={data.phone}
            onChange={(e) => setData('phone', e.target.value)}
            dir="ltr"
          />
        </div>

        <div className="grid gap-1.5">
          <Label htmlFor={`address-${branch?.id ?? 'new'}`}>آدرس</Label>
          <Input
            id={`address-${branch?.id ?? 'new'}`}
            value={data.address}
            onChange={(e) => setData('address', e.target.value)}
          />
        </div>
      </div>

      <div className="flex flex-wrap gap-6">
        <Checkbox
          checked={data.is_active}
          disabled={branch?.is_default}
          onCheckedChange={(checked) => setData('is_active', checked === true)}
          label="فعال"
          description={branch?.is_default ? 'شعبهٔ پیش‌فرض همیشه فعال است.' : undefined}
        />

        <Checkbox
          checked={data.is_default}
          disabled={branch?.is_default}
          onCheckedChange={(checked) => setData('is_default', checked === true)}
          label="شعبهٔ پیش‌فرض"
        />
      </div>

      <div className="flex gap-2">
        <Button type="submit" disabled={processing}>
          {processing ? 'در حال ذخیره…' : 'ذخیره'}
        </Button>
        <Button type="button" variant="ghost" onClick={onDone}>
          انصراف
        </Button>
      </div>
    </form>
  );
}

function StaffForm({
  branch,
  users,
  onDone,
}: {
  branch: Branch;
  users: Props['users'];
  onDone: () => void;
}) {
  const [selected, setSelected] = useState<number[]>(branch.user_ids);
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const toggle = (id: number) => {
    setSelected((current) =>
      current.includes(id) ? current.filter((value) => value !== id) : [...current, id]
    );
  };

  /*
  | This posted with no `onError` and no region at all.
  |
  | It decides which staff can see which branch's data — a refusal here is a permission
  | change that did not happen, and it came back as a redirect that re-rendered an
  | identical set of ticked boxes. The operator saw their own ticks, assumed it saved, and
  | the person they meant to restrict kept seeing everything.
  */
  const save = () => {
    setSaving(true);
    setErrors({});

    router.put(
      `/branches/${branch.id}/users`,
      { user_ids: selected },
      {
        preserveScroll: true,
        onSuccess: onDone,
        onError: (received) => setErrors(received as Record<string, string>),
        onFinish: () => setSaving(false),
      }
    );
  };

  return (
    <div className="space-y-4">
      <FormErrors errors={errors} />

      <p className="text-sm text-muted-foreground">
        کاربرانی که اینجا تیک بخورند به این شعبه محدود می‌شوند. اگر کاربری به هیچ شعبه‌ای اختصاص
        نداشته باشد، همهٔ شعب را می‌بیند — پس برای فروشگاه تک‌شعبه‌ای نیازی به تیک زدن نیست.
      </p>

      <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        {users.map((user) => (
          // Not a `<label>` any more: `Checkbox` renders its own, and a label inside a
          // label is invalid and makes the click target ambiguous.
          <div key={user.id} className="rounded-control border border-border px-2">
            <Checkbox
              checked={selected.includes(user.id)}
              onCheckedChange={() => toggle(user.id)}
              label={user.name}
            />
          </div>
        ))}
      </div>

      <div className="flex flex-wrap gap-2">
        <Button onClick={save} disabled={saving}>
          ذخیره
        </Button>
        <Button variant="ghost" onClick={onDone}>
          انصراف
        </Button>
      </div>
    </div>
  );
}
