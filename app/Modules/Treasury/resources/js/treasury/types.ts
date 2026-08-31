import type { LucideIcon } from 'lucide-react';
import { BanknoteIcon, CreditCardIcon, LandmarkIcon, WalletIcon } from 'lucide-react';

import type { MoneyValue } from '@/types';

/**
 * A place money actually sits, exactly as `TreasuryController::index` sends it.
 *
 * The shape is the server's, unchanged. Note what is *not* here: account and terminal
 * numbers exist on the model but are not in this payload, so nothing on the screen may
 * claim to identify an account by its last four digits.
 */
export interface AccountRow {
  id: number;
  name: string;
  type: string;
  bank_name: string | null;
  balance: MoneyValue;
  /**
   * Net movement of entries nobody has ticked off against a statement.
   *
   * **Signed, and not a subset of `balance`.** It is a sum of debits minus credits over
   * unreconciled ledger rows, so it can be negative and it can exceed the balance. Any
   * attempt to render it as "x% of this account is unverified" would be arithmetic the
   * data does not support — show the amount, not a proportion.
   */
  unreconciled: MoneyValue;
}

/** A name money is counted under. Never a place it sits — hence `total`, not `balance`. */
export interface HeadingRow {
  id: number;
  name: string;
  type: string;
  total: MoneyValue;
}

export interface AccountKind {
  type: string;
  /** Plural, because it heads a group and labels a row in the composition breakdown. */
  label: string;
  icon: LucideIcon;
}

/**
 * The kinds of place money sits, most liquid first.
 *
 * The order is the point. Cash in the till is spendable now, money in the bank is
 * spendable today, and takings sitting in a card terminal are not spendable until the
 * acquirer settles. A treasurer reads down this list in exactly that order, so the page
 * presents it in exactly that order — which is *not* the `orderBy('type')` alphabetical
 * order the server sends, and deliberately so. Re-ordering for reading is presentation;
 * the server's ordering is left alone.
 */
export const ACCOUNT_KINDS: AccountKind[] = [
  { type: 'cash', label: 'صندوق‌ها', icon: BanknoteIcon },
  { type: 'bank', label: 'حساب‌های بانکی', icon: LandmarkIcon },
  { type: 'pos_terminal', label: 'کارتخوان‌ها', icon: CreditCardIcon },
];

/** Anything the server calls a money-holder that this list does not yet name. */
export const UNKNOWN_KIND: AccountKind = { type: 'other', label: 'سایر حساب‌ها', icon: WalletIcon };

export function kindOf(type: string): AccountKind {
  return ACCOUNT_KINDS.find((kind) => kind.type === type) ?? UNKNOWN_KIND;
}

export interface AccountGroup {
  kind: AccountKind;
  accounts: AccountRow[];
  total: number;
}

/**
 * Bucket accounts into the kinds the UI displays, dropping empty groups.
 *
 * Lives here rather than in the page because the transfer picker groups its options the
 * same way — the page teaches that a treasurer reads صندوق → بانک → کارتخوان, and the
 * picker has to keep that promise when they act on it.
 *
 * Anything the server calls a money-holder that `ACCOUNT_KINDS` does not name still gets
 * a home under «سایر حساب‌ها» rather than vanishing: a new account type added to the
 * backend should show up looking unstyled, not disappear silently.
 */
export function groupByKind(accounts: AccountRow[]): AccountGroup[] {
  const ordered = [...ACCOUNT_KINDS, UNKNOWN_KIND];

  return ordered
    .map((kind) => {
      const members =
        kind.type === UNKNOWN_KIND.type
          ? accounts.filter(
              (account) => !ACCOUNT_KINDS.some((known) => known.type === account.type)
            )
          : accounts.filter((account) => account.type === kind.type);

      return {
        kind,
        accounts: members,
        total: members.reduce((sum, account) => sum + account.balance.value, 0),
      };
    })
    .filter((group) => group.accounts.length > 0);
}

/** Heading types, which are classifications rather than places. */
export const HEADING_TYPE_LABEL: Record<string, string> = {
  expense: 'هزینه',
  income: 'درآمد',
  sales: 'فروش',
  inventory: 'موجودی',
  cheques_receivable: 'چک نزد ما',
  cheques_in_collection: 'چک در جریان وصول',
  cheques_returned: 'چک برگشتی',
  cheques_payable: 'اسناد پرداختنی',
};
