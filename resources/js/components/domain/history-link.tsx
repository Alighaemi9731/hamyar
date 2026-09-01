import { Link } from '@inertiajs/react';
import { HistoryIcon } from 'lucide-react';

import { Button } from '@/components/ui/button';

export interface HistoryLinkProps {
  /** Audit subject key, as the owning module registered it (`product`, `party`, …). */
  subject: string;
  /** The record's id. */
  record: number;
  /** Override the label where «تاریخچه» alone would be ambiguous on the page. */
  label?: string;
  className?: string;
}

/**
 * «تاریخچه» — from a record, into its own audit history.
 *
 * ## Why this is a component and not four hrefs
 *
 * The audit log is only useful from the record. An owner asking «کی قیمت این گوشی را
 * عوض کرد؟» is looking at the گوشی when they ask, and a viewer they must find in
 * Settings and then filter down to the product they were already on is a viewer they
 * open once, out of curiosity, and never again. The standalone screen is for browsing;
 * this is for answering.
 *
 * Making it a component rather than a link each page writes itself keeps the query
 * shape in one place. The pairing of `subject` and `record` is load-bearing — a record
 * id means nothing without the kind it belongs to, since ids repeat across tables —
 * and four pages spelling that out by hand is four chances to send `?record=12` alone
 * and land on an unfiltered log that looks filtered.
 */
export function HistoryLink({ subject, record, label = 'تاریخچه', className }: HistoryLinkProps) {
  return (
    <Button variant="ghost" className={className} asChild>
      <Link href={`/settings/activity?subject=${subject}&record=${record}`}>
        <HistoryIcon className="size-4" aria-hidden />
        {label}
      </Link>
    </Button>
  );
}
