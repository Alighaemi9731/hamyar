import { Head } from '@inertiajs/react';
import { PackageIcon } from 'lucide-react';

import { EmptyState } from '@/components/domain/empty-state';
import { Button } from '@/components/ui/button';
import { AppShell } from '@/layouts/app-shell';

/**
 * Placeholder dashboard.
 *
 * Real widgets arrive in Phase 9 (role-aware, measured SQL). Until then this exists
 * to prove the shell, the shared props and the RTL layout actually render.
 */
export default function Dashboard() {
  return (
    <AppShell title="داشبورد">
      <Head title="داشبورد" />

      <EmptyState
        icon={PackageIcon}
        title="هنوز داده‌ای برای نمایش نیست"
        description="با ثبت اولین خرید و ورود گوشی‌ها به انبار، آمار فروش و سود همین‌جا نمایش داده می‌شود."
        action={<Button>شروع کنید</Button>}
      />
    </AppShell>
  );
}
