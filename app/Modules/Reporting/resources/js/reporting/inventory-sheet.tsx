import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import type { MoneyValue } from '@/types';

import type { InventoryRow, InventoryTotals } from './types';

export interface InventorySheetProps {
  dead: boolean;
  asOfJalali: string;
  days: number;
  rows: InventoryRow[];
  totals: InventoryTotals;
}

/**
 * ارزش موجودی و کالای راکد, as a document.
 *
 * Moved here without a character changed — see `sales-sheet.tsx` for the argument.
 * **Nothing in this file may be adjusted for the screen.**
 */
export function InventorySheet({ dead, asOfJalali, days, rows, totals }: InventorySheetProps) {
  return (
    <div className="p-8 print:p-0">
      <header className="mb-6 border-b pb-4">
        {/* The document's heading, not the page's — `AppShell` already renders an
            `<h1>` above the paper, and this repeated it, so every report shipped
            two page headings. On paper the outline does not exist and the
            rendering is unchanged; on screen a reader now gets one. */}
        <h2 className="text-lg font-bold">{dead ? 'کالای راکد' : 'ارزش موجودی انبار'}</h2>
        <p className="mt-1 text-sm text-black/60">
          {dead ? (
            <>
              بدون خروج به مدت <Num value={days} variant="table" /> روز یا بیشتر
            </>
          ) : (
            <>در تاریخ {asOfJalali}</>
          )}
        </p>
      </header>

      <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Figure label="ارزش کل" value={totals.value} />
        <Figure label="ارزش دستگاه‌ها" value={totals.device_value} />
        <Figure label="تعداد دستگاه" count={totals.devices} />
        <Figure label="تعداد اقلام" count={totals.items} />
      </div>

      {rows.length === 0 ? (
        <p className="py-12 text-center text-sm text-black/60">
          {dead ? 'کالای راکدی در این بازه وجود ندارد.' : 'موجودی‌ای ثبت نشده است.'}
        </p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b text-black/60">
                <th className="py-2 text-start font-medium">عنوان</th>
                <th className="py-2 text-start font-medium">نوع</th>
                <th className="py-2 text-end font-medium">تعداد</th>
                {dead ? (
                  <>
                    <th className="py-2 text-end font-medium">روز بی‌حرکت</th>
                    <th className="py-2 text-end font-medium">آخرین خروج</th>
                  </>
                ) : (
                  <th className="py-2 text-end font-medium">بهای واحد</th>
                )}
                <th className="py-2 text-end font-medium">ارزش</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row, index) => (
                <tr key={`${row.label}-${index}`} className="border-b last:border-0">
                  <td className="py-2">{row.label}</td>
                  <td className="py-2">{row.kind === 'serialized' ? 'دستگاه' : 'کالا'}</td>
                  <td className="py-2 text-end">
                    <Num value={row.quantity} variant="table" />
                  </td>
                  {dead ? (
                    <>
                      <td className="py-2 text-end">
                        <Num value={row.idle_days ?? 0} variant="table" />
                      </td>
                      <td className="py-2 text-end">{row.last_out}</td>
                    </>
                  ) : (
                    <td className="py-2 text-end">
                      <Money rial={row.unit_cost?.value ?? 0} digits="latin" />
                    </td>
                  )}
                  <td className="py-2 text-end">
                    <Money rial={row.value.value} digits="latin" />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <footer className="mt-6 border-t pt-3 text-xs text-black/60">
        ارزش‌ها به بهای تمام‌شده است، نه قیمت فروش. کالاهای معمولی به میانگین وزنی خرید و دستگاه‌های
        سریالی به بهای همان دستگاه ارزش‌گذاری می‌شوند.
      </footer>
    </div>
  );
}

function Figure({ label, value, count }: { label: string; value?: MoneyValue; count?: number }) {
  return (
    <div className="rounded-control border p-3">
      <p className="text-xs text-black/60">{label}</p>
      <p className="mt-1 font-semibold">
        {value ? (
          <Money rial={value.value} withUnit digits="latin" />
        ) : (
          <Num value={count ?? 0} variant="table" />
        )}
      </p>
    </div>
  );
}
