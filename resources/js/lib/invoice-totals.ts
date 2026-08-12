/**
 * Invoice arithmetic, in the browser.
 *
 * ## This is a deliberate mirror of `App\Modules\Sales\Services\InvoiceTotals`
 *
 * Duplicating money arithmetic is normally indefensible, so the reason has to be
 * written down. The POS holds the basket client-side and shows a running total that
 * updates on every scan, every quantity change and every discount keystroke. Asking the
 * server for that total means a round trip between a salesperson typing a discount and
 * the number they are reading out to the customer — on a shop's connection, that is the
 * pause that sends people back to a calculator.
 *
 * The same trade was made for `isValidImei` in `imei-input.tsx`, for the same reason and
 * with the same rule: **the server is authoritative, this is a preview.** Whatever the
 * customer is charged is what `InvoiceTotals` computes at finalisation. If the two ever
 * disagree, this file is wrong, not the PHP.
 *
 * ## How the two are kept in step
 *
 * There is no JavaScript test runner in this repo, so this cannot be unit-tested against
 * fixtures the way the PHP is. The guard is the browser test: it builds a real basket
 * through the real POS, reads the total off the screen, finalises, and asserts the
 * issued invoice's stored total equals what the screen said. That is a stronger check
 * than parallel fixtures anyway — it exercises both implementations against each other
 * on the same data, through the same UI a shopkeeper uses.
 *
 * ## The rules, restated so a reader does not have to open the PHP
 *
 * 1. Integer rial everywhere. No float touches money; every division is a truncating
 *    integer division.
 * 2. An invoice-level discount is **distributed across the lines** by value, not
 *    subtracted from the total — otherwise per-line profit ignores the discount the
 *    customer actually received. The remainder lands on the largest line so the parts
 *    sum to the whole exactly.
 * 3. VAT is computed **per line, after** that distribution: tax follows the discounted
 *    price.
 * 4. Both derived figures — the discount share and the line VAT — are floored to a whole
 *    toman, because a shop cannot charge a tenth of one and `<Money/>` will not render
 *    it.
 * 5. Rounding the grand total is a shop setting, applied **once at the very end**, never
 *    to a line, and the adjustment is reported rather than absorbed.
 */

/** Storage is rial; the shop and the customer transact in whole toman. */
const RIAL_PER_TOMAN = 10;

export type RoundingDirection = 'none' | 'nearest' | 'up' | 'down';

export interface TotalsLine {
  /** Stable identity for the discount distribution. Any unique string will do. */
  key: string;
  quantity: number;
  /** Rial. */
  unit_price: number;
  /** Rial, this line's own discount — not a share of the invoice one. */
  discount_amount: number;
}

export interface TotalsInput {
  lines: TotalsLine[];
  /** Rial, spread across the lines by value. */
  discount_amount: number;
  shipping_amount: number;
  /** Percent, 0–100. Zero when the shop does not charge VAT. */
  vat_rate: number;
  rounding_step: number;
  rounding_direction: RoundingDirection;
}

export interface LineTotals {
  key: string;
  /** Before any invoice-level discount. */
  gross: number;
  /** This line's share of the invoice discount. */
  discount_share: number;
  /** Gross less the share, before tax. */
  net: number;
  vat: number;
  /** Net plus VAT — what this line adds to the amount handed over. */
  line_total: number;
}

export interface InvoiceTotals {
  /** Sum of line gross, before the invoice discount. */
  subtotal: number;
  discount_amount: number;
  vat_amount: number;
  shipping_amount: number;
  /** Signed. What rounding moved the grand total by. */
  rounding_adjustment: number;
  total: number;
  lines: LineTotals[];
}

/** Floor to a whole number of toman — the unit the counter can actually settle. */
function wholeToman(rial: number): number {
  return Math.trunc(rial / RIAL_PER_TOMAN) * RIAL_PER_TOMAN;
}

