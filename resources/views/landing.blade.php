<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>سامانه همیار — نرم‌افزار فروشگاه موبایل: فروش، تعمیرات، اقساط</title>
    <meta name="description" content="سامانه همیار کار روزانهٔ مغازهٔ موبایل را می‌بندد: فروش سریال‌دار با IMEI، تعمیرات، اقساط و چک، پیامک خودکار و گزارش سود. پلن رایگان، بدون کارت بانکی.">
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

    {{-- The two faces the hero paints with, requested before the stylesheet parses.
         `crossorigin` is mandatory even same-origin or the preload is discarded. --}}
    <link rel="preload" as="font" type="font/woff2" crossorigin
          href="{{ Illuminate\Support\Facades\Vite::asset('resources/fonts/estedad-arabic-wght-normal.woff2') }}">
    <link rel="preload" as="font" type="font/woff2" crossorigin
          href="{{ Illuminate\Support\Facades\Vite::asset('resources/fonts/vazirmatn-arabic-wght-normal.woff2') }}">

    @vite(['resources/landing/landing.css', 'resources/landing/landing.js'])
</head>
<body>

<a href="#main" class="btn btn--quiet" style="position:absolute;inset-inline-start:-9999px"
   onfocus="this.style.insetInlineStart='1rem';this.style.insetBlockStart='1rem';this.style.zIndex='99'"
   onblur="this.style.insetInlineStart='-9999px'">پرش به محتوا</a>

{{-- ================================================================= nav === --}}
<header class="nav" data-nav>
    <div class="shell nav__inner">
        <a href="/" class="nav__brand">
            <svg class="nav__mark" viewBox="0 0 32 32" fill="none" aria-hidden="true">
                <rect x="6.5" y="2.5" width="19" height="27" rx="4" stroke="#0E1B2C" stroke-width="2"/>
                <path d="M12 24.5h8" stroke="#0066CC" stroke-width="2" stroke-linecap="round"/>
            </svg>
            همیار
        </a>

        <nav class="nav__links" aria-label="پیمایش اصلی">
            <a href="#problems">امکانات</a>
            <a href="#imei">شناسنامهٔ IMEI</a>
            <a href="#pricing">تعرفه‌ها</a>
            <a href="#faq">سوالات</a>
        </nav>

        {{-- Both go to the app host, which is now one address for every shop (ADR 0017). --}}
        <div class="nav__cta">
            <a href="{{ route('login') }}" class="btn btn--quiet">ورود</a>
            <a href="{{ route('register') }}" class="btn btn--primary">ثبت‌نام</a>
        </div>
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
