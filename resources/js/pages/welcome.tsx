import { Head, Link } from '@inertiajs/react';
import { StoreIcon } from 'lucide-react';

import { Button } from '@/components/ui/button';

/**
 * Central landing placeholder.
 *
 * The real marketing page — signature "live thermal receipt" hero, Blade + Tailwind,
 * ≤180KB gz — is Phase 11 per docs/design-system.md#landing. This exists so the
 * central domain resolves to something honest in the meantime.
 */
export default function Welcome() {
  return (
    <div className="flex min-h-dvh flex-col items-center justify-center gap-6 bg-background px-4 text-center">
      <Head title="MobiShop" />

      <span className="flex size-14 items-center justify-center rounded-card bg-primary text-primary-foreground">
        <StoreIcon className="size-6" />
      </span>

      <div className="space-y-2">
        <h1 className="text-2xl">MobiShop</h1>
        <p className="max-w-md text-sm text-muted-foreground">
          سامانه ابری مدیریت فروشگاه موبایل — فروش سریالی، تعمیرات، اقساط و چک، همه در
          یک‌جا. هر گوشی یک شناسنامه IMEI دارد.
        </p>
      </div>

      <Button asChild size="lg">
        <Link href="/register">۱۴ روز رایگان شروع کنید</Link>
      </Button>
    </div>
  );
}
