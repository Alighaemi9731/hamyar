<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>سامانه همیار — نرم‌افزار فروشگاه موبایل: فروش، تعمیرات، اقساط</title>
    <meta name="description" content="سامانه همیار کار روزانهٔ فروشگاه موبایل را می‌بندد: فروش سریال‌دار با IMEI، تعمیرات، اقساط و چک، پیامک خودکار و گزارش سود. پلن رایگان، بدون کارت بانکی.">
    <meta name="theme-color" content="#FFFFFF">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <link rel="canonical" href="{{ url('/') }}">

    <meta property="og:type" content="website">
    <meta property="og:title" content="سامانه همیار — نرم‌افزار فروشگاه موبایل">
    <meta property="og:description" content="از پذیرش تعمیر تا تسویه، روی یک قبض.">
    <meta property="og:locale" content="fa_IR">
    <meta property="og:url" content="{{ url('/') }}">
    {{-- Rendered from the brand layer and the dashboard capture by `bin/shots og`; hashed by
         Vite so a re-capture invalidates the URL every unfurler has cached. --}}
    <meta property="og:image" content="{{ Vite::asset('resources/landing/og/og.png') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="سامانه همیار — نرم‌افزار فروشگاه موبایل">
    <meta name="twitter:description" content="فروش با IMEI، تعمیرات، اقساط و چک، پیامک و گزارش سود — در یک سامانه.">
    <meta name="twitter:image" content="{{ Vite::asset('resources/landing/og/og.png') }}">

    {{-- The two weights the fold paints with — the heading and the text — requested
         before the stylesheet parses. One family since ADR 0020, so this is 400 and 700
         of it rather than two families. `crossorigin` is mandatory even same-origin or
         the preload is discarded and the file fetched twice. --}}
    <link rel="preload" as="font" type="font/woff2" crossorigin
          href="{{ Illuminate\Support\Facades\Vite::asset('resources/fonts/ibm-plex-sans-arabic-arabic-700-normal.woff2') }}">
    <link rel="preload" as="font" type="font/woff2" crossorigin
          href="{{ Illuminate\Support\Facades\Vite::asset('resources/fonts/ibm-plex-sans-arabic-arabic-400-normal.woff2') }}">

    @vite(['resources/landing/landing.css', 'resources/landing/landing.js'])
</head>
<body>

{{-- CSS-only. It carried `onfocus`/`onblur` handlers that the nonce-only CSP refused
     outright, so the one affordance a keyboard visitor needs was never visible and the
     console carried two errors on every visit (16.0 baseline, finding 7). --}}
<a href="#main" class="skip">پرش به محتوا</a>

{{-- ================================================================= nav === --}}
@php
    // One source for the mark: the same file `components/brand-mark.tsx` imports. The
    // brand dot becomes `currentColor` through CSS on the tile, not by editing the file.
    $mark = Illuminate\Support\Facades\File::get(resource_path('brand/mark.svg'));
    $links = [
        '#problems' => 'امکانات',
        '#imei' => 'شناسنامهٔ IMEI',
        '#pricing' => 'تعرفه‌ها',
        '#faq' => 'سوالات',
    ];
@endphp
<header class="nav">
    <div class="shell nav__inner">
        <a href="/" class="nav__brand" aria-label="سامانه همیار — صفحهٔ نخست">
            <span class="nav__mark" aria-hidden="true">{!! $mark !!}</span>
            <span class="nav__wordmark">سامانه همیار</span>
        </a>

        <nav class="nav__links" aria-label="پیمایش اصلی">
            @foreach ($links as $href => $label)
                <a href="{{ $href }}">{{ $label }}</a>
            @endforeach
        </nav>

        {{-- Both go to the app host, which is now one address for every shop (ADR 0017). --}}
        <div class="nav__cta">
            <a href="{{ route('login') }}" class="btn btn--quiet">ورود</a>
            <a href="{{ route('register') }}" class="btn btn--primary">ثبت‌نام</a>
        </div>

        <details class="nav__menu">
            <summary class="nav__toggle" aria-label="منو">
                <svg class="nav__toggle-open" viewBox="0 0 24 24" width="22" height="22" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="M4 7h16M4 12h16M4 17h16"/>
                </svg>
                <svg class="nav__toggle-close" viewBox="0 0 24 24" width="22" height="22" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="M6 6l12 12M18 6L6 18"/>
                </svg>
            </summary>

            <div class="nav__panel">
                @foreach ($links as $href => $label)
                    <a href="{{ $href }}">{{ $label }}</a>
                @endforeach

                <div class="nav__panel-actions">
                    <a href="{{ route('login') }}" class="btn btn--quiet">ورود</a>
                    <a href="{{ route('register') }}" class="btn btn--primary">ثبت‌نام</a>
                </div>
            </div>
        </details>
    </div>
</header>

<main id="main">

@include('landing.sections.hero')
@include('landing.sections.trust')
@include('landing.sections.problems')
@include('landing.sections.imei')
@include('landing.sections.tour')
@include('landing.sections.pricing')
@include('landing.sections.faq')

{{-- The closing renders in two parts and the `</main>` boundary runs between them.

     The CTA is the page's primary conversion target, so it is content and belongs inside
     the main landmark — and the skip link at the top of this file targets `#main`, so a
     CTA outside it is a CTA a keyboard user is invited to skip. The site `<footer>` has
     the opposite requirement: inside `<main>` it stops being `contentinfo` at all.

     One `<div>` cannot straddle `</main>`, so the partial takes a `part` and both halves
     carry `.signoff` — one navy ground, no seam. Nothing may be inserted between these
     two includes. See landing/sections/closing.blade.php. --}}
@include('landing.sections.closing', ['part' => 'call'])

</main>

@include('landing.sections.closing', ['part' => 'tail'])

</body>
</html>
