<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\SmsCreditEntry;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Jalali;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * SMS usage, per template, in segments.
 *
 * ## The fixture's whole point is that messages and segments disagree
 *
 * «یادآوری قسط» sends four messages of **two** segments each; «تولد» sends six of one. By
 * message count the birthday template is the bigger sender; by segment — which is what the
 * gateway bills — the instalment reminder costs more. A report counting messages would rank
 * them the wrong way round and point the shop at the wrong template to shorten.
 *
 * | template   | messages | segments | cost      |
 * |------------|----------|----------|-----------|
 * | قسط        |     4    |     8    | 1,480,000 |
 * | تولد       |     6    |     6    |   870,000 |
 * | (manual)   |     1    |     1    |   145,000 |
 *
 * ## Failed and suppressed are counted apart
 *
 * A suppressed message is one the opt-out list stopped, and it is a success — the shop did
 * not text somebody who asked not to be texted. Folding it into "failed" would put a red
 * number beside a feature working correctly.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = tenantUrl($this->tenant);

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    $this->now = CarbonImmutable::now();

    /** @var array{User, User} $fixtures */
    $fixtures = app(TenantContext::class)->runFor($this->tenant, function (): array {
        $owner = User::factory()->create(['name' => 'مالک']);
        $owner->assignRole('Owner');

        $technician = User::factory()->create();
        $technician->assignRole('Technician');

        $send = function (?string $template, string $status, int $segments, int $cost, int $daysAgo = 3): void {
            Message::query()->create([
                'to' => '+989121234567',
                'template_key' => $template,
                'status' => $status,
                'segments' => $segments,
                'cost' => $cost,
                'body' => 'متن پیام',
                'queued_at' => CarbonImmutable::now()->subDays($daysAgo),
            ]);
        };

        // Two segments each, and an un-round per-message cost: 370,000 rial is a real
        // gateway price shape, and a fixture that bills round numbers never notices a
        // report that divides when it should sum.
        for ($i = 0; $i < 3; $i++) {
            $send('installment-due', 'sent', 2, 370_000);
        }

        // One that failed — money the gateway may still have taken.
        $send('installment-due', 'failed', 2, 370_000);

        for ($i = 0; $i < 5; $i++) {
            $send('birthday', 'sent', 1, 145_000);
        }

        // Stopped by the opt-out list: no cost, and NOT a failure.
        $send('birthday', 'suppressed', 1, 145_000);

        // Typed at the counter, no template behind it.
        $send(null, 'sent', 1, 145_000);

        // Outside the range the report will ask for — proves the filter does something.
        $send('birthday', 'sent', 1, 145_000, daysAgo: 400);

        /* ---------------------------------------------------------- wallet -- */

        SmsCreditEntry::query()->create([
            'amount' => 50_000_000,
            'type' => 'topup',
            'occurred_at' => CarbonImmutable::now()->subDays(10),
        ]);

        SmsCreditEntry::query()->create([
            'amount' => -2_495_000,
            'type' => 'charge',
            'occurred_at' => CarbonImmutable::now()->subDays(3),
        ]);

        return [$owner, $technician];
    });

    [$this->owner, $this->technician] = $fixtures;
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * The report over a range that covers the recent messages and not the year-old one.
 */
function smsReportUrl(): string
{
    /** @var CarbonImmutable $now */
    $now = test()->now;
    /** @var string $url */
    $url = test()->url;

    return $url.'/reporting/operations?'.http_build_query([
        'from' => Jalali::format($now->subDays(30), 'Y/m/d', persianDigits: false),
        'to' => Jalali::format($now, 'Y/m/d', persianDigits: false),
    ]);
}

/* --------------------------------------------------------------- usage -- */

