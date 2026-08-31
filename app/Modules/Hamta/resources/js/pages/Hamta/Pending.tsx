import { Head, Link } from '@inertiajs/react';
import { CheckCircle2Icon, SmartphoneIcon } from 'lucide-react';

import { EmptyState } from '@/components/domain/empty-state';
import { AppShell } from '@/layouts/app-shell';

interface Unit {
  id: number;
  imei: string;
  product: string;
  condition: string;
  status: string;
  party: string | null;
  warehouse: string | null;
  acquired_at: string;
  url: string;
}

/**
 * Devices with an ownership transfer outstanding — the screen somebody has to clear.
 *
 * The spec's wording: a used sale can complete with the transfer outstanding, because the
 * shop's workflow cannot be held hostage to a third party. This is the other half of that
 * bargain — the outstanding ones are collected somewhere visible instead of being forgotten
 * the moment the customer leaves.
 */
export default function HamtaPending({ units }: { units: Unit[] }) {
  return (
    <AppShell title="انتقال‌های همتا">
      <Head title="انتقال‌های همتا" />

      <div className="space-y-6">
        {/* No `<h1>` here: `AppShell` renders one from `title`, and this said the same
            words at 40px directly beneath it — two page headings in the document outline
            and, since the shell's own title was demoted to 28px, the louder of the two
            was the duplicate. The description survives; the heading was the redundancy. */}
        <header>
          <p className="max-w-3xl text-sm text-muted-foreground">
            دستگاه‌های دست‌دومی که انتقال مالکیتشان در سامانهٔ همتا هنوز ثبت نشده است.
            <Link href="/hamta/guide" className="ms-1 text-primary hover:underline">
              راهنمای انتقال
            </Link>
          </p>
        </header>

        <ApiNotice />

        {units.length === 0 ? (
          <EmptyState
            icon={CheckCircle2Icon}
            title="انتقال معوقی نیست"
            description="هر دستگاه دست‌دومی که خریده یا فروخته‌اید، انتقالش ثبت شده است."
          />
        ) : (
          <div className="overflow-x-auto rounded-card border">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b bg-surface-muted text-muted-foreground">
                  <th className="p-3 text-start font-medium">دستگاه</th>
                  <th className="p-3 text-start font-medium">IMEI</th>
                  <th className="p-3 text-start font-medium">وضعیت</th>
                  <th className="p-3 text-start font-medium">طرف حساب</th>
                  <th className="p-3 text-start font-medium">تاریخ ورود</th>
                  <th className="p-3 text-start font-medium" />
                </tr>
              </thead>
              <tbody>
                {units.map((unit) => (
                  <tr key={unit.id} className="border-b last:border-0">
                    <td className="p-3">
                      <span className="font-medium">{unit.product}</span>
                      <span className="block text-xs text-muted-foreground">{unit.condition}</span>
                    </td>
                    {/* Inherently LTR — an IMEI is read left to right whatever the page does. */}
                    <td className="p-3 font-mono tabular-nums" dir="ltr">
                      {unit.imei}
                    </td>
                    <td className="p-3">{unit.status}</td>
                    <td className="p-3">{unit.party ?? '—'}</td>
                    <td className="p-3 tabular-nums">{unit.acquired_at}</td>
                    <td className="p-3 text-end">
                      <Link href={`/hamta/${unit.id}`} className="text-primary hover:underline">
                        چک‌لیست
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </AppShell>
  );
}

/**
 * The sentence this whole module exists to keep saying.
 *
 * Repeated on every HAMTA screen rather than stated once in the guide: a shop that believes
 * the software talks to همتا will stop doing the transfers, and find out months later from
 * a customer whose phone stopped working.
 */
export function ApiNotice() {
  return (
    <div className="flex gap-3 rounded-card border border-warning/25 bg-warning/5 p-4 text-sm">
      <SmartphoneIcon className="mt-0.5 size-5 shrink-0 text-warning" aria-hidden />
      <p className="text-pretty">
        <strong className="font-semibold">سامانهٔ همتا رابط برنامه‌نویسی عمومی ندارد.</strong> این
        صفحه‌ها فقط <em>ثبت و یادآوری</em> هستند — انتقال را خودتان با مشتری انجام می‌دهید و
        نتیجه‌اش را اینجا وارد می‌کنید. همیار هیچ استعلامی از همتا نمی‌گیرد و صحت شناسهٔ فعال‌سازی
        را بررسی نمی‌کند.
      </p>
    </div>
  );
}
