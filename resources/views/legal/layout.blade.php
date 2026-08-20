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
    <title>@yield('title') — مویار</title>
    <meta name="robots" content="index, follow">
    <meta name="theme-color" content="#070B0E">
    @vite(['resources/landing/landing.css'])
    <style>
        .legal { max-inline-size: 44rem; margin-inline: auto; padding-block: 3.5rem 5rem; }
        .legal h1 { font-size: clamp(1.875rem, 4vw, 2.75rem); margin-block-end: 0.5rem; }
        .legal h2 { font-size: 1.3125rem; margin-block: 2.25rem 0.625rem; color: #fff; }
        .legal p, .legal li { color: var(--color-say); margin-block-end: 0.875rem; }
        .legal ul { padding-inline-start: 1.25rem; list-style: disc; }
        .legal a { color: var(--color-firooze); text-decoration: underline; text-underline-offset: 3px; }
        .legal__meta { color: var(--color-say-soft); font-size: 0.875rem; margin-block-end: 2.5rem; }
    </style>
</head>
<body>

<header class="l-nav" data-nav>
    <div class="l-shell l-nav__inner">
        <a href="/" class="l-nav__brand">
            <svg class="l-nav__mark" viewBox="0 0 32 32" fill="none" aria-hidden="true">
                <rect x="6" y="2" width="20" height="28" rx="4" stroke="#3FD9C8" stroke-width="2"/>
                <path d="M11 24h10" stroke="#FFD84D" stroke-width="2" stroke-linecap="round"/>
            </svg>
            مویار
        </a>
        <div class="l-nav__cta">
            <a href="/" class="l-btn l-btn--ghost">بازگشت به صفحهٔ اصلی</a>
        </div>
    </div>
</header>

<main class="l-shell legal">
    <h1>@yield('title')</h1>
    <p class="legal__meta">آخرین بازبینی: @yield('updated')</p>
    @yield('body')
</main>

<footer class="l-foot">
    <div class="l-shell l-foot__row">
        <span>© ۱۴۰۵ مویار</span>
        <nav style="display:flex;gap:1.25rem" aria-label="پیوندهای حقوقی">
            <a href="{{ route('legal.terms') }}">قوانین و شرایط</a>
            <a href="{{ route('legal.privacy') }}">حریم خصوصی</a>
        </nav>
    </div>
</footer>

</body>
</html>
