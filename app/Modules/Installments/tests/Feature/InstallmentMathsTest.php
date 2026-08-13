<?php

declare(strict_types=1);

use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Modules\Installments\Models\InstallmentPlan;
use App\Modules\Installments\Models\InstallmentRow;
use App\Modules\Installments\Services\InstallmentMaths;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * The instalment maths from `docs/specs/installment-collection.md`, pinned rule by rule.
 *
 * **This file mirrors that document section by section**, and every worked example in the
 * spec appears here as an assertion on the exact rial. Same standard as the cheque posting
 * matrix and ADR 0009's rounding rules: a change to the spec without a change here, or the
 * reverse, is visibly incomplete.
 *
 * The spec's headline example throughout: **60,000,000 financed, 20% flat, 6 monthly
 * instalments** — 12,000,000 profit, 72,000,000 payable, 12,000,000 a month, of which
 * 2,000,000 is profit and 10,000,000 principal.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, Party, Warehouse} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        return [$owner, Party::factory()->create(['name' => 'حسن رضایی']), Warehouse::factory()->create()];
    });

    [$this->owner, $this->customer, $this->warehouse] = $fixtures;

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * The spec's headline plan: 60,000,000 financed, 20% flat, 6 instalments.
 */
function specPlan(int $financed = 60_000_000, int $percent = 20, int $count = 6): InstallmentPlan
{
    /** @var Party $customer */
    $customer = test()->customer;
    /** @var Warehouse $warehouse */
    $warehouse = test()->warehouse;

    $profit = intdiv($financed * $percent, 100);
    $profit -= $profit % 10;
    $total = $financed + $profit;

    /** @var InstallmentPlan $plan */
    $plan = InstallmentPlan::query()->create([
        'branch_id' => $warehouse->branch_id,
        'party_id' => $customer->id,
        'number' => 'INS-'.random_int(100000, 999999),
        'down_payment' => 0,
        'principal' => $financed,
        'profit_percent' => $percent,
        'profit_amount' => $profit,
        'total_payable' => $total,
        'installment_count' => $count,
        'interval_months' => 1,
        'first_due_at' => '2026-08-01',
        'status' => 'active',
    ]);

    $each = intdiv($total, $count);
    $each -= $each % 10;
    $allocated = 0;

    for ($sequence = 1; $sequence <= $count; $sequence++) {
        $amount = $sequence === $count ? $total - $allocated : $each;
        $allocated += $amount;

        InstallmentRow::query()->create([
            'installment_plan_id' => $plan->id,
            'sequence' => $sequence,
            'due_at' => CarbonImmutable::parse('2026-08-01')->addMonths($sequence - 1),
            'amount' => $amount,
            'status' => InstallmentRow::STATUS_PENDING,
        ]);
    }

    return $plan->fresh() ?? $plan;
}

function withLateFeePolicy(int $percent, int $grace = 5, int $cap = 20): void
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    $tenant->forceFill(['settings' => [
        'installments' => [
            'late_fee_percent_per_month' => $percent,
            'late_fee_grace_days' => $grace,
            'late_fee_cap_percent' => $cap,
        ],
    ]])->save();

    app(TenantContext::class)->forget();
}

/* ============================== §1 — the split ============================== */

it('§1 splits a row into profit and principal that sum back to it exactly', function (): void {
    ($this->inTenant)(function (): void {
        $plan = specPlan();
        $maths = app(InstallmentMaths::class);

        foreach ($plan->rows as $row) {
            expect($maths->profitPartOf($row, $plan) + $maths->principalPartOf($row, $plan))
                ->toBe($row->amount);
        }
    });
});

it('§1 splits the spec example into 2,000,000 profit and 10,000,000 principal', function (): void {
    ($this->inTenant)(function (): void {
        $plan = specPlan();
        $maths = app(InstallmentMaths::class);

        /** @var InstallmentRow $first */
        $first = $plan->rows()->where('sequence', 1)->firstOrFail();

        expect($first->amount)->toBe(12_000_000)
            ->and($maths->profitPartOf($first, $plan))->toBe(2_000_000)
            ->and($maths->principalPartOf($first, $plan))->toBe(10_000_000);
    });
});

it('§1 carries the profit residue to the last row so the parts sum to the plan profit', function (): void {
    ($this->inTenant)(function (): void {
        // 10,000,000 over 3 does not divide, so the flooring leaves a residue with
        // somewhere to go.
        $plan = specPlan(financed: 10_000_000, percent: 17, count: 3);
        $maths = app(InstallmentMaths::class);

        $summed = 0;

        foreach ($plan->rows as $row) {
            $summed += $maths->profitPartOf($row, $plan);
        }

        // The invariant the spec calls out: the parts sum to the whole, exactly.
        expect($summed)->toBe($plan->profit_amount);
    });
});

/* ============================== §2 — late fees ============================== */

