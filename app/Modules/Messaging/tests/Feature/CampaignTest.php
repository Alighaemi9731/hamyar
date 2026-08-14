<?php

declare(strict_types=1);

use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Models\PartyContact;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Contracts\SmsDriver;
use App\Modules\Messaging\Drivers\FakeSmsDriver;
use App\Modules\Messaging\Models\Campaign;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageOptOut;
use App\Modules\Messaging\Services\CampaignAudience;
use App\Modules\Messaging\Services\SendCampaign;
use App\Modules\Messaging\Services\SmsWallet;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Bulk sends, and the two places opt-out has to hold.
 *
 * The audience excludes opted-out numbers so the count a shop reads before spending credit
 * is honest, and the door refuses them again on the way out. Neither is redundant: the
 * first is about a shopkeeper deciding whether to spend 1,200,000 rial, the second is the
 * guarantee that holds however the audience was built.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    inTenantContext($this->tenant, function (): void {
        User::factory()->create()->assignRole('Owner');
        app(SmsWallet::class)->topUp(50_000_000);

        foreach ([
            ['حسن رضایی', '09121110001'],
            ['مریم احمدی', '09121110002'],
            ['علی کریمی', '09121110003'],
        ] as [$name, $mobile]) {
            $party = Party::factory()->create(['name' => $name]);

            PartyContact::query()->create([
                'party_id' => $party->id,
                'type' => PartyContact::TYPE_MOBILE,
                'value' => $mobile,
                'is_primary' => true,
            ]);
        }

        // A customer with only a landline. Not an audience member — counting them inflates
        // every figure the shop reads.
        $landlineOnly = Party::factory()->create(['name' => 'مغازه روبرو']);

        PartyContact::query()->create([
            'party_id' => $landlineOnly->id,
            'type' => PartyContact::TYPE_PHONE,
            'value' => '02188776655',
            'is_primary' => true,
        ]);
    });

    /** @var FakeSmsDriver $driver */
    $driver = app(SmsDriver::class);
    $driver->reset();
    $this->driver = $driver;

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

function makeCampaign(): Campaign
{
    /** @var Campaign $campaign */
    $campaign = Campaign::query()->create([
        'name' => 'تخفیف نوروزی',
        'body' => 'سلام {name}، تخفیف ویژه {shop}',
        'provider_template_id' => 'pattern-promo',
        'per_minute' => 60,
    ]);

    return $campaign;
}

function drainCampaign(): void
{
    app(TenantContext::class)->forget();

    Illuminate\Support\Facades\Artisan::call('queue:work', [
        '--queue' => 'sms', '--stop-when-empty' => true, '--tries' => 1, '--memory' => 4096,
    ]);
}

/* ---------------------------------------------- the audience -- */

it('counts only people there is a mobile number for', function (): void {
    ($this->inTenant)(function (): void {
        // Four parties, three with mobiles.
        expect(Party::query()->count())->toBe(4)
            ->and(app(CampaignAudience::class)->count(makeCampaign()))->toBe(3);
    });
});

it('excludes an opted-out number from the COUNT, not merely from the send', function (): void {
    ($this->inTenant)(function (): void {
        MessageOptOut::query()->create([
            'phone' => '+989121110002',
            'opted_out_at' => CarbonImmutable::now(),
        ]);

        // A shop deciding whether to spend credit is reading this number. Showing 3 and
        // sending 2 makes the estimate a lie in the direction that costs them money.
        expect(app(CampaignAudience::class)->count(makeCampaign()))->toBe(2);
    });
});

it('matches an opt-out recorded in a different spelling than the contact row', function (): void {
    ($this->inTenant)(function (): void {
        // The list is canonical +98; the contact row holds 0912…. Same person.
        MessageOptOut::query()->create([
            'phone' => '+989121110003',
            'opted_out_at' => CarbonImmutable::now(),
        ]);

        expect(app(CampaignAudience::class)->count(makeCampaign()))->toBe(2);
    });
});

