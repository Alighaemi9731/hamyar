<?php

declare(strict_types=1);

use App\Modules\Platform\Models\Tenant;
use App\Support\Counters\Counter;
use App\Support\Counters\CounterService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\mock;

/**
 * Document numbering. The project convention forbids `MAX(number) + 1`, and these
 * assert the reason it is forbidden rather than merely that the helper returns integers.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->counters = app(CounterService::class);
});

afterEach(fn () => app(TenantContext::class)->forget());

it('counts up from one', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        /** @var int $id */
        $id = $this->tenant->getKey();

        $values = DB::transaction(fn (): array => [
            $this->counters->next($id, Counter::SALES_INVOICE),
            $this->counters->next($id, Counter::SALES_INVOICE),
            $this->counters->next($id, Counter::SALES_INVOICE),
        ]);

        expect($values)->toBe([1, 2, 3]);
    });
});

it('keeps separate sequences per key and per period', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        /** @var int $id */
        $id = $this->tenant->getKey();

        DB::transaction(function () use ($id): void {
            expect($this->counters->next($id, Counter::SALES_INVOICE))->toBe(1);
            // A different document type does not share the sales sequence…
            expect($this->counters->next($id, Counter::REPAIR_TICKET))->toBe(1);
            // …and neither does the same type in a new Jalali year.
            expect($this->counters->next($id, Counter::SALES_INVOICE, period: '1406'))->toBe(1);
            expect($this->counters->next($id, Counter::SALES_INVOICE))->toBe(2);
        });
    });
});

it('refuses to run outside a transaction', function (): void {
    // Outside one the row lock releases immediately, so the counter would happily hand
    // the same number to two concurrent callers. Failing loudly beats issuing duplicates.
    //
    // Driven through a stub connection rather than the real one: RefreshDatabase already
    // has every test inside a transaction, so the real connection can never report the
    // condition this guard exists to catch.
    /** @var ConnectionInterface $connection */
    $connection = mock(ConnectionInterface::class)
        ->shouldReceive('transactionLevel')->andReturn(0)
        ->getMock();

    expect(fn () => (new CounterService($connection))->next(1, Counter::SALES_INVOICE))
        ->toThrow(RuntimeException::class, 'must run inside a transaction');
});

it('formats a document number with padding and period', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        /** @var int $id */
        $id = $this->tenant->getKey();

        $number = DB::transaction(
            fn (): string => $this->counters->nextFormatted($id, Counter::SALES_INVOICE, 'INV', period: '1405')
        );

        expect($number)->toBe('INV-1405-000001');
    });
});

it('does not share a sequence between tenants', function (): void {
    pest()->group('isolation');

    $other = Tenant::factory()->create();

    /** @var int $mineId */
    $mineId = $this->tenant->getKey();
    /** @var int $theirsId */
    $theirsId = $other->getKey();

    $mine = app(TenantContext::class)->runFor(
        $this->tenant,
        fn (): int => DB::transaction(fn (): int => $this->counters->next($mineId, Counter::SALES_INVOICE))
    );

    $theirs = app(TenantContext::class)->runFor(
        $other,
        fn (): int => DB::transaction(fn (): int => $this->counters->next($theirsId, Counter::SALES_INVOICE))
    );

    // Both shops' first invoice is number 1. A shared sequence would leak how many
    // documents every other shop on the platform has issued.
    expect($mine)->toBe(1);
    expect($theirs)->toBe(1);
});
