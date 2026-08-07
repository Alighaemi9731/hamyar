import { Head } from '@inertiajs/react';
import { HistoryIcon } from 'lucide-react';

import { EmptyState } from '@/components/domain/empty-state';
import { Badge } from '@/components/ui/badge';
import { AppShell } from '@/layouts/app-shell';
import { formatJalali } from '@/lib/jalali';

interface ActivityRow {
  id: number;
  description: string;
  event: string | null;
  subject_type: string | null;
  subject_id: number | null;
  causer: string | null;
  created_at: string | null;
  changes: Record<string, unknown>;
}

interface Props {
  activities: {
    data: ActivityRow[];
    current_page: number;
    last_page: number;
    total: number;
  };
}

/**
 * Read-only by design: an audit trail an operator can edit is not an audit trail,
 * so there is no update or delete route behind this screen.
 */
export default function ActivityLog({ activities }: Props) {
  return (
    <AppShell title="گزارش فعالیت">
      <Head title="گزارش فعالیت" />

      <div className="max-w-3xl space-y-6">
        {activities.data.length === 0 ? (
          <EmptyState
            icon={HistoryIcon}
            title="هنوز فعالیتی ثبت نشده"
            description="هر تغییری که کاربران انجام دهند، همراه با نام و زمان اینجا ثبت می‌شود."
          />
        ) : (
          <ul className="divide-y divide-border overflow-hidden rounded-card border border-border bg-surface">
            {activities.data.map((activity) => (
              <li key={activity.id} className="flex items-start gap-3 p-4">
                <HistoryIcon className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                <div className="min-w-0 flex-1">
                  <p className="text-sm">
                    {activity.description}
                    {activity.subject_type && (
                      <Badge variant="secondary" className="ms-2">
                        {activity.subject_type}
                      </Badge>
                    )}
                  </p>
                  <p className="text-2xs text-muted-foreground">
                    {activity.causer ?? 'سیستم'}
                    {activity.created_at &&
                      ` · ${formatJalali(activity.created_at, { withTime: true })}`}
                  </p>
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>
    </AppShell>
  );
}
