<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\CRM\Models\Party;
use App\Modules\Messaging\Enums\AutomationKey;
use App\Modules\Messaging\Jobs\SendSmsJob;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Support\Settings\ShopSettings;
use Illuminate\Database\Eloquent\Model;

/**
 * The gate every automatic message passes before it becomes a job.
 *
 * ## Four questions, in this order, and all of them here
 *
 * 1. Has the shop switched this automation on? Default is **no** — see
 *    {@see \App\Support\Settings\MessagingSettings} on why that direction is not a
 *    preference.
 * 2. Is there a template, and does it point at a registered pattern?
 * 3. Is there a customer with a number to send to?
 * 4. What are the tokens, in the order the sentence puts them?
 *
 * Opt-out is deliberately NOT on that list. It is checked in {@see SendSms}, at the door
 * every message goes through, because a check here would be one of nine callers getting it
 * right — and the one that got it wrong would be the birthday message. `AutomationOptOutTest`
 * asserts suppression separately for **each** automation anyway: the door is where the
 * guarantee lives, and per-automation tests are how we know no automation found a way round
 * it.
 *
 * ## Nothing is sent inline
 *
 * Every automation queues. A repair status change must not wait on a gateway, and a sweep
 * touching four hundred instalments must not hold a transaction open while it does.
 */
final class Automations
{
    public function __construct(
        private readonly ShopSettings $settings,
        private readonly TemplateRenderer $renderer,
    ) {}

    /**
     * Queue one automatic message, if everything says it should go.
     *
     * @param  array<string, string|int|null>  $values  template variables by name
     * @param  string|null  $idempotencyKey  period-keyed for swept automations; see
     *                                       docs/specs/treasury.md
     */
    public function fire(
        AutomationKey $key,
        ?Party $party,
        array $values,
        ?string $idempotencyKey = null,
        ?Model $reference = null,
        ?int $branchId = null,
    ): bool {
        if (! $this->settings->messaging()->isEnabled($key)) {
            return false;
        }

        $template = MessageTemplate::query()
            ->where('automation_key', $key->value)
            ->first();

        if (! $template instanceof MessageTemplate) {
            return false;
        }

        $resolved = $this->renderer->resolve($template, $values);

        if ($resolved === null) {
            return false;
        }

        $phone = $this->phoneFor($party);

        if ($phone === null) {
            return false;
        }

        SendSmsJob::dispatch(
            $phone,
            $resolved['template_id'],
            $resolved['tokens'],
            templateKey: $key->value,
            partyId: $party?->id,
            idempotencyKey: $idempotencyKey,
            branchId: $branchId,
        );

        return true;
    }

    /**
     * The customer's mobile number.
     *
     * `Party::primaryMobile()` already answers this — «the number the counter actually
     * dials» — and re-deriving it here would give the shop two answers to one question the
     * first time somebody changes what "primary" means.
     *
     * Null when there is nobody to text: a walk-in with no party, or a party whose only
     * contact is a landline. Returning null rather than queueing a doomed job keeps the
     * messages screen free of rows nobody could have prevented.
     */
    private function phoneFor(?Party $party): ?string
    {
        return $party?->loadMissing('contacts')->primaryMobile();
    }
}
