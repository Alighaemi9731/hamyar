<?php

declare(strict_types=1);

use App\Providers\ModuleServiceProvider;

/**
 * Architecture tests — the enforcement half of ADR 0003.
 *
 * Module boundaries that are only written down get crossed the first time someone is
 * in a hurry. These fail the build instead.
 */

/** The eighteen modules of CLAUDE.md golden rule 6. */
const MODULES = [
    'Platform', 'Identity', 'Catalog', 'Inventory', 'Purchasing', 'Sales',
    'Repairs', 'CRM', 'Treasury', 'Cheques', 'Installments', 'Messaging',
    'Reporting', 'Files', 'Settings', 'Storefront', 'Hamta', 'Moadian',
];

it('has every module directory with the conventional layout', function (string $module): void {
    $path = base_path("app/Modules/{$module}");

    expect($path)->toBeDirectory();

    foreach (['Providers', 'Http', 'Models', 'Services', 'Events', 'Policies', 'database/migrations', 'tests'] as $directory) {
        expect("{$path}/{$directory}")->toBeDirectory();
    }

    expect("{$path}/Providers/{$module}ServiceProvider.php")->toBeFile();
})->with(MODULES);

it('auto-registers every module service provider', function (): void {
    $discovered = (new ModuleServiceProvider(app()))->discover();

    expect($discovered)->toHaveCount(count(MODULES));

    foreach (MODULES as $module) {
        expect($discovered)->toContain("App\\Modules\\{$module}\\Providers\\{$module}ServiceProvider");
    }
});

/**
 * The domain layer must not know the transport layer exists. If a Service needs a
 * Request, the controller is doing too little and the service too much — and the
 * service becomes untestable outside an HTTP context.
 */
test('the domain layer does not depend on Http', function (): void {
    expect([
        'App\Modules\Platform\Models', 'App\Modules\Platform\Services', 'App\Modules\Platform\Events',
        'App\Modules\Identity\Models', 'App\Modules\Identity\Services', 'App\Modules\Identity\Events',
        'App\Modules\Catalog\Models', 'App\Modules\Catalog\Services', 'App\Modules\Catalog\Events',
        'App\Modules\Inventory\Models', 'App\Modules\Inventory\Services', 'App\Modules\Inventory\Events',
        'App\Modules\Purchasing\Models', 'App\Modules\Purchasing\Services', 'App\Modules\Purchasing\Events',
        'App\Modules\Sales\Models', 'App\Modules\Sales\Services', 'App\Modules\Sales\Events',
        'App\Modules\Repairs\Models', 'App\Modules\Repairs\Services', 'App\Modules\Repairs\Events',
        'App\Modules\CRM\Models', 'App\Modules\CRM\Services', 'App\Modules\CRM\Events',
        'App\Modules\Treasury\Models', 'App\Modules\Treasury\Services', 'App\Modules\Treasury\Events',
        'App\Modules\Cheques\Models', 'App\Modules\Cheques\Services', 'App\Modules\Cheques\Events',
        'App\Modules\Installments\Models', 'App\Modules\Installments\Services', 'App\Modules\Installments\Events',
        'App\Modules\Messaging\Models', 'App\Modules\Messaging\Services', 'App\Modules\Messaging\Events',
        'App\Modules\Reporting\Models', 'App\Modules\Reporting\Services', 'App\Modules\Reporting\Events',
        'App\Modules\Files\Models', 'App\Modules\Files\Services', 'App\Modules\Files\Events',
        'App\Modules\Settings\Models', 'App\Modules\Settings\Services', 'App\Modules\Settings\Events',
        'App\Modules\Hamta\Models', 'App\Modules\Hamta\Services', 'App\Modules\Hamta\Events',
        'App\Modules\Moadian\Models', 'App\Modules\Moadian\Services', 'App\Modules\Moadian\Events',
        'App\Modules\Storefront\Models', 'App\Modules\Storefront\Services', 'App\Modules\Storefront\Events',
    ])->not->toUse([
        'Illuminate\Http\Request',
        'Illuminate\Http\Response',
        'Inertia\Inertia',
    ]);
});

/**
 * Controllers orchestrate; they do not query. A `DB::` call in a controller is how
 * a query ends up bypassing the tenant global scope (golden rule 1).
 */
test('controllers do not talk to the database directly', function (): void {
    expect('App\Modules')
        ->not->toUse(['Illuminate\Support\Facades\DB'])
        ->ignoring([
            'App\Modules\Platform\Services',
            'App\Modules\Reporting\Services',
        ]);
})->skip('Enabled in Phase 1 once modules contain controllers.');

test('nothing debugs in production code', function (): void {
    expect(['App', 'App\Modules'])->not->toUse(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die']);
});

test('strict types are declared everywhere in app/', function (): void {
    expect('App')->toUseStrictTypes();
});