function lineGross(line: TotalsLine): number {
  return Math.max(0, line.unit_price * line.quantity - line.discount_amount);
}

/**
 * Spread an invoice-level discount across the lines, by value.
 *
 * The remainder goes to the largest line — the same rule `LandedCostAllocator` uses in
 * Purchasing, and for the same reason: the parts must sum to the whole exactly, and the
 * biggest line is the least visible place for the odd rial.
 */
function distribute(discount: number, lines: TotalsLine[]): Map<string, number> {
  const shares = new Map<string, number>(lines.map((line) => [line.key, 0]));

  if (discount <= 0 || lines.length === 0) {
    return shares;
  }

  const weights = new Map<string, number>(lines.map((line) => [line.key, lineGross(line)]));
  const totalWeight = [...weights.values()].reduce((sum, weight) => sum + weight, 0);

  // A basket of zero-priced lines. Refusing to divide by zero beats inventing a split.
  if (totalWeight === 0) {
    return shares;
  }

  let allocated = 0;
  // `lines` is non-empty here — the early return above covers the other case — but the
  // index signature cannot know that, and asserting it is cheaper than an assertion
  // operator that hides a real emptiness bug later.
  let largestKey = lines[0]?.key ?? '';

  for (const [key, weight] of weights) {
    const share = wholeToman(Math.trunc((discount * weight) / totalWeight));
    shares.set(key, share);
    allocated += share;

    if (weight > (weights.get(largestKey) ?? 0)) {
      largestKey = key;
    }
  }

  // Whatever the flooring left behind, so the discount the customer was promised is
  // exactly the discount the lines record.
  shares.set(largestKey, (shares.get(largestKey) ?? 0) + discount - allocated);

  return shares;
}

/**
 * Round the grand total to something a counter can settle.
 *
 * Mirrors `TotalRounder`. A total already sitting on the step is never moved, in any
 * direction — including `up`, which would otherwise add a whole step to a number that
 * was already payable.
 */
export function roundTotal(
  total: number,
  step: number,
  direction: RoundingDirection
): { total: number; adjustment: number } {
  if (direction === 'none' || step <= 1 || total <= 0) {
    return { total, adjustment: 0 };
  }

  const remainder = total % step;

  if (remainder === 0) {
    return { total, adjustment: 0 };
  }

  let rounded: number;

  if (direction === 'down') {
    rounded = total - remainder;
  } else if (direction === 'up') {
    rounded = total - remainder + step;
  } else {
    // Half-up: exactly half a step rounds away from zero, which is what a person does in
    // their head and therefore what the customer expects to see.
    rounded = remainder * 2 >= step ? total - remainder + step : total - remainder;
  }

  return { total: rounded, adjustment: rounded - total };
}

/**
 * Every figure on the invoice, from the basket.
 */
export function calculateTotals({
  lines,
  discount_amount,
  shipping_amount,
  vat_rate,
  rounding_step,
  rounding_direction,
}: TotalsInput): InvoiceTotals {
  const shares = distribute(discount_amount, lines);

  let subtotal = 0;
  let netTotal = 0;
  let vatTotal = 0;

  const computed: LineTotals[] = lines.map((line) => {
    const gross = lineGross(line);
    const share = shares.get(line.key) ?? 0;
    const net = Math.max(0, gross - share);
    const vat = wholeToman(Math.trunc((net * vat_rate) / 100));

    subtotal += gross;
    netTotal += net;
    vatTotal += vat;

    return { key: line.key, gross, discount_share: share, net, vat, line_total: net + vat };
  });

  const beforeRounding = Math.max(0, netTotal + vatTotal + shipping_amount);
  const rounded = roundTotal(beforeRounding, rounding_step, rounding_direction);

  return {
    subtotal,
    discount_amount,
    vat_amount: vatTotal,
    shipping_amount,
    rounding_adjustment: rounded.adjustment,
    total: rounded.total,
    lines: computed,
  };
}
