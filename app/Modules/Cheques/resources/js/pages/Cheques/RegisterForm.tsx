import { router } from '@inertiajs/react';
import { PlusIcon } from 'lucide-react';
import { useState } from 'react';

import { FormErrors } from '@/components/domain/form-errors';
import { JDatePicker } from '@/components/domain/jdate-picker';
import { MoneyField } from '@/components/domain/money-field';
import { PartyPicker, type PartyOption } from '@/components/domain/party-picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useTenantSettings } from '@/hooks/use-tenant-settings';

export interface RegisterFormProps {
  /** `received` or `issued` — the tab the shopkeeper is on. */
  direction: string;
  /** Active bank accounts, for the cheque the shop itself issues. */
  accounts: Array<{ id: number; name: string }>;
  errors: Record<string, string | undefined>;
}

/**
 * Registering a cheque — the door that did not exist until `0.20.0`.
 *
 * Every transition below this worked and none of it was reachable: across 104 write routes
 * nothing created a `Cheque`. The row was written in nine test files and zero production
 * files, while the plan ladder sold «۵۰ ثبت چک در ماه».
 *
 * ## Why it is a disclosure rather than a page
 *
 * The cheque list is what a shop opens this screen for — «کدام چک سررسیدش رسیده» is the
 * daily question. Registering is occasional by comparison, so it collapses out of the way
 * and opens in place. A separate `/cheques/create` route would put a page load between the
 * shopkeeper and a task that takes fifteen seconds.
 *
 * ## The fields are what is printed on the paper
 *
 * Serial, صیاد, bank, branch, holder, amount, due date — read off the cheque in their hand,
 * in that order, so the form can be filled top to bottom without looking back and forth.
 * `received_at` defaults to today on the server rather than appearing here: the ordinary
 * case is a cheque taken today, and a date field pre-filled with today is a field everybody
 * tabs past.
 */