it('§2 charges nothing when the shop has not configured a late fee', function (): void {
    ($this->inTenant)(function (): void {
        $plan = specPlan();

        /** @var InstallmentRow $row */
        $row = $plan->rows()->where('sequence', 1)->firstOrFail();

        // Off by default. A fee the owner never chose is worse than no fee.
        expect(app(InstallmentMaths::class)->lateFeeOn($row, CarbonImmutable::parse('2027-01-01')))
            ->toBe(0);
    });
});

it('§2 charges nothing inside the grace period', function (): void {
    withLateFeePolicy(percent: 2);

    ($this->inTenant)(function (): void {
        $plan = specPlan();

        /** @var InstallmentRow $row */
        $row = $plan->rows()->where('sequence', 1)->firstOrFail();

        // Due 2026-08-01, five days' grace. Day four is still free.
        expect(app(InstallmentMaths::class)->lateFeeOn($row, CarbonImmutable::parse('2026-08-05')))
            ->toBe(0);
    });
});

it('§2 computes the spec worked example — 5,000,000 at 22 days late is 56,660', function (): void {
    withLateFeePolicy(percent: 2);

    ($this->inTenant)(function (): void {
        $plan = specPlan(financed: 25_000_000, percent: 20, count: 6);

        /** @var InstallmentRow $row */
        $row = $plan->rows()->where('sequence', 1)->firstOrFail();
        $row->forceFill(['amount' => 5_000_000])->save();

        /*
        | The spec's arithmetic, to the rial:
        |   days_late = 22 − 5 grace = 17
        |   raw = 5,000,000 × 2 × 17 ÷ (100 × 30) = 56,666
        |   floored to a whole toman = 56,660
        */
        expect(app(InstallmentMaths::class)->lateFeeOn($row->fresh() ?? $row, CarbonImmutable::parse('2026-08-23')))
            ->toBe(56_660);
    });
});

it('§2 caps the fee however late it becomes', function (): void {
    withLateFeePolicy(percent: 2, cap: 20);

    ($this->inTenant)(function (): void {
        $plan = specPlan(financed: 25_000_000, percent: 20, count: 6);

        /** @var InstallmentRow $row */
        $row = $plan->rows()->where('sequence', 1)->firstOrFail();
        $row->forceFill(['amount' => 5_000_000])->save();

        // 400 days late would be 1,316,660 uncapped. The cap is a fifth of the row.
        expect(app(InstallmentMaths::class)->lateFeeOn($row->fresh() ?? $row, CarbonImmutable::parse('2027-09-05')))
            ->toBe(1_000_000);
    });
});

it('§2 never compounds — the fee is on the row amount, not the row plus fee', function (): void {
    withLateFeePolicy(percent: 2, cap: 100);

    ($this->inTenant)(function (): void {
        $plan = specPlan(financed: 25_000_000, percent: 20, count: 6);

        /** @var InstallmentRow $row */
        $row = $plan->rows()->where('sequence', 1)->firstOrFail();
        $row->forceFill(['amount' => 5_000_000])->save();
        $row = $row->fresh() ?? $row;

        $maths = app(InstallmentMaths::class);

        $at30 = $maths->lateFeeOn($row, CarbonImmutable::parse('2026-09-05'));
        $at60 = $maths->lateFeeOn($row, CarbonImmutable::parse('2026-10-05'));

        // Strictly linear: doubling the days doubles the fee. Anything super-linear means
        // interest on interest, which this market calls ربا and a customer will say so.
        expect($at60)->toBeGreaterThan($at30)
            ->and(abs($at60 - 2 * $at30))->toBeLessThan(100);
    });
});

it('§2 returns a whole number of toman, always', function (): void {
    withLateFeePolicy(percent: 3, grace: 0);

    ($this->inTenant)(function (): void {
        $plan = specPlan(financed: 37_777_770, percent: 23, count: 7);
        $maths = app(InstallmentMaths::class);

        foreach ($plan->rows as $row) {
            $fee = $maths->lateFeeOn($row, CarbonImmutable::parse('2027-03-11'));

            // ADR 0009 — `Money` refuses to render anything else, and the refusal would
            // land on a printed receipt with the customer watching.
            expect($fee % 10)->toBe(0);
        }
    });
});

/* ========================= §3 — early settlement ========================= */

it('§3 computes the spec worked example — four rows left settles at 42,666,670', function (): void {
    ($this->inTenant)(function (): void {
        $plan = specPlan();

        // Two paid, four remaining.
        $plan->rows()->whereIn('sequence', [1, 2])->update(['status' => InstallmentRow::STATUS_PAID]);

        $quote = app(InstallmentMaths::class)->earlySettlement($plan->fresh() ?? $plan, CarbonImmutable::parse('2026-09-15'));

        /*
        | The spec's arithmetic, to the rial:
        |   unrebated profit  = 4 × 2,000,000 = 8,000,000
        |   rebate            = floor_toman(8,000,000 × 4 ÷ 6) = 5,333,330
        |   settlement        = 40,000,000 + (8,000,000 − 5,333,330) = 42,666,670
        */
        expect($quote['principal'])->toBe(40_000_000)
            ->and($quote['rebate'])->toBe(5_333_330)
            ->and($quote['profit_due'])->toBe(2_666_670)
            ->and($quote['total'])->toBe(42_666_670);
    });
});

