{{--
    Shared shell for the two public legal pages.

    Same dark landing skin, but a reading measure rather than a layout: these exist to
    be read once, carefully, by somebody deciding whether to trust us with a shop's
    books. No animation, no effects bundle — landing.js is not loaded here at all.
--}}
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <title>@yield('title') — سامانه همیار</title>
    <meta name="robots" content="index, follow">
    <meta name="theme-color" content="#FFFFFF">
    @vite(['resources/landing/landing.css'])
    {{--
        These two pages had their own private type scale: a `clamp()` starting at 1.875rem
        that exists nowhere else, and two literal sizes that happen to equal tokens the
        stylesheet already defines. Worse, the headings inherited the body face — so on the
        two pages a prospect reads before trusting us with a shop's books, every heading was
        set in Vazirmatn while every heading everywhere else is Estedad. The pairing (ADR
        0020, amended 2026-09-05) says nothing but the display face renders a heading; this
        was the one surface that never got the message, because no sweep of the product
        looks inside a Blade `<style>` block.

        Sizes are tokens now. `--text-lg` is the 1.3125rem this file was spelling out, and
        `--text-fine` the 0.875rem — the latter also gains the 14px phone floor for free.
    --}}
    <style>
        .legal { max-inline-size: 44rem; margin-inline: auto; padding-block: 3.5rem 5rem; }
        .legal h1, .legal h2 { font-family: var(--font-display); letter-spacing: var(--tracking-heading); }
        .legal h1 { font-size: var(--text-section); font-weight: 800; line-height: 1.15; margin-block-end: 0.5rem; }
        .legal h2 { font-size: var(--text-lg); font-weight: 700; margin-block: 2.25rem 0.625rem; color: var(--color-navy); }
        .legal p, .legal li { color: var(--color-navy-soft); margin-block-end: 0.875rem; }
        .legal ul { padding-inline-start: 1.25rem; list-style: disc; }
        .legal a { color: var(--color-accent); text-decoration: underline; text-underline-offset: 3px; }
        .legal__meta { color: var(--color-navy-mute); font-size: var(--text-fine); margin-block-end: 2.5rem; }
        .legal__links { display: flex; gap: 1.25rem; }
    </style>
</head>
<body>

<header class="nav" data-nav>
    <div class="shell nav__inner">
        {{--
            The same wordmark the landing signs with, read from the one file.

            This carried an inline copy of the retired symbol — a handset outline with two
            hardcoded hex values, `#0E1B2C` and `#0066CC`, written straight into the markup
            where no token could reach them. So the terms and privacy pages had their own
            private idea of the brand, and it survived both the ink change of ADR 0020 and
            the owner retiring the symbol on 2026-09-04, because nothing that swept the
            product ever looked in here.

            Read per request rather than shared from a parent: these two pages have their
            own layout and no landing variable reaches them. One `File::get` on a page
            nobody loads twice is the right price for having exactly one logo.
        --}}
        <a href="/" class="nav__brand" aria-label="همیار — صفحهٔ نخست">
            <span class="nav__wordmark" aria-hidden="true">
                {!! Illuminate\Support\Facades\File::get(resource_path('brand/wordmark.svg')) !!}
            </span>
        </a>
        <div class="nav__cta">
            <a href="/" class="btn btn--quiet">بازگشت به صفحهٔ اصلی</a>
        </div>
    </div>
</header>

<main class="shell legal">
    <h1>@yield('title')</h1>
    <p class="legal__meta">آخرین بازبینی: @yield('updated')</p>
    @yield('body')
</main>

<footer class="foot">
    <div class="shell foot__row">
        {{-- Rendered, not typed. «۱۴۰۵» was correct when it was written and becomes quietly
             wrong at Nowruz, on the page whose whole purpose is to look trustworthy. The
             landing's closing band already renders its year; this did not. --}}
        <span>© {{ jalali(now(), 'Y') }} همیار</span>
        <nav class="legal__links" aria-label="پیوندهای حقوقی">
            <a href="{{ route('legal.terms') }}">قوانین و شرایط</a>
            <a href="{{ route('legal.privacy') }}">حریم خصوصی</a>
        </nav>
    </div>
</footer>

</body>
</html>