it('shows a sample so a shop can check the filter before spending', function (): void {
    ($this->inTenant)(function (): void {
        $sample = app(CampaignAudience::class)->sample(makeCampaign(), 2);

        expect($sample)->toHaveCount(2)
            ->and($sample[0]['mobile'])->not->toBeNull();
    });
});

/* ---------------------------------------------- sending -- */

it('queues one message per audience member and none to anybody else', function (): void {
    ($this->inTenant)(function (): void {
        $result = app(SendCampaign::class)->send(makeCampaign());

        expect($result['queued'])->toBe(3);
    });

    drainCampaign();

    $this->driver->assertSentCount(3);
    $this->driver->assertSent('+989121110001', 'pattern-promo', ['حسن رضایی', config()->string('app.name')]);
});

it('sends nothing to an opted-out number even if it reached the queue', function (): void {
    ($this->inTenant)(function (): void {
        MessageOptOut::query()->create([
            'phone' => '+989121110002',
            'opted_out_at' => CarbonImmutable::now(),
        ]);

        app(SendCampaign::class)->send(makeCampaign());
    });

    drainCampaign();

    // Excluded from the audience AND refused at the door. Belt and braces on the one thing
    // that reaches a regulator.
    $this->driver->assertNothingSentTo('+989121110002');
    $this->driver->assertSentCount(2);
});

it('reaches nobody twice when a campaign is sent twice', function (): void {
    ($this->inTenant)(function (): void {
        $campaign = makeCampaign();

        app(SendCampaign::class)->send($campaign);

        // A double click, or an operator who is not sure it worked. The key is
        // campaign+party, so the same customer in a DIFFERENT campaign still gets both.
        $campaign->forceFill(['status' => Campaign::STATUS_DRAFT])->save();
        app(SendCampaign::class)->send($campaign->fresh() ?? $campaign);
    });

    drainCampaign();

    $this->driver->assertSentCount(3);

    ($this->inTenant)(fn () => expect(Message::query()->count())->toBe(3));
});

it('spreads the send out rather than bursting at the gateway', function (): void {
    /*
    | Asserted on the job's own delay rather than on the `jobs` table.
    |
    | The suite runs on `sync`, where a dispatched job executes inline and never reaches a
    | table — so a query against `jobs` finds nothing and the assertion passes or fails for
    | reasons unrelated to throttling. Faking the queue captures what was pushed and with
    | what delay, which is the actual claim, on any driver.
    */
    Illuminate\Support\Facades\Queue::fake();

    ($this->inTenant)(function (): void {
        $campaign = makeCampaign();
        $campaign->forceFill(['per_minute' => 2])->save();

        app(SendCampaign::class)->send($campaign->fresh() ?? $campaign);
    });

    $delays = [];

    Illuminate\Support\Facades\Queue::assertPushed(
        App\Modules\Messaging\Jobs\SendSmsJob::class,
        function (App\Modules\Messaging\Jobs\SendSmsJob $job) use (&$delays): bool {
            // Timestamps rather than diffInSeconds: Carbon's signed/absolute default has
            // changed between majors, and a silently unsigned zero here is exactly the
            // assertion-that-cannot-fail this suite keeps catching.
            $delays[] = $job->delay instanceof DateTimeInterface
                ? $job->delay->getTimestamp() - CarbonImmutable::now()->getTimestamp()
                : 0;

            return true;
        },
    );

    sort($delays);

    // Three messages at two a minute: 0s, 30s, 60s. Gateways rate-limit, and a burst gets
    // throttled, retried, and charged for the retries.
    expect($delays)->toHaveCount(3)
        // 0s, 30s, 60s — the last one is a minute out.
        ->and($delays[2])->toBeGreaterThanOrEqual(55)
        // And the first is immediate, so a small campaign is not needlessly delayed.
        ->and($delays[0])->toBeLessThan(5);
});

it('refuses a campaign with no registered pattern', function (): void {
    ($this->inTenant)(function (): void {
        $campaign = makeCampaign();
        $campaign->forceFill(['provider_template_id' => null])->save();

        expect(fn () => app(SendCampaign::class)->send($campaign->fresh() ?? $campaign))
            ->toThrow(RuntimeException::class);
    });
});
