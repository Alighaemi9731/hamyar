<?php

declare(strict_types=1);

/**
 * The quota layer's boundary, made mechanical.
 *
 * Golden rule 6 says cross-module calls go through events or a public interface, and
 * `docs/architecture.md` says a module may not reference another module's Services. That
 * rule has never had a test — the codebase crosses it in several places for historical
 * reasons, and fencing the whole tree today would fail on imports nobody is proposing to
 * change (Sales → CRM's ledger, Repairs → Sales' finaliser).
 *
 * This fences the one boundary being built right now, while it is still cheap to keep
 * clean. Eighteen modules are about to call `QuotaGuard::consume()`; if even one of them
 * reaches for Platform's implementation instead of the shared-kernel contract, the
 * dependency direction inverts and every later module copies the shortcut.
 */
test('no module reaches into the quota implementation', function (): void {
    // The contract lives in App\Support\Quota and Platform implements it. A module that
    // imported the implementation would be depending on Platform's internals to count its
    // own invoices — and would break the moment the guard is swapped in a test.
    expect('App\Modules')
        ->not->toUse('App\Modules\Platform\Services\Quota')
        ->ignoring('App\Modules\Platform');
});

test('the quota kernel depends on no module', function (): void {
    // The other direction, and the one that actually rots: a single `use
    // App\Modules\Sales\...` here would make the shared kernel un-loadable without the
    // module it names, and the next metric would add another.
    expect('App\Support\Quota')->not->toUse('App\Modules');
});

test('the quota kernel does not know about HTTP', function (): void {
    // Same rule the domain layer already lives under. The guard is called from
    // controllers, services, jobs and artisan commands; one of those has no request.
    expect('App\Support\Quota')->not->toUse([
        'Illuminate\Http\Request',
        'Illuminate\Http\Response',
        'Inertia\Inertia',
    ]);
});
