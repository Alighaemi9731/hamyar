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
    <div className="flex min-h-dvh flex-col items-center justify-center gap-8 bg-background px-5 text-center">
      <Head title="MobiShop" />

      <span className="reveal flex size-16 items-center justify-center rounded-card bg-primary text-primary-foreground shadow-low">
        <StoreIcon className="size-6" />
      </span>

      <div className="space-y-2">
        <h1 className="reveal text-3xl font-extrabold">MobiShop</h1>
        <p className="reveal reveal-delay-1 max-w-xl text-lg leading-relaxed text-muted-foreground">
          سامانه ابری مدیریت فروشگاه موبایل — فروش سریالی، تعمیرات، اقساط و چک، همه در
          یک‌جا. هر گوشی یک شناسنامه IMEI دارد.
        </p>
      </div>

      <Button asChild size="lg" className="reveal reveal-delay-2 h-12 px-8 text-base">
        <Link href="/register">۱۴ روز رایگان شروع کنید</Link>
      </Button>
    </div>
  );
}
