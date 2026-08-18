<?php

declare(strict_types=1);

namespace App\Support\Timeline;

use Carbon\CarbonImmutable;
use Closure;
use Throwable;

/**
 * The 360° customer timeline, assembled from every module that has something to say.
 *
 * The same problem `DocumentRegistry` solves, one level up. CRM owns the customer page,
 * but most of what a shop wants to see there belongs to other modules: what they bought
 * (Sales), what was repaired (Repairs), what they were texted (Messaging), what the shop
 * bought *from* them (Purchasing). CRM may not import any of it (ADR 0003), and a
 * timeline that CRM assembles by hand would need editing every time a module lands.
 *
 * So each module registers a contributor for its own records, keyed by module name, and
 * the party page asks here. Adding Sales in Phase 5 is one `contribute()` call in the
 * Sales service provider and no change to CRM at all.
 *
 * ## Contributors are given an id, not a model
 *
 * A `Party` object would be a CRM class, which is exactly the dependency this exists to
 * avoid. Contributors receive the party id and a window.
 *
 * ## One slow contributor must not blank the page
 *
 * A contributor that throws is skipped and logged, not propagated. A customer page that
 * cannot render because the SMS module had a bad day is worse than the same page missing
 * its SMS lines — and the missing module is named in the response so the gap is visible
 * rather than silent.
 *
 * Registered as a singleton in `App\Providers\AppServiceProvider`.
 */
final class TimelineRegistry
{
    /** @var array<string, Closure(int, CarbonImmutable|null, CarbonImmutable|null): list<TimelineEntry>> */
    /*
    | ADR 0012 audit: no tenant in the key, and correctly so.
    |
    | This holds CALLBACKS registered by service providers at boot — a table of code, not
    | of shop data. It is identical for every tenant and is never written to at request
    | time. The rows those callbacks go on to read are scoped by RLS when they run.
    */
    private array $contributors = [];

    /**
     * @param  string  $module  the module's own name, e.g. "Purchasing"
     * @param  Closure(int, CarbonImmutable|null, CarbonImmutable|null): list<TimelineEntry>  $contributor
     */
    public function contribute(string $module, Closure $contributor): void
    {
        $this->contributors[$module] = $contributor;
    }

    /**
     * Everything known about a party, newest first.
     *
     * @return array{entries: list<TimelineEntry>, failed: list<string>}
     */
    public function for(
        int $partyId,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        int $limit = 100,
    ): array {
        $entries = [];
        $failed = [];

        foreach ($this->contributors as $module => $contributor) {
            try {
                foreach ($contributor($partyId, $from, $to) as $entry) {
                    $entries[] = $entry;
                }
            } catch (Throwable $exception) {
                // Named, not swallowed: the page says which module is missing.
                $failed[] = $module;
                report($exception);
            }
        }

        // Sorted here rather than in SQL — the rows come from several tables in several
        // modules and there is no single query to order them by.
        usort(
            $entries,
            static fn (TimelineEntry $a, TimelineEntry $b): int => $b->occurredAt <=> $a->occurredAt
        );

        return ['entries' => array_slice($entries, 0, $limit), 'failed' => $failed];
    }

    /**
     * @return list<string>
     */
    public function contributors(): array
    {
        return array_keys($this->contributors);
    }
}
