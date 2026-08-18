<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\ActivityLogController;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * Read-only is a property of the audit log, not a habit of whoever last edited it.
 *
 * An audit trail an operator can edit is not an audit trail. That is easy to agree with
 * and easy to undo: the viewer is a normal Inertia screen, and the day someone wants to
 * hide a noisy entry or bulk-delete last year, adding a `DELETE` route beside the `GET`
 * is a two-line change that no reviewer would necessarily read as an architectural one.
 *
 * These tests make it a change you have to delete a test to make — and the test says
 * why. Entries leave by ageing out on a retention schedule, never by request.
 */

/**
 * Every registered route that reaches the audit-log controller.
 *
 * @return list<RoutingRoute>
 */
function activityLogRoutes(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        static function (RoutingRoute $route): bool {
            $action = $route->getAction('controller');

            return is_string($action) && str_starts_with($action, ActivityLogController::class);
        },
    ));
}

it('registers the audit-log viewer', function (): void {
    // The guard below is vacuously true if the controller stops being routed at all,
    // so the first assertion is that there is something to guard.
    expect(activityLogRoutes())->not->toBeEmpty();
});

it('reaches the audit log through no verb that could change it', function (): void {
    foreach (activityLogRoutes() as $route) {
        expect($route->methods())
            ->each->toBeIn(['GET', 'HEAD'], "[{$route->uri()}] must not be reachable by a mutating verb.");
    }
});

it('offers no ability to write an activity entry', function (): void {
    // The policy is the other half: a `create`, `update` or `delete` method here would
    // be the first thing a new write route reached for, and its presence is the signal
    // that somebody is building one.
    $abilities = get_class_methods(App\Modules\Identity\Policies\ActivityPolicy::class);

    expect($abilities)->toBe(['viewAny']);
});

it('exposes no controller action that is not a read', function (): void {
    $actions = array_values(array_filter(
        get_class_methods(ActivityLogController::class),
        static fn (string $method): bool => ! str_starts_with($method, '__')
            && (new ReflectionMethod(ActivityLogController::class, $method))->isPublic()
            && (new ReflectionMethod(ActivityLogController::class, $method))->getDeclaringClass()->getName() === ActivityLogController::class,
    ));

    expect($actions)->toBe(['index']);
});
