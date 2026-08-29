<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Reporting\Services\DashboardWidgets;
use App\Modules\Reporting\Support\ReportAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The front page — what needs doing today, for whoever is looking at it.
 *
 * ## Two gates per widget, and they are different questions
 *
 * A widget appears only if **the shop's plan includes the module** and **this user has
 * the permission**. They are not the same check and neither implies the other: a Basic
 * shop has no Cheques module at all, while a Pro shop's salesperson simply may not see
 * its cheques. Collapsing them would either sell a card the shop has not bought or hide
 * one it has (golden rule 7).
 *
 * The plan half is asked of `SubscriptionResolver` — the same object `EnsureModuleEnabled`
 * asks — rather than of the `features` shared prop, which is a convenience for hiding nav
 * and is not authorization.
 *
 * ## The dashboard itself is not plan-gated
 *
 * It lives outside `module:reporting`, because every shop on every plan has a front page.
 * What varies is how many cards are on it. A shop whose plan has no Reporting module still
 * opens somewhere after login, and «۴۰۳» is not a landing screen.
 *
 * ## Absent, not empty
 *
 * A card the viewer may not see is missing from the payload entirely rather than sent
 * with zeros. Zeros are an answer — «هیچ چکی سررسید ندارد» — and putting one in front of
 * somebody who was never allowed to ask is how a cashier ends up telling the owner there
 * are no cheques due.
 */
final class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardWidgets $widgets, SubscriptionResolver $plan): Response
    {
        /*
        | Narrowed to the tenant `User` once, here. `$request->user()` may also be a
        | central `PlatformUser` — that account never reaches a tenant hostname, but
        | typing it away at the door beats repeating the check at seven call sites.
        */
        $user = $request->user() instanceof User ? $request->user() : null;

        /*
        | Cost and margin, which the Salesperson role deliberately cannot see (Gate 1).
        | Asked of `ReportAccess` rather than of a permission name directly, so this
        | screen and the report viewer can never disagree about one person. Passed
        | *into* the widget rather than stripped out of its answer: see
        | DashboardWidgets on why the difference is load-bearing.
        */
        $withProfit = ReportAccess::showsMargin($user);

        $props = [
            'today' => null,
            'trend' => null,
            'repairs' => null,
            'abandoned' => null,
            'cheques' => null,
            'installments' => null,
            'low_stock' => null,
        ];

        if ($this->may($user, $plan, 'sales', 'sales.view')) {
            $props['today'] = $widgets->todaysTrade($withProfit);
            $props['trend'] = $widgets->salesTrend($withProfit);
        }

        if ($this->may($user, $plan, 'repairs', 'repairs.view')) {
            $props['repairs'] = $widgets->repairsInProgress();
            $props['abandoned'] = $widgets->abandonedDevices();
        }

        if ($this->may($user, $plan, 'cheques', 'cheques.view')) {
            $props['cheques'] = $widgets->chequesDue();
        }

        if ($this->may($user, $plan, 'installments', 'installments.view')) {
            $props['installments'] = $widgets->overdueInstallments();
        }

        if ($this->may($user, $plan, 'inventory', 'inventory.view')) {
            $props['low_stock'] = $widgets->lowStock();
        }

        return Inertia::render('Reporting::Dashboard/Index', [
            ...$props,
            'shows_profit' => $withProfit,
            // Quick actions are the one widget everybody gets, but "everybody" still
            // means "what this person can actually do" — a link to a screen that 403s
            // is a worse welcome than no link.
            'can' => [
                'sell' => $this->may($user, $plan, 'sales', 'sales.create'),
                'intake_repair' => $this->may($user, $plan, 'repairs', 'repairs.create'),
                'add_party' => $this->may($user, $plan, 'crm', 'crm.create'),
                'purchase' => $this->may($user, $plan, 'purchasing', 'purchasing.create'),
                'reports' => $this->may($user, $plan, 'reporting', 'reporting.view'),
            ],
        ]);
    }

    /**
     * The module is switched on AND the user holds the permission.
     *
     * `grants()` used to mean "this shop's plan includes the module". Since DECISION GATE 6
     * it means "we have this module switched on for everybody" — so a widget disappears
     * only when Moadian has no provider behind it (ADR 0011) or we have taken something
     * down, never because a shop is on the free rung. What the plan decides now is how
     * much each shop may record, and that is enforced where the recording happens.
     */
    private function may(?User $user, SubscriptionResolver $plan, string $module, string $permission): bool
    {
        return $user instanceof User
            && $plan->grants($module)
            && $user->can($permission);
    }
}
