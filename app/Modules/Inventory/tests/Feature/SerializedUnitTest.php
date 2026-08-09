<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Exceptions\IllegalUnitTransition;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\ProductUnitHistory;
use App\Modules\Inventory\Services\UnitStateMachine;
use App\Modules\Platform\Models\Tenant;
use App\Support\Imei;
use App\Support\Tenancy\TenantContext;
use Database\Factories\ProductUnitFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->machine = app(UnitStateMachine::class);
});

afterEach(fn () => app(TenantContext::class)->forget());

/* ------------------------------------------------------------------ imei -- */

it('accepts a Luhn-valid IMEI and rejects a mistyped one', function (): void {
    $valid = ProductUnitFactory::validImei();

    expect(Imei::isValid($valid))->toBeTrue();

    // Change one digit: the check digit no longer agrees. This is the everyday failure
    // the validation exists for — a number mistyped at intake that then fails to match
    // when the same phone is sold or warranty-claimed.
    $broken = $valid;
    $broken[3] = (string) ((((int) $broken[3]) + 1) % 10);

    expect(Imei::isValid($broken))->toBeFalse();
});

it('rejects IMEIs of the wrong length and all-zero placeholders', function (): void {
    expect(Imei::isValid('12345'))->toBeFalse();
    expect(Imei::isValid('1234567890123456'))->toBeFalse();
    // Passes Luhn, but is what a broken scanner or a placeholder entry produces.
    expect(Imei::isValid('000000000000000'))->toBeFalse();
});

it('reads Persian digits and separators as the same IMEI', function (): void {
    $imei = ProductUnitFactory::validImei();
    $persian = App\Support\Digits::toPersian($imei);

    // Iranian staff type on Persian keyboards and paste from Persian documents.
    expect(Imei::isValid($persian))->toBeTrue();
    expect(Imei::normalise($persian))->toBe($imei);
    expect(Imei::normalise(substr($imei, 0, 6).'-'.substr($imei, 6)))->toBe($imei);
});

it('normalises an IMEI before storing it', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $imei = ProductUnitFactory::validImei();

        $unit = ProductUnit::factory()->create([
            'imei1' => App\Support\Digits::toPersian($imei),
        ]);

        // Stored as Latin digits, or the unique index and every later lookup would treat
        // the two spellings as different devices.
        expect($unit->fresh()?->imei1)->toBe($imei);
    });
});

it('finds a device by IMEI, second IMEI or serial, however typed', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $unit = ProductUnit::factory()->dualSim()->create(['serial' => 'SN-ABC-123']);

        // The POS scan box does not know which kind of code was scanned.
        expect(ProductUnit::query()->matchingCode((string) $unit->imei1)->first()?->getKey())->toBe($unit->getKey());
        expect(ProductUnit::query()->matchingCode((string) $unit->imei2)->first()?->getKey())->toBe($unit->getKey());
        expect(ProductUnit::query()->matchingCode('SN-ABC-123')->first()?->getKey())->toBe($unit->getKey());
        expect(ProductUnit::query()->matchingCode(App\Support\Digits::toPersian((string) $unit->imei1))->first()?->getKey())
            ->toBe($unit->getKey());
    });
});

/* ------------------------------------------------------- imei uniqueness -- */

it('refuses the same IMEI twice in one shop', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $imei = ProductUnitFactory::validImei();
        ProductUnit::factory()->create(['imei1' => $imei]);

        expect(fn () => DB::transaction(fn () => ProductUnit::factory()->create(['imei1' => $imei])))
            ->toThrow(QueryException::class);
    });
});

it('refuses an IMEI already registered as another device second SIM', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $unit = ProductUnit::factory()->dualSim()->create();

        // The case two per-column unique indexes miss entirely: the same physical handset
        // entered twice, once as imei1 and once as imei2. Caught by the trigger.
        expect(fn () => DB::transaction(
            fn () => ProductUnit::factory()->create(['imei1' => $unit->imei2])
        ))->toThrow(QueryException::class);
    });
});

it('lets two different shops hold the same IMEI', function (): void {
    pest()->group('isolation');

    $other = Tenant::factory()->withDomain()->create();
    $imei = ProductUnitFactory::validImei();

    // A phone genuinely moves between shops; uniqueness is per tenant, not global.
    app(TenantContext::class)->runFor($this->tenant, fn () => ProductUnit::factory()->create(['imei1' => $imei]));
    app(TenantContext::class)->runFor($other, fn () => ProductUnit::factory()->create(['imei1' => $imei]));

    app(TenantContext::class)->runFor($other, fn () => expect(ProductUnit::query()->count())->toBe(1));
});

it('frees an IMEI once the unit is soft-deleted', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $imei = ProductUnitFactory::validImei();

        ProductUnit::factory()->create(['imei1' => $imei])->delete();

        // The same handset coming back through the door must be registrable again.
        expect(ProductUnit::factory()->create(['imei1' => $imei])->exists)->toBeTrue();
    });
});

/* --------------------------------------------------------- state machine -- */

