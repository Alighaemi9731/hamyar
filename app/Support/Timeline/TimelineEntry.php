<?php

declare(strict_types=1);

namespace App\Support\Timeline;

use Carbon\CarbonImmutable;

/**
 * One thing that happened with a party, in a shape any module can produce and the
 * party page can render without knowing which module produced it.
 *
 * Money is an integer number of rial or null — never a formatted string, because the
 * page decides how to show it (golden rule 2, and the tenant's toman/rial setting).
 */
final readonly class TimelineEntry
{
    /**
     * @param  string  $kind  a stable key the UI maps to an icon and a tone:
     *                        purchase · purchase_return · payment · charge · device ·
     *                        note · follow_up · loyalty · sale · repair · sms
     * @param  int|null  $amount  integer RIAL, signed from the shop's point of view
     * @param  string|null  $url  where to go to see the thing itself
     */
    public function __construct(
        public CarbonImmutable $occurredAt,
        public string $kind,
        public string $title,
        public ?string $description = null,
        public ?int $amount = null,
        public ?string $url = null,
        public ?string $actor = null,
    ) {}

    /**
     * @return array{occurred_at: string, kind: string, title: string, description: string|null, amount: int|null, url: string|null, actor: string|null}
     */
    public function toArray(): array
    {
        return [
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'kind' => $this->kind,
            'title' => $this->title,
            'description' => $this->description,
            'amount' => $this->amount,
            'url' => $this->url,
            'actor' => $this->actor,
        ];
    }
}
