<?php

declare(strict_types=1);

use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Files\AttachmentStore;
use App\Support\Quota\QuotaExceeded;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * `files.attachments` and `files.storage_mb` — two metrics on one act, capping different
 * things.
 *
 * A shop can be comfortably inside one and hard against the other: a thousand small
 * receipt photos, or a single enormous PDF. So an upload spends a **monthly** attachment
 * credit *and* is checked against a **standing** megabyte capacity, and these tests hold
 * both halves of that in place — including the asymmetry that follows from it, which is the
 * part nobody would guess:
 *
 * > Deleting a file gives the space back and does **not** give the attachment credit back.
 *
 * That is not an oversight. The megabyte cap asks "how much are we storing for this shop
 * right now", which changes when a file goes; the attachment credit asks "how much work did
 * this shop record this month", which does not. Voids and returns do not refund a sale
 * either, for the same reason (Gate 6).
 *
 * ## Why the order inside the transaction matters
 *
 * The attachment credit is consumed first, then the megabytes, then the row, and the blob
 * is written to the disk **last**. An earlier order put the object on the disk before the
 * row existed, so anything failing afterwards left a file nothing referenced: invisible,
 * uncountable, and still on the bill. These tests assert the shop-visible half of that —
 * a refused upload leaves no row, no credit and no object.
 */
beforeEach(function (): void {
    Storage::fake('local');

    $this->tenant = Tenant::factory()->withDomain()->create();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var Party $owner */
    $owner = inTenantContext($this->tenant, function (): Party {
        User::factory()->create()->assignRole('Owner');

        return Party::factory()->create(['name' => 'حسن رضایی']);
    });

    $this->owner = $owner;
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * Attach one image of a given size to the fixture party.
 *
 * `$kilobytes` is what makes the storage metric testable: `UploadedFile::fake()->image()`
 * accepts a size in KB, so a 3 MB file is one argument rather than a fixture on disk.
 */
function attachOne(int $kilobytes = 8, string $name = 'receipt.jpg'): App\Support\Files\Attachment
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;
    /** @var Party $owner */
    $owner = test()->owner;

    /** @var App\Support\Files\Attachment $attachment */
    $attachment = inTenantContext($tenant, fn () => app(AttachmentStore::class)->attach(
        $owner,
        UploadedFile::fake()->image($name)->size($kilobytes),
        'party_documents',
    ));

    return $attachment;
}

it('spends one attachment credit per file', function (): void {
    attachOne();

    expect(quotaUsed($this->tenant, 'files.attachments'))->toBe(1);
});

it('refuses the upload that would cross the attachment ceiling, and stores nothing', function (): void {
    capQuota($this->tenant, 'files.attachments', 1);

    attachOne();

    expect(fn () => attachOne(name: 'second.jpg'))->toThrow(QuotaExceeded::class);

    /** @var array<int, object> $files */
    $files = inTenantContext($this->tenant, fn (): array => app(AttachmentStore::class)->for($this->owner));

    expect($files)->toHaveCount(1)
        // The blob is written last, inside the transaction, so a refused upload must leave
        // nothing on the disk either — an orphaned object is invisible, uncountable, and
        // still on the bill.
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1)
        ->and(quotaUsed($this->tenant, 'files.attachments'))->toBe(1);
});

it('checks the megabytes separately from the file count', function (): void {
    // Room for plenty of files, room for almost no space. A shop can be inside one cap and
    // against the other, which is the whole reason there are two metrics.
    capQuota($this->tenant, 'files.storage_mb', 1);

    expect(fn () => attachOne(kilobytes: 3_000))->toThrow(QuotaExceeded::class);

    /** @var array<int, object> $files */
    $files = inTenantContext($this->tenant, fn (): array => app(AttachmentStore::class)->for($this->owner));

    expect($files)->toBeEmpty()
        // Refused on the second metric, so the FIRST one must have unwound too. Both
        // consumes are in one transaction precisely so a shop cannot be charged an
        // attachment credit for a file it was not allowed to store.
        ->and(quotaRowExists($this->tenant, 'files.attachments'))->toBeFalse();
});

it('gives the space back when a file is deleted, but not the attachment credit', function (): void {
    $first = attachOne(kilobytes: 1_500);

    capQuota($this->tenant, 'files.storage_mb', 2);

    // Full: 1.5 MB stored against a 2 MB cap leaves no room for another 1.5.
    expect(fn () => attachOne(kilobytes: 1_500, name: 'second.jpg'))->toThrow(QuotaExceeded::class);

    inTenantContext($this->tenant, fn () => app(AttachmentStore::class)->detach($first));

    // `files.storage_mb` is a Total window — measured from live rows, not counted — so the
    // slot comes back the moment the row does not exist. Nothing had to remember to
    // decrement anything, which is exactly why it is measured rather than counted.
    attachOne(kilobytes: 1_500, name: 'third.jpg');

    expect(quotaUsed($this->tenant, 'files.attachments'))
        // Three attempts, two of which stored a file, one refused. The credit counts the
        // successful writes and never counts back down: deleting the first file did not
        // give a shop back the month's allowance for having uploaded it.
        ->toBe(2);
});
