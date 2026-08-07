import { Head, useForm } from '@inertiajs/react';
import { MonitorIcon } from 'lucide-react';

import { EmptyState } from '@/components/domain/empty-state';
import { Num } from '@/components/domain/num';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali } from '@/lib/jalali';

interface SessionRow {
  id: string;
  is_current: boolean;
  ip: string | null;
  agent: string;
  last_active_at: string;
}

export default function Sessions({ sessions }: { sessions: SessionRow[] }) {
  const revokeOthers = useForm({ password: '' });

  return (
    <AppShell title="نشست‌های فعال">
      <Head title="نشست‌های فعال" />

      <div className="max-w-2xl space-y-6">
        <p className="text-sm text-muted-foreground">
          هر دستگاهی که با حساب شما وارد شده است. اگر موردی را نمی‌شناسید، همان‌جا ببندیدش
          و رمز عبورتان را عوض کنید.
        </p>

        {sessions.length === 0 ? (
          <EmptyState
            icon={MonitorIcon}
            title="نشست فعالی ثبت نشده"
            description="پس از ورود از یک دستگاه، اینجا نمایش داده می‌شود."
          />
        ) : (
          <ul className="divide-y divide-border overflow-hidden rounded-card border border-border bg-surface">
            {sessions.map((session) => (
              <li key={session.id} className="flex items-center gap-4 p-4">
                <MonitorIcon className="size-5 shrink-0 text-muted-foreground" />
                <div className="min-w-0 flex-1">
                  <p className="flex items-center gap-2 text-sm">
                    {session.agent}
                    {session.is_current && <Badge variant="outline">همین دستگاه</Badge>}
                  </p>
                  <p className="text-2xs text-muted-foreground">
                    {session.ip && <Num value={session.ip} variant="ltr" />}
                    {' · '}
                    {formatJalali(session.last_active_at, { withTime: true })}
                  </p>
                </div>
                {!session.is_current && (
                  <Button
                    variant="ghost"
                    size="sm"
                    className="text-destructive"
                    onClick={() => {
                      // Inertia's router; no payload needed — the id is in the URL.
                      revokeOthers.delete(`/settings/sessions/${session.id}`);
                    }}
                  >
                    بستن
                  </Button>
                )}
              </li>
            ))}
          </ul>
        )}

        <form
          className="space-y-4 rounded-card border border-border bg-surface p-5"
          onSubmit={(e) => {
            e.preventDefault();
            revokeOthers.delete('/settings/sessions');
          }}
        >
          <p className="text-xs text-muted-foreground">
            بستن همه نشست‌های دیگر به رمز عبور نیاز دارد — تا کسی که پشت میز باز شما
            نشسته نتواند این کار را بکند.
          </p>
          <div className="space-y-1.5">
            <Label htmlFor="revoke-password">رمز عبور فعلی</Label>
            <Input
              id="revoke-password"
              type="password"
              dir="ltr"
              className="ltr-value"
              value={revokeOthers.data.password}
              onChange={(e) => revokeOthers.setData('password', e.target.value)}
            />
            {revokeOthers.errors.password && (
              <p className="text-2xs text-destructive">{revokeOthers.errors.password}</p>
            )}
          </div>
          <Button type="submit" variant="destructive" disabled={revokeOthers.processing}>
            بستن همه نشست‌های دیگر
          </Button>
        </form>
      </div>
    </AppShell>
  );
}
