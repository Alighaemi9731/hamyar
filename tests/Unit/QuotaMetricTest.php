<?php

declare(strict_types=1);

use App\Support\Quota\Metric;
use App\Support\Quota\MetricRegistry;
use App\Support\Quota\UnknownMetric;
use App\Support\Quota\Window;

/**
 * The registry's invariants — the ones that decide whether a metering bug is loud or
 * silent. Unit-level on purpose: none of this touches a table.
 */
function countedMetric(string $key = 'sales.invoices', int $position = 10): Metric
{
    return new Metric(
        key: $key,
        labelFa: 'فاکتور فروش',
        window: Window::Month,
        module: explode('.', $key)[0],
        unitFa: 'فاکتور',
        position: $position,
    );
}

it('rejects a key that is not module.noun', function (string $key): void {
    expect(fn (): Metric => new Metric(
        key: $key, labelFa: 'x', window: Window::Month, module: 'sales'
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'no module' => 'invoices',
    'capitals' => 'Sales.Invoices',
    'a dash' => 'sales.invoice-lines',
    'two dots' => 'sales.invoices.final',
    'empty' => '',
]);

it('rejects a key that does not belong to its module', function (): void {
    // The whole point of the prefix: `crm.parties` registered by Sales would put one
    // module's counter under another's name, and the first person to look would be
    // reading the wrong module's code to find out why.
    expect(fn (): Metric => new Metric(
        key: 'crm.parties', labelFa: 'x', window: Window::Month, module: 'sales'
    ))->toThrow(InvalidArgumentException::class, 'sales');
});

it('refuses a measure closure on a counted metric', function (): void {
    // Two answers to "how much has been used" and nothing to say which is right.
    expect(fn (): Metric => new Metric(
        key: 'sales.invoices', labelFa: 'x', window: Window::Month, module: 'sales',
        measure: static fn (int $tenantId): int => 0,
    ))->toThrow(InvalidArgumentException::class);
});

it('requires a measure closure on a standing capacity', function (): void {
    // There is no counter row to read, so a Total metric with no closure would report
    // zero usage for ever — a seat cap that never fires.
    expect(fn (): Metric => new Metric(
        key: 'identity.users', labelFa: 'x', window: Window::Total, module: 'identity',
    ))->toThrow(InvalidArgumentException::class);
});

it('measures a standing capacity through its own module closure', function (): void {
    $metric = new Metric(
        key: 'identity.users', labelFa: 'کاربر', window: Window::Total, module: 'identity',
        measure: static fn (int $tenantId): int => $tenantId * 2,
    );

    expect($metric->isCounted())->toBeFalse();
    expect($metric->measure(21))->toBe(42);
});

it('refuses to register the same key twice', function (): void {
    // Two modules sharing a counter row would each see the other's usage, and neither
    // would be wrong about its own numbers — the worst kind of wrong.
    $registry = new MetricRegistry;
    $registry->register(countedMetric());

    expect(fn () => $registry->register(countedMetric()))->toThrow(InvalidArgumentException::class, 'already registered');
});

it('throws on an unregistered key rather than metering nothing', function (): void {
    // A typo in a consume() call must be loud. Silently unlimited is a quota that never
    // fires and a bug that surfaces months later as missing revenue.
    expect(fn (): Metric => (new MetricRegistry)->get('sales.invoicez'))
        ->toThrow(UnknownMetric::class);
});

it('orders by position and splits counted from computed', function (): void {
    $registry = new MetricRegistry;
    $registry->register(
        countedMetric('repairs.tickets', position: 30),
        countedMetric('sales.invoices', position: 10),
        new Metric(
            key: 'identity.users', labelFa: 'کاربر', window: Window::Total, module: 'identity',
            position: 90, measure: static fn (int $tenantId): int => 0,
        ),
    );

    expect($registry->keys())->toBe(['sales.invoices', 'repairs.tickets', 'identity.users']);
    expect(array_map(fn ($m) => $m->key, $registry->counted()))->toBe(['sales.invoices', 'repairs.tickets']);
    expect(array_map(fn ($m) => $m->key, $registry->computed()))->toBe(['identity.users']);
    expect(array_keys($registry->byModule()))->toBe(['sales', 'repairs', 'identity']);
});
