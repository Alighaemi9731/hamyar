import { Head, Link } from '@inertiajs/react';
import { CheckCircle2Icon, SmartphoneIcon } from 'lucide-react';

import { DataTable } from '@/components/domain/data-table';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/domain/empty-state';
import { Num } from '@/components/domain/num';
import { PageHeader } from '@/components/domain/page-header';
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
 *
 * ## The list is capped, and now says so
 *
 * The controller fetches `limit(200)`. A shop with two hundred and fifty outstanding
 * transfers was shown two hundred and told nothing, so «تمام شد» and «تا اینجا نشان دادیم»
 * looked identical — on the one screen whose whole job is proving a compliance backlog has
 * been cleared. The notice is drawn from the row count rather than a server-side total,
 * which over-warns in the exact case of two hundred and never under-warns. A precise
 * remainder needs a count on the wire and is a decision for the owner, not a redesign.
 */
const ROW_CAP = 200;

export default function HamtaPending({ units }: { units: Unit[] }) {
  return (
    <AppShell
      header={
        <PageHeader
          title="انتقال‌های همتا"
          description="دستگاه‌های دست‌دومی که انتقال مالکیتشان در سامانهٔ همتا هنوز ثبت نشده است."
          actions={
            <Button asChild variant="outline">
              <Link href="/hamta/guide">راهنمای انتقال</Link>
            </Button>
          }
        />
      }
    >
      <Head title="انتقال‌های همتا" />

      <div className="space-y-6">
        <ApiNotice />

        {units.length === 0 ? (
          <EmptyState
            icon={CheckCircle2Icon}
            title="انتقال معوقی نیست"
            description="هر دستگاه دست‌دومی که خریده یا فروخته‌اید، انتقالش ثبت شده است."
          />
        ) : (
          <>
            {units.length >= ROW_CAP && (
              <p
                role="status"
                className="rounded-control border border-warning/25 bg-warning/5 px-4 py-3 text-sm text-warning"
              >
                فقط <Num value={ROW_CAP} variant="prose" /> مورد قدیمی‌تر نشان داده می‌شود؛ ممکن است
                انتقال‌های معوق بیشتری داشته باشید. با ثبت همین‌ها، بقیه نمایش داده می‌شوند.
              </p>
            )}

            <DataTable
              caption="دستگاه‌های دست‌دومی که انتقال مالکیتشان در همتا ثبت نشده، قدیمی‌ترین اول."
              rows={units}
              rowKey={(unit) => unit.id}
              columns={[
                {
                  key: 'product',
                  header: 'دستگاه',
                  cell: (unit) => (
                    <>
                      <span className="font-medium">{unit.product}</span>
                      <span className="block text-xs text-muted-foreground">{unit.condition}</span>
                    </>
                  ),
                },
                {
                  // Inherently LTR — an IMEI is read left to right whatever the page does.
                  key: 'imei',
                  header: 'IMEI',
                  cell: (unit) => <Num value={unit.imei} variant="ltr" />,
                },
                {
                  key: 'status',
                  header: 'وضعیت',
                  cell: (unit) => unit.status,
                  secondary: true,
                },
                {
                  key: 'party',
                  header: 'طرف حساب',
                  cell: (unit) => unit.party ?? '—',
                },
                {
                  key: 'acquired_at',
                  header: 'تاریخ ورود',
                  cell: (unit) => unit.acquired_at,
                  secondary: true,
                },
                {
                  key: 'checklist',
                  header: '',
                  cell: (unit) => (
                    <Link href={`/hamta/${unit.id}`} className="text-primary hover:underline">
                      چک‌لیست
                    </Link>
                  ),
                },
              ]}
            />
          </>
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