it('§3 shrinks the rebate as the term elapses', function (): void {
    ($this->inTenant)(function (): void {
        $maths = app(InstallmentMaths::class);
        $at = CarbonImmutable::parse('2026-09-15');

        // Nothing paid: the customer has had none of the financing they were charged for,
        // so the whole remaining profit comes back.
        $untouched = $maths->earlySettlement(specPlan(), $at);

        // Five of six paid: they have had almost all of it, so almost none comes back.
        $plan = specPlan();
        $plan->rows()->whereIn('sequence', [1, 2, 3, 4, 5])->update(['status' => InstallmentRow::STATUS_PAID]);
        $nearlyDone = $maths->earlySettlement($plan->fresh() ?? $plan, $at);

        expect($untouched['rebate'])->toBe(12_000_000)
            ->and($untouched['profit_due'])->toBe(0)
            // One row left of six: 2,000,000 × 1/6, floored to a whole toman.
            ->and($nearlyDone['rebate'])->toBe(333_330)
            ->and($nearlyDone['profit_due'])->toBe(1_666_670);
    });
});

it('§3 never rebates a late fee', function (): void {
    withLateFeePolicy(percent: 2);

    ($this->inTenant)(function (): void {
        $plan = specPlan();

        $quote = app(InstallmentMaths::class)->earlySettlement($plan, CarbonImmutable::parse('2026-09-15'));

        // A fee is a charge for a breach that happened. Settling early does not un-happen
        // it, so it rides on top of the rebated figure untouched.
        expect($quote['fees'])->toBeGreaterThan(0)
            ->and($quote['total'])->toBe($quote['principal'] + $quote['profit_due'] + $quote['fees']);
    });
});

it('§3 quotes the same figure whichever day of the week it is asked on', function (): void {
    ($this->inTenant)(function (): void {
        $plan = specPlan();
        $maths = app(InstallmentMaths::class);

        // Pro rata by instalment COUNT, not by days. A figure that moves between Monday
        // and Tuesday cannot be quoted over the phone and reads as the shop inventing it.
        $monday = $maths->earlySettlement($plan, CarbonImmutable::parse('2026-08-03'));
        $tuesday = $maths->earlySettlement($plan, CarbonImmutable::parse('2026-08-04'));

        expect($monday['total'])->toBe($tuesday['total']);
    });
});

it('§3 returns whole toman for every figure it quotes', function (): void {
    ($this->inTenant)(function (): void {
        $plan = specPlan(financed: 37_777_770, percent: 23, count: 7);

        $quote = app(InstallmentMaths::class)->earlySettlement($plan, CarbonImmutable::parse('2026-09-15'));

        foreach (['principal', 'profit_due', 'rebate', 'fees', 'total'] as $key) {
            expect($quote[$key] % 10)->toBe(0);
        }
    });
});

/* ======================== §4 — partial payment ======================== */

it('§4 settles the fee first, then profit, then principal', function (): void {
    withLateFeePolicy(percent: 2);

    ($this->inTenant)(function (): void {
        $plan = specPlan();

        /** @var InstallmentRow $row */
        $row = $plan->rows()->where('sequence', 1)->firstOrFail();

        $maths = app(InstallmentMaths::class);
        $asOf = CarbonImmutable::parse('2026-09-15');

        $fee = $maths->lateFeeOn($row, $asOf);

        // Pay the fee and a little more.
        $applied = $maths->applyPayment($row, $plan, $fee + 500_000, $asOf);

        expect($applied['fee'])->toBe($fee)
            ->and($applied['profit'])->toBe(500_000)
            ->and($applied['principal'])->toBe(0);
    });
});

it('§4 reports an overpayment rather than absorbing it into the row', function (): void {
    ($this->inTenant)(function (): void {
        $plan = specPlan();

        /** @var InstallmentRow $row */
        $row = $plan->rows()->where('sequence', 1)->firstOrFail();

        $applied = app(InstallmentMaths::class)->applyPayment($row, $plan, 15_000_000, CarbonImmutable::parse('2026-08-01'));

        // 12,000,000 row, 15,000,000 handed over. The extra belongs on the party's balance
        // as a credit, not quietly swallowed by this row.
        expect($applied['profit'] + $applied['principal'])->toBe(12_000_000)
            ->and($applied['unapplied'])->toBe(3_000_000);
    });
});
