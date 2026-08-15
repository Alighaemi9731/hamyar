<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use Illuminate\Support\Facades\DB;

/**
 * What the shop's automations did, and what they cost.
 *
 * ## SMS is billed by segment, so segments are the unit, not messages
 *
 * A Persian SMS is 70 characters per segment against 160 for Latin, and a template that
 * grew by one polite word silently doubled the bill on every message it sends. A report
 * counting *messages* cannot show that; a report counting segments shows it as the number
 * beside the template. So both are reported and the cost sits next to them.
 *
 * ## Grouped by template, because that is the thing somebody can change
 *
 * «۴۰۰ پیامک فرستادیم» is not actionable. «یادآوری قسط، ۴۰۰ پیامک، ۸۰۰ بخش» is: the shop
 * can shorten that one template, or stop sending it two days early as well as on the day.
 * A message with no template behind it — a one-off typed at the counter — is kept under a
 * single named row rather than dropped, so the rows sum to the wallet.
 *
 * ## Failed and suppressed messages are counted apart, and only one of them costs money
 *
 * A suppressed message is one the opt-out list stopped, and it is a *success*: the shop did
 * not text somebody who asked not to be texted. A failed one is money spent on nothing if
 * the provider charged for it, which is why `cost` is summed from what was actually
 * recorded against each row rather than inferred from the count.
 */
final class OperationsReports
{
    /**
     * SMS usage per template over the range.
     *
     * @return list<array{template: string, label: string, sent: int, failed: int, suppressed: int, queued: int, messages: int, segments: int, cost: int}>
     */
    public function smsUsage(ReportPeriod $period): array
    {
        $rows = DB::table('messages')
            ->whereBetween('messages.queued_at', [$period->from, $period->to])
            ->groupBy('messages.template_key')
            ->orderByRaw('coalesce(sum(messages.cost), 0) desc, count(*) desc')
            ->selectRaw("
                coalesce(messages.template_key, '') as template,
                count(*) as messages,
                count(*) filter (where messages.status = 'sent') as sent,
                count(*) filter (where messages.status = 'failed') as failed,
                count(*) filter (where messages.status = 'suppressed') as suppressed,
                count(*) filter (where messages.status = 'queued') as queued,
                coalesce(sum(messages.segments), 0) as segments,
                coalesce(sum(messages.cost), 0) as cost
            ")
            ->get();

        $shaped = [];

        foreach ($rows as $row) {
            $values = (array) $row;
            $template = $this->stringOf($values['template'] ?? '');

            $shaped[] = [
                'template' => $template,
                'label' => $template === '' ? 'بدون قالب (دستی)' : $template,
                'sent' => $this->intOf($values['sent'] ?? 0),
                'failed' => $this->intOf($values['failed'] ?? 0),
                'suppressed' => $this->intOf($values['suppressed'] ?? 0),
                'queued' => $this->intOf($values['queued'] ?? 0),
                'messages' => $this->intOf($values['messages'] ?? 0),
                'segments' => $this->intOf($values['segments'] ?? 0),
                'cost' => $this->intOf($values['cost'] ?? 0),
            ];
        }

        return $shaped;
    }

    /**
     * The SMS wallet: what went in during the range, what came out, and what is left now.
     *
     * ## The balance ignores the range, and says so
     *
     * Top-ups and charges are *of* the range; the balance is a fact about **now**, because
     * "what was my SMS credit on the 12th of Mordad" is a question nobody asks and «چقدر
     * اعتبار دارم» is one they ask constantly. Reporting a historical balance under a
     * heading that reads as current is how a shop runs out of credit mid-campaign.
     *
     * It is a SUM over `sms_credit_entries` rather than a stored figure, like every other
     * balance in this product (golden rule 3).
     *
     * @return array{balance: int, topups: int, charges: int, refunds: int}
     */
    public function smsWallet(ReportPeriod $period): array
    {
        $balance = DB::table('sms_credit_entries')->sum('amount');

        $row = DB::table('sms_credit_entries')
            ->whereBetween('occurred_at', [$period->from, $period->to])
            ->selectRaw("
                coalesce(sum(case when type = 'topup' then amount else 0 end), 0) as topups,
                coalesce(sum(case when type = 'refund' then amount else 0 end), 0) as refunds,
                coalesce(-sum(case when type = 'charge' then amount else 0 end), 0) as charges
            ")
            ->first();

        $values = (array) $row;

        return [
            'balance' => $this->intOf($balance),
            'topups' => $this->intOf($values['topups'] ?? 0),
            // Reported positive. A charge is stored negative — the wallet's sign convention
            // — and a column of negative numbers under a heading that already says «مصرف»
            // makes a reader check whether it is a credit.
            'charges' => $this->intOf($values['charges'] ?? 0),
            'refunds' => $this->intOf($values['refunds'] ?? 0),
        ];
    }

    private function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function stringOf(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