it('records history on every transition', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $unit = ProductUnit::factory()->create();

        $this->machine->recordAcquisition($unit, note: 'خرید از تأمین‌کننده');
        $this->machine->transition($unit, UnitStatus::Reserved, note: 'رزرو برای مشتری');
        $this->machine->transition($unit, UnitStatus::Sold);

        /** @var list<ProductUnitHistory> $history */
        $history = $unit->histories()->get()->all();

        expect($history)->toHaveCount(3);
        expect($history[0]->from_status)->toBeNull();
        expect($history[1]->from_status)->toBe(UnitStatus::InStock);
        expect($history[2]->to_status)->toBe(UnitStatus::Sold);
        expect($unit->fresh()?->status)->toBe(UnitStatus::Sold);
    });
});

it('refuses to un-sell a device without a return', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $unit = ProductUnit::factory()->status(UnitStatus::Sold)->create();

        // The transition that matters most. A silent flip back to in_stock loses the
        // credit document and is how the same phone gets sold twice.
        expect(fn () => $this->machine->transition($unit, UnitStatus::InStock))
            ->toThrow(IllegalUnitTransition::class);

        expect($unit->fresh()?->status)->toBe(UnitStatus::Sold);
    });
});

it('allows a sold device to come back as returned', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $unit = ProductUnit::factory()->status(UnitStatus::Sold)->create();

        $this->machine->transition($unit, UnitStatus::Returned);

        // Returned, not in_stock: it needs inspection before it can be sold again.
        expect($unit->fresh()?->status)->toBe(UnitStatus::Returned);
    });
});

it('treats written off as terminal', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $unit = ProductUnit::factory()->status(UnitStatus::WrittenOff)->create();

        expect(UnitStatus::WrittenOff->allowedNext())->toBe([]);

        // A written-off device coming back is a new acquisition with its own cost, not a
        // resurrection of this row.
        expect(fn () => $this->machine->transition($unit, UnitStatus::InStock))
            ->toThrow(IllegalUnitTransition::class);
    });
});

it('does not write a history line when the status is re-asserted', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $unit = ProductUnit::factory()->create();

        $this->machine->transition($unit, UnitStatus::InStock);

        // Callers legitimately re-assert; a passport full of meaningless repeats is
        // worse than no entry.
        expect($unit->histories()->count())->toBe(0);
    });
});

it('leaves no history behind when a transition is refused', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $unit = ProductUnit::factory()->status(UnitStatus::Sold)->create();

        try {
            $this->machine->transition($unit, UnitStatus::InStock);
        } catch (IllegalUnitTransition) {
            // expected
        }

        expect(ProductUnitHistory::query()->count())->toBe(0);
    });
});

it('links a transition to the document that caused it', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $unit = ProductUnit::factory()->create();
        $variant = ProductVariant::factory()->create();

        // Any model can be the reference; Phase 5 passes a sales invoice here.
        $this->machine->transition($unit, UnitStatus::Sold, reference: $variant);

        $history = $unit->histories()->latest('id')->first();

        expect($history?->reference_type)->toBe(ProductVariant::class);
        expect($history?->reference_id)->toBe($variant->getKey());
    });
});

/* ------------------------------------------------------------- on-hand -- */

it('counts reserved and in-repair as on hand but not sold', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        ProductUnit::factory()->status(UnitStatus::InStock)->create();
        ProductUnit::factory()->status(UnitStatus::Reserved)->create();
        ProductUnit::factory()->status(UnitStatus::InRepair)->create();
        ProductUnit::factory()->status(UnitStatus::Sold)->create();
        ProductUnit::factory()->status(UnitStatus::WrittenOff)->create();

        // A reserved phone is still the shop's asset and still on the valuation; it is
        // simply not sellable to anyone else.
        expect(ProductUnit::query()->onHand()->count())->toBe(3);
        expect(UnitStatus::Reserved->isOnHand())->toBeTrue();
        expect(UnitStatus::Reserved->isSellable())->toBeFalse();
    });
});

/* ----------------------------------------------------------- isolation -- */

it('does not leak units across tenants', function (): void {
    pest()->group('isolation');

    $other = Tenant::factory()->withDomain()->create();

    app(TenantContext::class)->runFor($this->tenant, fn () => ProductUnit::factory()->count(3)->create());
    app(TenantContext::class)->runFor($other, fn () => ProductUnit::factory()->create());

    app(TenantContext::class)->runFor($other, fn () => expect(ProductUnit::query()->count())->toBe(1));
});

it('does not leak unit history across tenants', function (): void {
    pest()->group('isolation');

    $other = Tenant::factory()->withDomain()->create();

    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $unit = ProductUnit::factory()->create();
        $this->machine->transition($unit, UnitStatus::Sold);
    });

    // A competitor's purchase and sale history is precisely the thing a shop would pay
    // to read.
    app(TenantContext::class)->runFor(
        $other,
        fn () => expect(ProductUnitHistory::query()->count())->toBe(0)
    );
});
