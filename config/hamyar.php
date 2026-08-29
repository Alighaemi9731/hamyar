<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Hamyar — product configuration
|--------------------------------------------------------------------------
|
| Knobs that belong to the product rather than to a framework component, and that an
| operator may need to turn without a deploy. Business data — prices, plan limits — is
| deliberately NOT here: it lives in the database and is edited in the Filament panel, so
| that changing what a shop pays is never a release (roadmap 11.4).
|
| Everything reads through `config()`, never `env()` outside this file, and the file is
| cacheable — `tests/Feature/ConfigIsCacheableTest.php` enforces that.
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Quotas (Phase 12 · ADR 0018)
    |----------------------------------------------------------------------
    */

    'quota' => [

        /*
        | Whose limits apply to a shop with no usable subscription.
        |
        | A lapsed shop is never locked out — it falls back to the free plan and keeps
        | working with its credits (DECISION GATE 6, item 4). This names the plan by code
        | rather than assuming one, because the code is data: the panel can rename a plan,
        | and the fallback has to keep pointing at the one that is actually free.
        |
        | If it points at no plan at all the resolver THROWS rather than assuming
        | unlimited. A missing fallback is a misconfiguration, and the lenient reading
        | would hand every lapsed shop unlimited everything — failing open, in the one
        | layer whose whole job is to fail closed.
        */
        'fallback_plan' => env('QUOTA_FALLBACK_PLAN', 'basic'),

        /*
        | How full a credit has to be before the shop is warned, as a fraction.
        |
        | Warned once per metric per period, in the shell and optionally by SMS. Below
        | this the meters are quiet: a shop that is using what it pays for does not need
        | to be told so.
        */
        'warning_ratio' => (float) env('QUOTA_WARNING_RATIO', 0.8),

        /*
        | How many messages the platform will pay for, per shop per day.
        |
        | System messages — «سهمیهٔ پیامک شما تمام شد» and, when Phase 8 wires them, password
        | reset and invitations — bypass both the shop's quota and its wallet, because a
        | message telling someone they are out of messages must not itself be refused
        | (DECISION GATE 6, item 16). That means WE pay the gateway, so it needs a ceiling:
        | without one, a loop between an alert and the thing it is alerting about bills us
        | indefinitely.
        */
        'system_sms_daily_cap' => (int) env('QUOTA_SYSTEM_SMS_DAILY_CAP', 20),

    ],

];
