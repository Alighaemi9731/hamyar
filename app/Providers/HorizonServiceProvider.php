<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Platform\Models\PlatformUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Who may look at the queue.
 *
 * ## This is a tenancy boundary, not an admin convenience
 *
 * The Horizon dashboard renders **job payloads**. `SendSmsJob` carries a customer's
 * phone number and the text of the message; `SubmitInvoiceJob` carries an invoice.
 * Those are serialised into Redis by every shop on the platform and displayed, side by
 * side, on one screen with no tenant scoping — RLS cannot help, because none of it is
 * a database row.
 *
 * So a shop owner reaching this page reads the other forty-nine shops' customers. It
 * is the widest single leak available in the product, and it does not look like one:
 * it looks like a queue monitor.
 *
 * ## Which is why the gate names the guard
 *
 * The default published gate checks an email against a list of `$user` — and `$user`
 * is whoever the *default* guard resolved, which on a tenant subdomain is a shop's
 * user. An allow-list of platform emails would then be compared against a shop
 * employee, fail, and deny correctly by accident.
 *
 * This one asks the `platform` guard directly and accepts nothing else. Shop staff live
 * on a different guard and a different table precisely so that questions like this have
 * a one-word answer (see `PlatformUser`), and `is_active` is re-checked here rather
 * than trusted from login, so revoking an account closes the dashboard immediately
 * rather than whenever the session happens to expire.
 */
final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        /*
        | Horizon's notifications go to the platform's own channel, never to a tenant.
        | A queue backlog is Hamyar's operational problem; a shop cannot act on it and
        | telling them turns an internal metric into a support call.
        */
        Horizon::routeMailNotificationsTo(config()->string('mail.from.address'));
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', function (mixed $user = null): bool {
            unset($user);

            $platformUser = Auth::guard('platform')->user();

            return $platformUser instanceof PlatformUser && $platformUser->is_active;
        });
    }
}
