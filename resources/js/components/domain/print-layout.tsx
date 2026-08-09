import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

type Paper = 'thermal80' | 'a4' | 'a5';

interface PaperSpec {
  /** The `@page size` value. */
  size: string;
  /** Page margin. Thermal printers own their own margins; paper needs ours. */
  margin: string;
  /** On-screen width of the preview, so what you see is the width that prints. */
  screenWidth: string;
}

const PAPER: Record<Paper, PaperSpec> = {
  // 80mm roll. Height is `auto` because a receipt is as long as it is — fixing it
  // either cuts the last lines off or feeds blank paper after every sale.
  thermal80: { size: '80mm auto', margin: '0', screenWidth: '80mm' },
  a4: { size: 'A4', margin: '10mm', screenWidth: '210mm' },
  a5: { size: 'A5', margin: '8mm', screenWidth: '148mm' },
};

export interface PrintLayoutProps {
  /** Everything outside the sheet — controls, filters, the print button. */
  toolbar?: ReactNode;
  children: ReactNode;
  className?: string;
}

/**
 * The print layouts the design system owns (rule 9).
 *
 * Three things it exists to prevent, all of which are what page-local `@media print`
 * blocks produce:
 *
 * - **Two renderings of one document.** The sheet on screen IS the sheet that prints.
 *   The toolbar carries `no-print` and disappears; nothing else changes. A separate
 *   preview route drifts from the real thing and the operator finds out after wasting
 *   a sheet of adhesive label stock.
 * - **The wrong paper.** `@page` is a document-level rule and cannot be scoped to an
 *   element, so exactly one of these may be on a page — which is why each is a full
 *   page layout rather than a box you drop into a screen.
 * - **The app's dark mode on a printer.** The sheet is always ink on white. A shop
 *   working in dark mode must not get a black rectangle out of the printer.
 *
 * The sheet keeps `dir="rtl"`: a Persian receipt reads right-to-left on paper exactly
 * as it does on screen.
 */
function Sheet({ paper, toolbar, children, className }: PrintLayoutProps & { paper: Paper }) {
  const spec = PAPER[paper];

  return (
    <>
      {/*
        Rendered by the layout rather than written into app.css because the value
        depends on which paper the page chose, and `@page` takes no class or variable.
        This is the system layer, not a page-local hack — no feature page writes one.
      */}
      <style>{`@page { size: ${spec.size}; margin: ${spec.margin}; }`}</style>

      {toolbar ? <div className="no-print mb-6">{toolbar}</div> : null}

      <div
        data-paper={paper}
        dir="rtl"
        // Ink on white, always. `print:` variants would only fix the printer; a
        // preview that looks nothing like the output is its own bug.
        className={cn(
          'mx-auto bg-white text-black shadow-low print:shadow-none',
          'w-full print:w-auto',
          className
        )}
        style={{ maxWidth: spec.screenWidth }}
      >
        {children}
      </div>
    </>
  );
}

export const PrintLayout = {
  /** 80mm thermal roll: sales receipts, repair intake slips. */
  Thermal80: (props: PrintLayoutProps) => <Sheet paper="thermal80" {...props} />,
  /** A4: official invoices, label sheets, reports. */
  A4: (props: PrintLayoutProps) => <Sheet paper="a4" {...props} />,
  /** A5: the half-page invoice most Iranian shops actually hand over. */
  A5: (props: PrintLayoutProps) => <Sheet paper="a5" {...props} />,
};

/**
 * Ask the browser to print.
 *
 * A function rather than each page calling `window.print()` so there is one place to
 * put anything that has to happen first — fonts settling, images decoding — the day a
 * layout needs it.
 */
export function printSheet(): void {
  window.print();
}
