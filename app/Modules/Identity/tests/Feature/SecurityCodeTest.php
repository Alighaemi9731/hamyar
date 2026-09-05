<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\SecurityCode;
use App\Support\Tenancy\TenantContext;

/**
 * The «کد امنیتی» in front of the sign-in form.
 *
 * What matters here is not that it is hard to read — it is deliberately mild — but that
 * it is **spent**: one drawing answers one attempt. A captcha a script can solve once and
 * replay is decoration, and that is the property a test has to hold, because nothing
 * about it is visible when it breaks.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    $this->user = app(TenantContext::class)->runFor($this->tenant, function (): User {
        $user = User::factory()->create(['mobile' => '09121234567', 'password' => 'password']);
        $user->assignRole('Owner');

        return $user;
    });
});

afterEach(fn () => app(TenantContext::class)->forget());

it('draws a code on the sign-in page and remembers it for that session', function (): void {
    $response = $this->get($this->url.'/login')->assertOk();

    $code = session(SecurityCode::SESSION_KEY);

    expect($code)->toBeString()->toHaveLength(5);
    // The drawing is on the page, and the answer is not: a code in the markup as text
    // is a code any script reads without looking at the picture.
    $response->assertSee('data-security-image', false);
    $response->assertDontSee($code, false);
});

/*
| The assertion this file existed without until 2026-09-05.
|
| The drawing used to set each character with an SVG `<text>` element. `assertDontSee`
| above passed — the five characters are separated by markup, so the code never appears
| as one contiguous string — while `textContent` on the drawing returned it whole:
|
|     document.querySelector('[data-security-image]').textContent   // "WW6CA"
|
| A green test, and a captcha any script solved by reading the page it had just fetched.
| So the property is stated the way a scraper would test it: strip the tags and there must
| be nothing left to read.
*/
it('puts no readable text in the drawing at all', function (): void {
    $svg = app(SecurityCode::class)->render();
    $code = session(SecurityCode::SESSION_KEY);

    expect($svg)->toContain('<svg')
        ->and($svg)->not->toContain('<text')
        ->and($svg)->not->toContain('<tspan')
        // What `textContent` returns. Attributes are not text nodes, so `aria-label` and
        // the path data survive stripping only as markup — this is the visitor-visible
        // string, and it has to be empty.
        ->and(trim(strip_tags($svg)))->toBe('');

    /*
    | And the answer is not hiding in an attribute either.
    |
    | The `d` attributes are removed before looking, because those ARE the picture — the
    | geometry is what a person reads with their eyes and what an attacker would have to
    | rasterise. Everything else is metadata a scraper gets for free, and the code must
    | not be in any of it. Stripping them also keeps this deterministic: path data is a
    | long run of digits and the letters `MCLHVAQTZ`, so a bare `toContain` on the whole
    | document could match an all-digit code by coincidence and flake once a year.
    */
    $metadata = (string) preg_replace('/\sd="[^"]*"/', '', $svg);

    expect($metadata)->not->toContain($code);
});

it('draws every character of the alphabet, and only from paths', function (): void {
    $reflection = new ReflectionClass(SecurityCode::class);

    /** @var array<string, string> $glyphs */
    $glyphs = $reflection->getConstant('GLYPHS');
    /** @var string $alphabet */
    $alphabet = $reflection->getConstant('ALPHABET');

    expect(array_keys($glyphs))->toEqualCanonicalizing(str_split($alphabet));

    // A glyph whose path is malformed renders as nothing, and a code with an invisible
    // character is one a shopkeeper cannot answer. Only the SVG path grammar is allowed.
    foreach ($glyphs as $character => $path) {
        expect($path)->toMatch('/^[MmLlHhVvCcSsQqTtAaZz0-9 .,\-]+$/', "Glyph {$character} is not a path.");
    }
});

it('draws one path per character of the code', function (): void {
    $svg = app(SecurityCode::class)->render();

    // The glyph group is the last one; everything before it is noise. Counting the whole
    // document would count the noise too and pass on a drawing with no letters in it.
    $glyphGroup = substr($svg, (int) strrpos($svg, '<g '));

    expect(substr_count($glyphGroup, '<path'))->toBe(5);
});

it('refuses a sign-in with no code, with a wrong code, and with the wrong case-free match', function (string $answer): void {
    $this->withSession([SecurityCode::SESSION_KEY => 'HAMYR'])
        ->post($this->url.'/login', [
            'mobile' => '09121234567',
            'password' => 'password',
            'security_code' => $answer,
        ])
        ->assertSessionHasErrors('security_code');

    expect(auth()->check())->toBeFalse();
})->with([
    'a wrong code' => 'WRONG',
    'an empty string' => '',
    'a near miss' => 'HAMY',
]);

it('accepts the code in lower case and in Persian digits', function (): void {
    // A shopkeeper on an Iranian keyboard types «۴» for a drawn 4. Refusing that is
    // refusing a correct answer.
    $this->withSession([SecurityCode::SESSION_KEY => 'HAM47'])
        ->post($this->url.'/login', [
            'mobile' => '09121234567',
            'password' => 'password',
            'security_code' => 'ham۴۷',
        ])
        ->assertRedirect(route('dashboard'));

    expect(auth()->check())->toBeTrue();
});

it('spends the code: the same answer does not work twice', function (): void {
    $session = [SecurityCode::SESSION_KEY => 'HAMYR'];

    $this->withSession($session)->post($this->url.'/login', [
        'mobile' => '09121234567',
        'password' => 'wrong-password',
        'security_code' => 'HAMYR',
    ])->assertSessionHasErrors('mobile');

    // The credentials were wrong, so the code was still consumed. Replaying it against
    // the session it left behind must not authenticate anything.
    $this->post($this->url.'/login', [
        'mobile' => '09121234567',
        'password' => 'password',
        'security_code' => 'HAMYR',
    ])->assertSessionHasErrors('security_code');

    expect(auth()->check())->toBeFalse();
});

it('hands out a fresh drawing on request, and never the same one twice', function (): void {
    $first = $this->get($this->url.'/login/security-code')->assertOk();
    $first->assertHeader('Content-Type', 'image/svg+xml');

    $a = session(SecurityCode::SESSION_KEY);
    $this->get($this->url.'/login/security-code')->assertOk();
    $b = session(SecurityCode::SESSION_KEY);

    // Five characters from a 26-glyph alphabet: a repeat is possible and would make this
    // flaky, so the assertion is on the SESSION being rewritten, not on the two differing.
    expect($a)->toBeString()->toHaveLength(5);
    expect($b)->toBeString()->toHaveLength(5);
});

it('draws only characters a shopkeeper cannot misread', function (): void {
    // 0/O, 1/I/l, 5/S, 2/Z and 8/B are the pairs that get typed wrong, and a wrong code
    // is a shopkeeper locked out of their own till at nine in the morning.
    foreach (range(1, 40) as $ignored) {
        $this->get($this->url.'/login/security-code');

        expect(session(SecurityCode::SESSION_KEY))
            ->toMatch('/^[ACDEFGHJKLMNPQRTUVWXY34679]{5}$/');
    }
});
