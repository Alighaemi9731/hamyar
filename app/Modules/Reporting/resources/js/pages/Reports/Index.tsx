import { Head, Link } from '@inertiajs/react';
import { ArrowLeftIcon } from 'lucide-react';

import { EmptyState } from '@/components/domain/empty-state';
import { AppShell } from '@/layouts/app-shell';

interface ReportLink {
  key: string;
  title: string;
  description: string;
  href: string;
}

interface Props {
  groups: { key: string; label: string; reports: ReportLink[] }[];
}

/**
 * The report index.
 *
 * ## Only reports that exist appear
 *
 * No «به‌زودی» rows. A greyed-out promise trains the reader to stop trusting the list,
 * and the roadmap is a better place to track what is not built than the product is.
 *
 * ## A one-line description under each title
 *
 * «فروش بر اساس کالا» and «فروش بر اساس فروشنده» are one word apart and answer entirely
 * different questions. The line under the title is what stops somebody opening three
 * reports to find the one they meant.
 */
export default function ReportsIndex({ groups }: Props) {
  return (
    <AppShell title="گزارش‌ها">
      <Head title="گزارش‌ها" />

      {groups.length === 0 ? (
        <EmptyState
          variant="permission"
          title="گزارشی در دسترس شما نیست"
          description="دسترسی «مشاهده گزارش‌ها» به حساب شما داده نشده است. مدیر فروشگاه می‌تواند آن را از بخش نقش‌ها فعال کند."
        />
      ) : (
        <div className="space-y-10">
          {groups.map((group) => (
            <section key={group.key}>
              <h2 className="mb-4 text-sm font-semibold text-muted-foreground">{group.label}</h2>

              <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {group.reports.map((report) => (
                  <Link
                    key={report.key}
                    href={report.href}
                    className="group rounded-card border bg-card p-5 transition-colors hover:border-brand"
                  >
                    <div className="flex items-start justify-between gap-3">
                      <h3 className="font-semibold">{report.title}</h3>
                      <ArrowLeftIcon
                        className="size-4 shrink-0 text-muted-foreground transition-colors group-hover:text-brand"
                        aria-hidden
                      />
                    </div>
                    <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                      {report.description}
                    </p>
                  </Link>
                ))}
              </div>
            </section>
          ))}
        </div>
      )}
    </AppShell>
  );
}
