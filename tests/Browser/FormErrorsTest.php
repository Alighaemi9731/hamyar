<?php

declare(strict_types=1);

/**
 * The rule from CLAUDE.md, asserted in a browser because that is where it fails.
 *
 * > Every form has a home for errors that belong to no field. A validation failure on
 * > `accessories` or `lines` has nowhere to render beside an input, so without a general
 * > error region the submit button silently does nothing — and the operator, with a
 * > customer at the counter, presses it again and concludes the software is broken.
 *
 * ## Why a browser test rather than a feature test
 *
 * A feature test asserting `assertSessionHasErrors('lines')` passes on a form that shows
 * the operator nothing. It proves the server refused, which was never in doubt; the
 * question this rule is about is whether the *screen changed*. Only a rendered page can
 * answer that, and the whole defect is the absence of something — which is invisible to
 * every assertion that looks for a presence.
 *
 * ## The gallery is the unit test this component cannot otherwise have
 *
 * There is no JS test runner in this project, so `/design` is where a component's states
 * are pinned. `FormErrors` has real branching — prefix collapsing, the `quota` exclusion,
 * de-duplication — and rendering each case on the gallery is what makes those assertable.
 */
pest()->group('browser');

/*
| No fixture, deliberately.
|
| `/design` sits behind `web` alone — no `auth`, no `tenant` — because it is a component
| gallery rather than a screen a shop uses. Building a tenant and signing somebody in
| would be four moving parts this test does not depend on, and each one is a way for it
| to fail for a reason that has nothing to do with `FormErrors`.
*/

it('renders each FormErrors state on the gallery', function (): void {
    $page = visit('/design');

    $page->assertNoJavascriptErrors()
        // The single-message case, and the multi-message case beneath it.
        ->assertSee('حداقل یک قلم کالا لازم است.')
        ->assertSee('جمع پرداخت‌ها با مبلغ فاکتور برابر نیست.')
        // A nested key whose parent the form already handles: `lines.2.quantity` must NOT
        // appear, because the form shows it beside its own table. One problem rendered
        // twice reads as two problems.
        ->assertDontSee('تعداد باید بیشتر از صفر باشد.')
        ->assertSee('کد IMEI نامعتبر است.')
        // A key the form places itself is not repeated here.
        ->assertDontSee('نام لازم است.')
        // And `quota` never renders here: <QuotaBlock> shows it once in the shell, with a
        // price and an upgrade button. A bare sentence above that would be a worse version
        // of the same message.
        ->assertDontSee('سهمیهٔ ۳۰۰ فاکتور این ماه تمام شد.');
});
