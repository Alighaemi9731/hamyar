<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\CRM\Models\Party;
use App\Modules\Messaging\Jobs\SendSmsJob;
use App\Modules\Messaging\Models\Campaign;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Putting a campaign on the queue, spread out.
 *
 * ## Throttling is a delay per message, not a batch size
 *
 * `per_minute` becomes a per-message `delay()`, so four thousand messages at sixty a minute
 * arrive over roughly sixty-six hours of queue time rather than four thousand jobs hitting
 * the gateway at once. Gateways rate-limit; a burst gets throttled, retried, and charged for
 * the retries.
 *
 * Spreading at dispatch rather than with a worker rate limiter keeps the decision in one
 * place and visible: a shop that sets `per_minute` low can see when its campaign will
 * finish, because the answer is arithmetic rather than a property of how many workers happen
 * to be running.
 *
 * ## Every message still goes through the door
 *
 * The audience already excludes opted-out numbers so the shop's count is honest, and
 * {@see SendSms} refuses them again on the way out. Both, deliberately: the first is for the
 * estimate a shopkeeper reads before spending, the second is the guarantee.
 */
final class SendCampaign
{
    public function __construct(
        private readonly CampaignAudience $audience,
        private readonly TemplateRenderer $renderer,
    ) {}

    /**
     * @return array{queued: int, skipped: int}
     */
    public function send(Campaign $campaign): array
    {
        if (! $campaign->isSendable()) {
            throw new RuntimeException('این کمپین قابل ارسال نیست؛ الگوی تأییدشده و وضعیت پیش‌نویس لازم است.');
        }

        $campaign->forceFill([
            'status' => Campaign::STATUS_SENDING,
            'started_at' => CarbonImmutable::now(),
        ])->save();

        $perMinute = max(1, $campaign->per_minute);
        $queued = 0;
        $skipped = 0;
        $index = 0;

        $renderer = $this->renderer;

        $this->audience->query($campaign)->chunkById(200, function ($parties) use ($campaign, $perMinute, $renderer, &$queued, &$skipped, &$index): void {
            foreach ($parties as $party) {
                /** @var Party $party */
                $phone = $party->primaryMobile();

                if ($phone === null) {
                    $skipped++;

                    continue;
                }

                // Spread over time: message N goes out at N ÷ per_minute minutes from now.
                $delay = CarbonImmutable::now()->addSeconds((int) floor($index * 60 / $perMinute));

                SendSmsJob::dispatch(
                    $phone,
                    (string) $campaign->provider_template_id,
                    $renderer->tokensFor($campaign->body, ['name' => $party->name, 'shop' => config()->string('app.name')]),
                    templateKey: 'campaign:'.$campaign->id,
                    partyId: (int) $party->id,
                    // Keyed on campaign AND party: a campaign re-sent by a double click
                    // reaches nobody twice, and the same customer in two campaigns gets both.
                    idempotencyKey: "campaign:{$campaign->id}:{$party->id}",
                    branchId: $campaign->branch_id,
                )->delay($delay);

                $queued++;
                $index++;
            }
        });

        $campaign->forceFill([
            'status' => Campaign::STATUS_SENT,
            'finished_at' => CarbonImmutable::now(),
            'queued_count' => $queued,
            'skipped_count' => $skipped,
        ])->save();

        return ['queued' => $queued, 'skipped' => $skipped];
    }
}
