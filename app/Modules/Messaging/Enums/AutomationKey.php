<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Enums;

/**
 * Every automatic message the shop can send, and what fires it.
 *
 * ## The trigger column is a real event class, not a name
 *
 * Each case below documents the emitter it hangs off — `TicketStatusChanged`,
 * `InvoiceFinalised`, `TicketEscalated` — all of which existed before this module and are
 * dispatched by Phases 5 to 7. Nothing here invents an event name: a synthetic one drifts
 * from its emitter the first time somebody renames the real thing, and the automation goes
 * quiet with no test failing.
 *
 * The three date-driven cases have no emitter, because nothing happens when a due date
 * arrives. They are swept by {@see \App\Modules\Messaging\Services\DailyMessagingSweep}
 * and keyed by period, per `docs/specs/treasury.md`.
 */
enum AutomationKey: string
{
    /** Fired by `Sales\Events\InvoiceFinalised`. */
    case InvoiceIssued = 'invoice.issued';

    /** Fired by `Repairs\Events\TicketStatusChanged` — any transition. */
    case RepairStatusChanged = 'repair.status_changed';

    /** Fired by `Repairs\Events\TicketStatusChanged` where `to` is `ready`. */
    case RepairReady = 'repair.ready';

    /** Fired by `Repairs\Events\TicketEscalated` — the رسوبی ladder. */
    case RepairAbandonedStep = 'repair.abandoned_step';

    /** Swept: three days before an instalment falls due. */
    case InstallmentDueSoon = 'installment.due_soon';

    /** Swept: on the due date. */
    case InstallmentDueToday = 'installment.due_today';

    /** Swept: after the due date has passed and nothing was collected. */
    case InstallmentOverdue = 'installment.overdue';

    /** Swept: two days before a cheque's due date. */
    case ChequeDueSoon = 'cheque.due_soon';

    /** Swept: on the customer's birthday. */
    case Birthday = 'birthday';

    public function labelFa(): string
    {
        return match ($this) {
            self::InvoiceIssued => 'صدور فاکتور',
            self::RepairStatusChanged => 'تغییر وضعیت تعمیر',
            self::RepairReady => 'آماده شدن دستگاه',
            self::RepairAbandonedStep => 'یادآوری دستگاه رسوبی',
            self::InstallmentDueSoon => 'سه روز مانده به سررسید قسط',
            self::InstallmentDueToday => 'سررسید قسط',
            self::InstallmentOverdue => 'قسط معوق',
            self::ChequeDueSoon => 'نزدیک سررسید چک',
            self::Birthday => 'تولد مشتری',
        };
    }

    /**
     * The variables a template for this automation may use.
     *
     * Declared so the template editor can show them and refuse a template using one that
     * will never be filled — a `{amount}` in a birthday message renders as an empty token
     * and the customer gets a sentence with a hole in it.
     *
     * @return list<string>
     */
    public function variables(): array
    {
        return match ($this) {
            self::InvoiceIssued => ['name', 'invoice_number', 'amount', 'shop'],
            self::RepairStatusChanged, self::RepairReady => ['name', 'ticket_code', 'device', 'status', 'shop'],
            self::RepairAbandonedStep => ['name', 'ticket_code', 'device', 'days', 'shop'],
            self::InstallmentDueSoon, self::InstallmentDueToday, self::InstallmentOverdue => ['name', 'amount', 'due_date_j', 'plan_number', 'shop'],
            self::ChequeDueSoon => ['name', 'amount', 'due_date_j', 'serial', 'shop'],
            self::Birthday => ['name', 'shop'],
        };
    }

    /**
     * Date-driven — nothing emits an event, so a sweep has to look.
     */
    public function isSwept(): bool
    {
        return in_array($this, [
            self::InstallmentDueSoon, self::InstallmentDueToday, self::InstallmentOverdue,
            self::ChequeDueSoon, self::Birthday,
        ], true);
    }
}
