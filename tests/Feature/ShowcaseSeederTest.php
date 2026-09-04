<?php

declare(strict_types=1);

/**
 * `ShowcaseSeeder` produces a shop worth photographing.
 *
 * The landing page's screenshots are captured from the seeded demo tenant. On 2026-09-03
 * that tenant had one sale, no repairs, no cheques and no instalments, so every capture of
 * the dashboard, the repairs board, the collections desk and the profit report would have
 * been a picture of an empty product. This test pins the shape the screenshots depend on:
 * not exact figures — those are the seeder's business — but that every screen the tour
 * shows has something true to show.
 *
 * It runs the real services end to end (the seeder drives them; it inserts nothing a
 * service owns), so it is also the broadest smoke test in the suite: a month of trading
 * through sales, purchasing, repairs, cheques, instalments, treasury and messaging, on a
 * clock parked in the past.
 */

use App\Modules\Cheques\Enums\ChequeStatus;
use App\Modules\Cheques\Models\Cheque;
use App\Modules\Installments\Models\InstallmentRow;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Repairs\Enums\TicketStatus;
use App\Modules\Repairs\Models\RepairTicket;
use App\Modules\Sales\Enums\InvoiceStatus;
use App\Modules\Sales\Models\SalesInvoice;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DemoShopSeeder;
use Database\Seeders\ShowcaseSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    // Provisioned the way `make fresh` provisions it, so the branch, warehouse, price
    // levels, accounts and roles the seeders assume are the real ones.
    $this->tenant = app(TenantProvisioner::class)->provision([
        'name' => 'موبایل دمو',
        'subdomain' => 'demo',
        'owner_name' => 'رضا محمدی',
        'owner_mobile' => '09121234567',
        'owner_email' => 'admin@demo.test',
        'password' => 'password',
    ]);

    (new DemoShopSeeder)->run();
    (new ShowcaseSeeder)->run();

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

it('gives the dashboard chart a month with a shape', function (): void {
    ($this->inTenant)(function (): void {
        $final = SalesInvoice::query()->where('status', InvoiceStatus::Final);

        expect($final->count())->toBeGreaterThanOrEqual(40);

        $days = DB::table('sales_invoices')
            ->where('status', InvoiceStatus::Final->value)
            ->selectRaw("count(distinct date(issued_at at time zone 'Asia/Tehran')) as days")
            ->value('days');
        $days = is_numeric($days) ? (int) $days : 0;

        // A chart is a shape: the invoices must be spread, not stacked on one day.
        expect($days)->toBeGreaterThanOrEqual(20);
    });
});

it('fills every column of the repairs board, including the abandoned one', function (): void {
    ($this->inTenant)(function (): void {
        $statuses = RepairTicket::query()->get()->map(fn (RepairTicket $ticket): string => $ticket->status->value)->unique();

        expect(RepairTicket::query()->count())->toBeGreaterThanOrEqual(12)
            ->and($statuses->count())->toBeGreaterThanOrEqual(5)
            ->and($statuses)->toContain(TicketStatus::Abandoned->value)
            ->and($statuses)->toContain(TicketStatus::Delivered->value)
            ->and($statuses)->toContain(TicketStatus::Repairing->value);

        // A delivered repair is an invoice, made through the same service the screen uses.
        expect(RepairTicket::query()->where('status', TicketStatus::Delivered)->whereNotNull('sales_invoice_id')->count())
            ->toBeGreaterThanOrEqual(2);
    });
});

it('leaves the collections desk with work on it', function (): void {
    ($this->inTenant)(function (): void {
        $overdue = InstallmentRow::query()
            ->where('status', InstallmentRow::STATUS_PENDING)
            ->whereDate('due_at', '<', now()->toDateString())
            ->count();

        $paid = InstallmentRow::query()->where('status', InstallmentRow::STATUS_PAID)->count();

        // Overdue is derived from the date, never stored — so this is what the desk reads.
        expect($overdue)->toBeGreaterThanOrEqual(3)
            ->and($paid)->toBeGreaterThanOrEqual(4);
    });
});

it('puts a cheque in every state the register names', function (): void {
    ($this->inTenant)(function (): void {
        $statuses = Cheque::query()->get()->map(fn (Cheque $cheque): string => $cheque->status->value);

        expect(Cheque::query()->count())->toBeGreaterThanOrEqual(8)
            ->and($statuses)->toContain(ChequeStatus::Cleared->value)
            ->and($statuses)->toContain(ChequeStatus::Bounced->value);

        $overdueInHand = Cheque::query()
            ->where('status', ChequeStatus::InHand->value)
            ->whereDate('due_date', '<', now()->toDateString())
            ->count();

        expect($overdueInHand)->toBeGreaterThanOrEqual(1);
    });
});

it('leaves two lines under their reorder threshold', function (): void {
    ($this->inTenant)(function (): void {
        // The two thin lines: stocked at 3 and 2 against a threshold of 12, never sold.
        $onHand = DB::table('stock_movements')
            ->join('product_variants', 'product_variants.id', '=', 'stock_movements.product_variant_id')
            ->whereIn('product_variants.sku', ['ACC-LENS', 'ACC-CAR-CHG'])
            ->groupBy('product_variants.sku')
            ->selectRaw('product_variants.sku, sum(stock_movements.quantity) as on_hand')
            ->pluck('on_hand', 'sku');

        expect($onHand)->toHaveCount(2);

        foreach ($onHand as $sku => $quantity) {
            expect(is_numeric($quantity) ? (int) $quantity : PHP_INT_MAX)
                ->toBeLessThanOrEqual(12, "{$sku} is not under its threshold");
        }
    });
});

it('is a no-op the second time', function (): void {
    ($this->inTenant)(fn () => expect(RepairTicket::query()->count())->toBeGreaterThan(0));

    $before = ($this->inTenant)(fn (): int => SalesInvoice::query()->count());

    (new ShowcaseSeeder)->run();

    $after = ($this->inTenant)(fn (): int => SalesInvoice::query()->count());

    expect($after)->toBe($before);
});
