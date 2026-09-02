import { Head, useForm } from '@inertiajs/react';
import { UserPlusIcon, UsersIcon } from 'lucide-react';

import { FormErrors } from '@/components/domain/form-errors';
import { EmptyState } from '@/components/domain/empty-state';
import { Num } from '@/components/domain/num';
import { StatusBadge } from '@/components/domain/status-badge';
import { Badge } from '@/components/ui/badge';
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
import { toLatinDigits } from '@/lib/digits';
import { formatJalali } from '@/lib/jalali';

interface UserRow {
  id: number;
  name: string;
  mobile: string | null;
  email: string | null;
  is_active: boolean;
  last_login_at: string | null;
  roles: Record<string, string>;
  is_self: boolean;
}

interface InvitationRow {
  id: number;
  name: string;
  mobile: string;
  role: string;
  status: string;
  expires_at: string;
}

interface RoleOption {
  name: string;
  label: string;
}

interface Props {
  users: UserRow[];
  invitations: InvitationRow[];
  roles: RoleOption[];
}

export default function Users({ users, invitations, roles }: Props) {
  const invite = useForm({
    name: '',
    mobile: '',
    email: '',
    role: 'Salesperson',
  });
  const toggle = useForm({});

  const pending = invitations.filter((i) => i.status === 'pending');

  return (
    <AppShell title="کاربران فروشگاه">
      <Head title="کاربران فروشگاه" />

      <div className="max-w-3xl space-y-6">
        {/*
          `toggle` is a `useForm({})` driving two endpoints that refuse things — «نمی‌توانید
          حساب خودتان را غیرفعال کنید» on `user`, and «فروشگاه باید حداقل یک مالک داشته
          باشد» on `roles` — and it rendered no errors whatsoever. Both refusals came back
          as a 302, the page re-rendered identically, and the row simply did not change.

          It sits at page level rather than beside a row because the row it belongs to is
          one of many and the message is about the shop, not about that user's name field.
        */}
        <FormErrors errors={toggle.errors} />
        <section className="overflow-hidden rounded-card border border-border bg-surface">
          {users.length === 0 ? (
            <EmptyState icon={UsersIcon} title="هنوز کاربری ثبت نشده" />
          ) : (
            <ul className="divide-y divide-border">
              {users.map((user) => (
                <li key={user.id} className="flex flex-wrap items-center gap-3 p-4">
                  <div className="min-w-0 flex-1">
                    <p className="flex items-center gap-2 text-sm font-medium">
                      {user.name}
                      {user.is_self && <Badge variant="outline">شما</Badge>}
                      {!user.is_active && <StatusBadge status="canceled" label="غیرفعال" />}
                    </p>
                    <p className="text-2xs text-muted-foreground">
                      {user.mobile && <Num value={user.mobile} variant="ltr" />}
                      {user.last_login_at && ` · آخرین ورود ${formatJalali(user.last_login_at)}`}
                    </p>
                  </div>

                  <div className="flex flex-wrap items-center gap-1.5">
                    {Object.entries(user.roles).map(([name, label]) => (
                      <Badge key={name} variant="secondary">
                        {label || name}
                      </Badge>
                    ))}
                  </div>

                  {!user.is_self && (
                    <Button
                      variant="ghost"

                      disabled={toggle.processing}
                      onClick={() => toggle.put(`/settings/users/${user.id}/active`)}
                    >
                      {user.is_active ? 'غیرفعال‌کردن' : 'فعال‌کردن'}
                    </Button>
                  )}
                </li>
              ))}
            </ul>
          )}
        </section>

        {pending.length > 0 && (
          <section className="space-y-3">
            <h2 className="text-lg font-bold">دعوت‌نامه‌های در انتظار</h2>
            <ul className="divide-y divide-border overflow-hidden rounded-card border border-border bg-surface">
              {pending.map((invitation) => (
                <li key={invitation.id} className="flex items-center gap-3 p-4">
                  <div className="min-w-0 flex-1">
                    <p className="text-sm">{invitation.name}</p>
                    <p className="text-2xs text-muted-foreground">
                      <Num value={invitation.mobile} variant="ltr" /> · تا{' '}
                      {formatJalali(invitation.expires_at)}
                    </p>
                  </div>
                  <Badge variant="secondary">{invitation.role}</Badge>
                  <Button
                    variant="ghost"

                    className="text-destructive"
                    onClick={() => toggle.delete(`/settings/invitations/${invitation.id}`)}
                  >
                    لغو
                  </Button>
                </li>
              ))}
            </ul>
          </section>
        )}

        <section className="space-y-5 rounded-card border border-border bg-surface p-6 sm:p-7">
          <h2 className="flex items-center gap-2 text-lg font-bold">
            <UserPlusIcon className="size-4" />
            دعوت کاربر جدید
          </h2>
          <p className="text-sm leading-relaxed text-muted-foreground">
            کاربر با لینک دعوت، رمز عبور خودش را انتخاب می‌کند — هیچ‌کس جز خودش رمزش را نمی‌داند.
          </p>

          <form
            className="grid gap-4 sm:grid-cols-2"
            onSubmit={(e) => {
              e.preventDefault();
              invite.post('/settings/users/invite', {
                onSuccess: () => invite.reset(),
              });
            }}
          >
            <div className="space-y-1.5">
              <Label htmlFor="invite-name">نام</Label>
              <Input
                id="invite-name"
                value={invite.data.name}
                onChange={(e) => invite.setData('name', e.target.value)}
              />
              {invite.errors.name && (
                <p className="text-2xs text-destructive">{invite.errors.name}</p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="invite-mobile">شماره موبایل</Label>
              <Input
                id="invite-mobile"
                dir="ltr"
                inputMode="numeric"
                placeholder="09121234567"
                className="ltr-value tabular"
                value={invite.data.mobile}
                onChange={(e) => invite.setData('mobile', toLatinDigits(e.target.value))}
              />
              {invite.errors.mobile && (
                <p className="text-2xs text-destructive">{invite.errors.mobile}</p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="invite-role">نقش</Label>
              <Select value={invite.data.role} onValueChange={(v) => invite.setData('role', v)}>
                <SelectTrigger id="invite-role">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent dir="rtl">
                  {roles.map((role) => (
                    <SelectItem key={role.name} value={role.name}>
                      {role.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="flex items-end">
              {/* `email` and `role` are validated by `UserController::invite` and were not
                  rendered anywhere. A role rejected by `Rule::in` is not a mistake anybody
                  makes by hand — it is what a stale form posts after the catalogue changes. */}
              <FormErrors errors={invite.errors} handled={['name', 'mobile']} />
              <Button type="submit" disabled={invite.processing}>
                ارسال دعوت
              </Button>
            </div>
          </form>
        </section>
      </div>
    </AppShell>
  );
}
