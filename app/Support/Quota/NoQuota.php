<?php

declare(strict_types=1);

namespace App\Support\Quota;

/**
 * The null object: everything is allowed and nothing is counted.
 *
 * Bound with `bindIf` in `AppServiceProvider` so the container always has an answer, and
 * overridden by Platform's real guard with `singleton`. `bindIf` on the default and
 * `bind` on the implementation is the shape CLAUDE.md documents after the `PartyExposure`
 * incident: two providers binding the same interface with `bind` means a directory
 * listing decides which one wins, and the symptom is not a crash but a guard that
 * silently passes.
 *
 * ## When this is actually the bound one
 *
 * Only where Platform is not in play at all: a unit test, an artisan command running
 * before providers are loaded, or a test that opted out with `withUnlimitedQuota()`. In
 * an ordinary request Platform's provider has always overridden it — and if that ever
 * stops being true, `quota:audit` says so, because a product whose limits silently do
 * nothing is the failure this whole phase exists to end.
 *
 * It reports `limit: null` (unlimited) rather than a large number, so any screen that
 * renders a verdict from here shows «نامحدود» instead of a meter that is wrong.
 */
final class NoQuota implements QuotaGuard
{
    public function __construct(private readonly MetricRegistry $registry) {}

    public function check(string $metric, int $n = 1): QuotaVerdict
    {
        return $this->allow($metric, $n);
    }

    public function consume(string $metric, int $n = 1): QuotaVerdict
    {
        return $this->allow($metric, $n);
    }

    public function record(string $metric, int $n = 1): QuotaVerdict
    {
        return $this->allow($metric, $n);
    }

    /**
     * @return list<QuotaVerdict>
     */
    public function snapshot(): array
    {
        return [];
    }

    private function allow(string $metric, int $n): QuotaVerdict
    {
        // Resolved rather than assumed: an unregistered key is a bug in the caller and
        // must fail here too, or a typo would pass every test that runs without Platform.
        $registered = $this->registry->get($metric);

        return new QuotaVerdict(
            metric: $registered->key,
            window: $registered->window,
            used: 0,
            limit: null,
            requested: $n,
            periodKey: null,
            resetsAt: null,
            allowed: true,
        );
    }
}