export function RegisterForm({ direction, accounts, errors }: RegisterFormProps) {
  const settings = useTenantSettings();
  const toman = settings.currency_display === 'toman';

  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);

  const [party, setParty] = useState<PartyOption | null>(null);
  const [amount, setAmount] = useState(0);
  const [dueDate, setDueDate] = useState<string | null>(null);
  const [accountId, setAccountId] = useState<string>('');
  const [text, setText] = useState({
    serial: '',
    sayad_id: '',
    bank_name: '',
    branch_name: '',
    account_holder: '',
  });

  const issued = direction === 'issued';

  const submit = () => {
    setBusy(true);

    router.post(
      '/cheques',
      {
        direction,
        party_id: party?.id ?? null,
        amount,
        due_date: dueDate,
        account_id: issued && accountId !== '' ? Number(accountId) : null,
        ...text,
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          // Cleared only on success. A refused submit keeps everything the shopkeeper
          // typed — they are holding the paper and should not have to read it out twice.
          setOpen(false);
          setParty(null);
          setAmount(0);
          setDueDate(null);
          setAccountId('');
          setText({ serial: '', sayad_id: '', bank_name: '', branch_name: '', account_holder: '' });
        },
        onFinish: () => setBusy(false),
      },
    );
  };

  if (!open) {
    return (
      <Button variant="outline" onClick={() => setOpen(true)} className="gap-2">
        <PlusIcon className="size-4" aria-hidden />
        ثبت چک {issued ? 'پرداختی' : 'دریافتی'}
      </Button>
    );
  }

  return (
    <section className="mt-4 rounded-card border border-border bg-card p-5" aria-label="ثبت چک">
      <h2 className="text-lg font-semibold">
        ثبت چک {issued ? 'پرداختی' : 'دریافتی'}
      </h2>

      {/* Every key the server can refuse on that has no input to sit under — a quota
          ceiling, a missing bank account, a posting that would not balance. Without this
          the submit button would silently do nothing (CLAUDE.md). */}
      <FormErrors
        errors={errors}
        handled={['party_id', 'amount', 'serial', 'sayad_id', 'bank_name', 'due_date', 'account_id']}
        className="mt-4"
      />

      <div className="mt-4 grid gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <Label>{issued ? 'در وجه' : 'از طرف حساب'}</Label>
          <PartyPicker value={party} onChange={setParty} />
          {errors.party_id && <p className="mt-1 text-sm text-danger">{errors.party_id}</p>}
        </div>

        <div>
          <Label htmlFor="cheque-amount">مبلغ</Label>
          <MoneyField id="cheque-amount" value={amount} onChange={setAmount} toman={toman} />
          {errors.amount && <p className="mt-1 text-sm text-danger">{errors.amount}</p>}
        </div>

        <div>
          <Label>تاریخ سررسید</Label>
          <JDatePicker value={dueDate} onChange={setDueDate} invalid={Boolean(errors.due_date)} />
          {errors.due_date && <p className="mt-1 text-sm text-danger">{errors.due_date}</p>}
        </div>

        <div>
          <Label htmlFor="cheque-serial">شمارهٔ سریال</Label>
          <Input
            id="cheque-serial"
            value={text.serial}
            onChange={(e) => setText({ ...text, serial: e.target.value })}
            inputMode="numeric"
          />
          {errors.serial && <p className="mt-1 text-sm text-danger">{errors.serial}</p>}
        </div>

        <div>
          <Label htmlFor="cheque-sayad">شناسهٔ صیاد</Label>
          <Input
            id="cheque-sayad"
            value={text.sayad_id}
            onChange={(e) => setText({ ...text, sayad_id: e.target.value })}
            inputMode="numeric"
            // Optional on purpose: paper older than 1400 has none, and refusing it would
            // refuse a legitimate cheque.
            placeholder="۱۶ رقم — اختیاری"
          />
          {errors.sayad_id && <p className="mt-1 text-sm text-danger">{errors.sayad_id}</p>}
        </div>

        <div>
          <Label htmlFor="cheque-bank">بانک</Label>
          <Input
            id="cheque-bank"
            value={text.bank_name}
            onChange={(e) => setText({ ...text, bank_name: e.target.value })}
          />
          {errors.bank_name && <p className="mt-1 text-sm text-danger">{errors.bank_name}</p>}
        </div>

        <div>
          <Label htmlFor="cheque-branch">شعبه</Label>
          <Input
            id="cheque-branch"
            value={text.branch_name}
            onChange={(e) => setText({ ...text, branch_name: e.target.value })}
          />
        </div>

        <div className={issued ? '' : 'sm:col-span-2'}>
          <Label htmlFor="cheque-holder">صاحب حساب</Label>
          <Input
            id="cheque-holder"
            value={text.account_holder}
            onChange={(e) => setText({ ...text, account_holder: e.target.value })}
          />
        </div>

        {issued && (
          <div>
            <Label>حساب بانکی مبدأ</Label>
            {/* Required for an issued cheque and meaningless for a received one, so it is
                absent rather than disabled — a disabled field still reads as something the
                shopkeeper failed to fill in. */}
            <Select value={accountId} onValueChange={setAccountId}>
              <SelectTrigger>
                <SelectValue placeholder="انتخاب کنید" />
              </SelectTrigger>
              <SelectContent>
                {accounts.map((account) => (
                  <SelectItem key={account.id} value={String(account.id)}>
                    {account.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            {errors.account_id && <p className="mt-1 text-sm text-danger">{errors.account_id}</p>}
          </div>
        )}
      </div>

      <div className="mt-5 flex gap-3">
        <Button onClick={submit} disabled={busy}>
          {busy ? 'در حال ثبت…' : 'ثبت چک'}
        </Button>
        <Button variant="ghost" onClick={() => setOpen(false)} disabled={busy}>
          انصراف
        </Button>
      </div>
    </section>
  );
}