it('bills by segment, so the template that sends fewer messages can cost more', function (): void {
    $this->actingAs($this->owner)
        ->get(smsReportUrl())
        ->assertOk()
        ->assertInertia(function ($page): void {
            $byTemplate = byLabel(rowsOf($page));

            $instalment = $byTemplate['installment-due'];
            $birthday = $byTemplate['birthday'];

            // Fewer messages…
            expect($instalment['messages'])->toBe(4)
                ->and($birthday['messages'])->toBe(6);

            // …more segments, and more money.
            expect($instalment['segments'])->toBe(8)
                ->and($birthday['segments'])->toBe(6);

            expect(rialOf($instalment['cost']))->toBe(1_480_000)
                ->and(rialOf($birthday['cost']))->toBe(870_000);

            // Ordered by cost, so the expensive template is the one somebody reads first.
            expect(rowsOf($page)[0]['label'])->toBe('installment-due');
        });
});

it('separates a suppressed message from a failed one', function (): void {
    $this->actingAs($this->owner)
        ->get(smsReportUrl())
        ->assertOk()
        ->assertInertia(function ($page): void {
            $byTemplate = byLabel(rowsOf($page));

            // The opt-out is on the birthday template and it is not a failure.
            expect($byTemplate['birthday']['suppressed'])->toBe(1)
                ->and($byTemplate['birthday']['failed'])->toBe(0);

            expect($byTemplate['installment-due']['failed'])->toBe(1)
                ->and($byTemplate['installment-due']['suppressed'])->toBe(0);

            expect(propsOf($page)['totals'])->toMatchArray(['failed' => 1]);
        });
});

it('keeps a message typed at the counter under a named row rather than dropping it', function (): void {
    $this->actingAs($this->owner)
        ->get(smsReportUrl())
        ->assertOk()
        ->assertInertia(function ($page): void {
            $labels = array_column(rowsOf($page), 'label');

            // Dropping it would make the rows stop summing to the wallet.
            expect($labels)->toContain('بدون قالب (دستی)');

            expect(propsOf($page)['totals'])->toMatchArray(['messages' => 11, 'segments' => 15]);
        });
});

it('excludes messages outside the range', function (): void {
    // Eleven inside the range; a twelfth was queued four hundred days ago.
    $this->actingAs($this->owner)
        ->get(smsReportUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('totals.messages', 11)->etc());
});

/* -------------------------------------------------------------- wallet -- */

it('reports the wallet balance as of now and the movements as of the range', function (): void {
    $this->actingAs($this->owner)
        ->get(smsReportUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('wallet.balance.value', 50_000_000 - 2_495_000)
            ->where('wallet.topups.value', 50_000_000)
            // Reported positive: a column of negative numbers under a heading that already
            // says «مصرف» makes a reader check whether it is a credit.
            ->where('wallet.charges.value', 2_495_000)
            ->etc()
        );
});

/* ------------------------------------------------------------ boundary -- */

it('refuses SMS usage to somebody who may not see messaging', function (): void {
    $this->actingAs($this->technician)
        ->get($this->url.'/reporting/operations')
        ->assertForbidden();
});

it('lists the operations row on the index for the owner', function (): void {
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting')
        ->assertOk()
        ->assertInertia(function ($page): void {
            expect(reportKeys($page, 'operations'))->toBe(['operations.sms']);
        });
});

it('downloads a workbook', function (): void {
    $response = $this->actingAs($this->owner)->get($this->url.'/reporting/operations/export');

    $response->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('.xlsx');
});

/* ----------------------------------------------------------- isolation -- */

it('reports a shop its own messages and not the shop next door', function (): void {
    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($other);

    $neighbour = app(TenantContext::class)->runFor($other, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    // The positive half first, so the emptiness below means isolation.
    $this->actingAs($this->owner)
        ->get(smsReportUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('totals.messages', 11)->etc());

    $this->actingAs($neighbour)
        ->get(tenantUrl($other).'/reporting/operations')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('rows', [])
            ->where('totals.messages', 0)
            ->where('wallet.balance.value', 0)
            ->etc()
        );
})->group('isolation');
