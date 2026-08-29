<?php

declare(strict_types=1);

namespace App\Support\Quota;

use Closure;
use InvalidArgumentException;

/**
 * One thing a plan sells an amount of.
 *
 * A metric is declared by the module that owns the action — Sales declares
 * `sales.invoices`, Repairs declares `repairs.tickets` — and registered with
 * {@see MetricRegistry} from that module's service provider. Platform never lists them:
 * the Filament limits editor, the landing pricing rows, the billing page, the shared
 * `usage` prop and the analytics widgets all iterate the registry, so shipping a new
 * metered action is a change in one module and nowhere else (golden rule 6).
 *
 * ## The window carries the whole distinction
 *
 * A {@see Window::Month} metric is **counted**: every consume increments a
 * `usage_counters` row for the current period, and usage is that row. A
 * {@see Window::Total} metric is **computed**: there is no row, and usage is whatever
 * `$measure` returns for live rows right now.
 *
 * That is why there is no second "kind" enum. Two enums that must agree are two places to
 * disagree, and the constructor enforces the pairing instead: a `Total` metric must bring
 * a way to measure itself, and a `Month` metric must not, because a counted metric that
 * also had a measure closure would have two answers to "how much has been used" and
 * nothing to say which is right.
 *
 * ## Counted, never derived
 *
 * A counted metric is never `COUNT(*)` over its own table, and the closure is not an
 * escape hatch to make it one. Products, parties, units, invoices and branches soft
 * delete; contacts, payments and invoice lines are hard-deleted and recreated on every
 * edit; `sales_invoices` holds drafts, quotes and finals together; a voided invoice keeps
 * its row. Every module has at least one reason a live count would be wrong, and a shop
 * that deleted a product to buy back its credit would be right to.
 */
final readonly class Metric
{
    /**
     * @param  string  $key  `<module>.<noun_plural>`, e.g. `sales.invoices`
     * @param  string  $labelFa  what the shopkeeper calls the thing, «فاکتور فروش»
     * @param  string  $module  the owning module's code; the key must be prefixed with it
     * @param  string  $unitFa  the counting word, so copy reads «۳۰۰ فاکتور در ماه»
     * @param  int  $position  ordering on the pricing page, the billing page and Filament
     * @param  bool  $landing  is this one of the headline numbers the pricing rows show
     * @param  (Closure(int): int)|null  $measure  Total metrics only: live usage for a tenant id
     */
    public function __construct(
        public string $key,
        public string $labelFa,
        public Window $window,
        public string $module,
        public string $unitFa = 'مورد',
        public int $position = 100,
        public bool $landing = false,
        public ?Closure $measure = null,
    ) {
        if (preg_match('/^[a-z]+\.[a-z_]+$/', $key) !== 1 || mb_strlen($key) > 48) {
            throw new InvalidArgumentException(
                "Metric key [{$key}] must look like `module.noun_plural` and be at most 48 characters."
            );
        }

        if (! str_starts_with($key, $module.'.')) {
            throw new InvalidArgumentException(
                "Metric key [{$key}] must be prefixed with its owning module [{$module}]."
            );
        }

        if ($window->isCounted() && $measure instanceof Closure) {
            throw new InvalidArgumentException(
                "Metric [{$key}] is counted, so its usage is the counter row. A measure closure would be a second answer to the same question."
            );
        }

        if (! $window->isCounted() && ! $measure instanceof Closure) {
            throw new InvalidArgumentException(
                "Metric [{$key}] is a standing capacity and must bring a measure closure: there is no counter row to read."
            );
        }
    }

    public function isCounted(): bool
    {
        return $this->window->isCounted();
    }

    /**
     * Live usage for one tenant. Counted metrics have none — read the counter instead.
     */
    public function measure(int $tenantId): int
    {
        if (! $this->measure instanceof Closure) {
            throw new InvalidArgumentException("Metric [{$this->key}] is counted and cannot be measured.");
        }

        return ($this->measure)($tenantId);
    }
}
