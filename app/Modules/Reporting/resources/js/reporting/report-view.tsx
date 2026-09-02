import { FileTextIcon, PrinterIcon, TableIcon } from 'lucide-react';
import { type ReactNode, useState } from 'react';

import { printSheet } from '@/components/domain/print-layout';
import { Button } from '@/components/ui/button';

export interface ReportView {
  /** True while the A4 sheet is on screen instead of the analytical view. */
  showingSheet: boolean;
  /** The toggle and the print button, for a `PageHeader`'s `actions`. */
  actions: ReactNode;
}

/**
 * The two buttons every report grew when its screen view was split from its document.
 *
 * Extracted because these seven copies must not drift, not because two buttons are hard to
 * write. Three decisions live in here and each of them was a bug in an earlier draft:
 *
 * **Print shows the paper first.** `PrintLayout` argues that the sheet on screen *is* the
 * sheet that prints — "a preview that looks nothing like the output is its own bug" — so
 * pressing «چاپ» switches to the sheet and prints on the next tick. Nothing goes to a
 * printer unseen, and there is no preview route to drift from the real one.
 *
 * **The toggle is not called «نمای چاپ».** That name contains «چاپ», so the toggle and the
 * print button beside it announce as overlapping labels — a screen reader reads "نمای چاپ"
 * then "چاپ", and only one of them sends anything to a printer.
 *
 * **`aria-pressed` on the toggle**, because which view you are in is state, and a control
 * that communicates state only by swapping its own label communicates it once, at the
 * moment nobody is listening.
 *
 * The rest of a report's chrome — cuts, date ranges, saved filters, export — stays on the
 * page. Those genuinely differ: three reports have cuts and three do not, and the financial
 * report carries two ranges. One component configurable enough to hold all of that would
 * fit none of them well.
 */
export function useReportView(): ReportView {
  const [showingSheet, setShowingSheet] = useState(false);

  const actions = (
    <>
      <Button
        variant="outline"
        aria-pressed={showingSheet}
        onClick={() => setShowingSheet((open) => !open)}
      >
        {showingSheet ? <TableIcon aria-hidden /> : <FileTextIcon aria-hidden />}
        {showingSheet ? 'نمایش جدول' : 'نمایش برگه'}
      </Button>

      <Button
        variant="outline"
        onClick={() => {
          setShowingSheet(true);
          window.setTimeout(printSheet, 0);
        }}
      >
        <PrinterIcon aria-hidden />
        چاپ
      </Button>
    </>
  );

  return { showingSheet, actions };
}
