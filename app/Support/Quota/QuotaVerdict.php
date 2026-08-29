<?php

declare(strict_types=1);

namespace App\Support\Quota;

use Carbon\CarbonImmutable;

/**
 * What the guard decided, and everything a screen needs to explain it.
 *
 * Carries the *reason* rather than a bare boolean, because every consumer needs a
 * different part of it: the exception handler needs the numbers for the Persian sentence,
 * the shared `usage` prop needs the ratio for a meter, the block screen needs
 * `nextPlanCode` to aim the upgrade button, and the analytics ledger needs `requested` to
 * answer "how much were they trying to do when we stopped them".
 */
final readonly class QuotaVerdict
{
    /**
     * @param  int  $used  usage AFTER this consume when allowed, as it stands when refused
     * @param  int|null  $limit  null = unlimited
     * @param  int  $requested  how many units were asked for
     * @param  string|null  $periodKey  null for a standing capacity, which has no period
     * @param  string|null  $nextPlanCode  the cheapest public plan that would fit, if any
     */
    public function __construct(
        public string $metric,
        public Window $window,
        public int $used,
        public ?int $limit,
        public int $requested,
        public ?string $periodKey,
        public ?CarbonImmutable $resetsAt,
        public bool $allowed,
        public ?string $nextPlanCode = null,
    ) {}

    public function isUnlimited(): bool
    {
        return $this->limit === null;
    }

    /**
     * Units left, or null when unlimited. Never negative: a limit lowered in the panel
     * under a shop that has already spent more leaves it at zero, not in debt.
     */
    public function remaining(): ?int
    {
        return $this->limit === null ? null : max(0, $this->limit - $this->used);
    }

    /**
     * How full the credit is, 0.0–1.0, or null when unlimited.
     */
    public function ratio(): ?float
    {
        if ($this->limit === null) {
            return null;
        }

        if ($this->limit === 0) {
            // A zero limit is "not on this plan". It is full by definition, and dividing
            // would be a fatal error on a screen.
            return 1.0;
        }

        return min(1.0, $this->used / $this->limit);
    }
}
