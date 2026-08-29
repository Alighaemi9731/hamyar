<?php

declare(strict_types=1);

namespace App\Support\Quota;

use InvalidArgumentException;

/**
 * Every metric the application knows how to meter.
 *
 * The third of the shared-kernel registries, and the same shape as
 * {@see \App\Support\Documents\DocumentRegistry} and
 * {@see \App\Support\Timeline\TimelineRegistry}: the owning module registers what it
 * knows in its own service provider, and whoever needs the list asks here. Platform
 * implements the guard and never enumerates metrics; Sales never imports Platform.
 *
 * Registered as a singleton in `App\Providers\AppServiceProvider`.
 *
 * ## ADR 0012 audit
 *
 * No tenant in any key, and correctly so: a metric is a property of the *application* —
 * its key, label, window and unit are identical for every shop. What differs per tenant is
 * the limit and the usage, and neither is held here. The `measure` closures run under the
 * caller's tenant context and take the tenant id as an argument rather than closing over
 * one.
 *
 * ## Registration happens at boot, and the order does not matter
 *
 * Modules register with `afterResolving()` rather than by resolving the registry in their
 * own `register()`, so a provider discovered later cannot lose to one discovered earlier —
 * the `bindIf` lesson (CLAUDE.md) applied to a registry. The consequence to know: nothing
 * may resolve this during the register phase, or metrics declared after that first build
 * are silently absent. `quota:audit` asserts the full set is present after boot.
 */
final class MetricRegistry
{
    /** @var array<string, Metric> */
    private array $metrics = [];

    /**
     * Add one or more metrics. A duplicate key is a bug, not a silent overwrite: two
     * modules metering the same key would share a counter row and each see the other's
     * usage.
     */
    public function register(Metric ...$metrics): void
    {
        foreach ($metrics as $metric) {
            if (array_key_exists($metric->key, $this->metrics)) {
                throw new InvalidArgumentException(
                    "Metric [{$metric->key}] is already registered. Two modules sharing one key would share one counter row."
                );
            }

            $this->metrics[$metric->key] = $metric;
        }
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->metrics);
    }

    /**
     * @throws UnknownMetric when nothing registered that key — a typo in a `consume()`
     *                       call must fail loudly rather than meter nothing forever
     */
    public function get(string $key): Metric
    {
        return $this->metrics[$key] ?? throw new UnknownMetric(
            "Unknown metric [{$key}]. Register it in its module's service provider."
        );
    }

    /**
     * Every metric, in display order.
     *
     * @return list<Metric>
     */
    public function all(): array
    {
        $metrics = array_values($this->metrics);

        usort($metrics, static fn (Metric $a, Metric $b): int => [$a->position, $a->key] <=> [$b->position, $b->key]);

        return $metrics;
    }

    /**
     * @return list<Metric>
     */
    public function counted(): array
    {
        return array_values(array_filter($this->all(), static fn (Metric $m): bool => $m->isCounted()));
    }

    /**
     * @return list<Metric>
     */
    public function computed(): array
    {
        return array_values(array_filter($this->all(), static fn (Metric $m): bool => ! $m->isCounted()));
    }

    /**
     * Grouped by owning module, for the Filament limits editor.
     *
     * @return array<string, list<Metric>>
     */
    public function byModule(): array
    {
        $grouped = [];

        foreach ($this->all() as $metric) {
            $grouped[$metric->module][] = $metric;
        }

        return $grouped;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(static fn (Metric $m): string => $m->key, $this->all());
    }
}
