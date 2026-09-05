<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Persian copy for the six auth screens
|--------------------------------------------------------------------------
|
| The auth screens are Blade (ADR 0021), and CLAUDE.md's convention is that Blade and
| public pages read their Persian from `lang/fa/**` rather than carrying it inline —
| only React pages keep their strings in the component, because there is no i18n layer
| on that side. This is the file that convention was waiting for: before it, `lang/fa`
| held `validation.php` and nothing else, and the login and register views were the two
| Blade pages in the product with Persian typed into the markup.
|
| ## Shape
|
| One group per screen, one flat level of keys inside it, keyed by what the string IS on
| the page — `title`, `hint`, `submit` — rather than by where it happens to sit today.
| `__('auth.reset.submit')` reads as an address; a deeper tree would not.
|
| ## What is deliberately absent
|
| Laravel's stock `auth.php` ships `failed`, `password` and `throttle` for the framework's
| own scaffolding. Nothing here calls them: `LoginController` and `LoginRequest` write
| their own refusals, in this product's voice, naming what to do next — «شماره موبایل یا
| رمز عبور درست نیست.» rather than a generic failure. Carrying the framework's three keys
| unused would be three Persian strings the copy gate has to police and nobody ever reads.
| If a call site for them ever appears, add them then.
|
| Every string here follows `docs/brand/voice.md` and is checked by `bin/check-copy-terms`.
|
*/

return [

    /*
    | Forgetting the password. The confirmation this screen shows is identical whether or
    | not the number has an account — see `PasswordResetController::store()`, which is
    | where the reason lives: any other wording turns the form into an oracle for who
    | works at a shop.
    */
    'forgot' => [
        'title' => 'بازیابی رمز عبور',
        'hint' => 'شمارهٔ موبایل خود را وارد کنید تا لینک بازیابی برایتان ارسال شود.',
        'mobile' => 'شمارهٔ موبایل',
        'submit' => 'ارسال لینک بازیابی',
        'back' => 'بازگشت به ورود',
    ],

    /* Choosing the new password, reached from the link. */
    'reset' => [
        'title' => 'رمز عبور تازه',
        'hint' => 'رمز تازه‌ای برای حساب خود انتخاب کنید.',
        'password' => 'رمز عبور جدید',
        'confirmation' => 'تکرار رمز عبور',
        'submit' => 'تغییر رمز عبور',
        'back' => 'بازگشت به ورود',
    ],

    /*
    | The second factor at login. Two ways through it, and the copy for both lives here:
    | `landing.js` swaps the hint and the button label off data attributes rather than
    | holding Persian of its own.
    */
    'two_factor' => [
        'title' => 'تأیید دومرحله‌ای',
        'hint' => 'کد ۶ رقمی را از برنامهٔ احراز هویت خود وارد کنید.',
        'recovery_hint' => 'یکی از کدهای بازیابی خود را وارد کنید. هر کد فقط یک‌بار قابل استفاده است.',
        'code' => 'کد تأیید',
        'recovery' => 'کد بازیابی',
        'submit' => 'تأیید و ورود',
        'use_recovery' => 'به برنامه دسترسی ندارم — کد بازیابی',
        'use_app' => 'استفاده از کد برنامهٔ احراز هویت',
    ],

    /*
    | Joining a shop from an invitation link. `:name` is the invited person's own name as
    | the shop typed it into the invitation, so it is echoed escaped in the view.
    */
    'invitation' => [
        'title' => ':name عزیز، خوش آمدید',
        'hint' => 'برای ورود، یک رمز عبور انتخاب کنید.',
        'mobile' => 'شمارهٔ موبایل شما',
        'password' => 'رمز عبور',
        'confirmation' => 'تکرار رمز عبور',
        'submit' => 'ساخت حساب و ورود',
    ],

];
