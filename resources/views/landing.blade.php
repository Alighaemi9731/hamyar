<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>موبایل‌یار — نرم‌افزار فروشگاه موبایل: فروش، تعمیرات، اقساط</title>
    <meta name="description" content="موبایل‌یار کار روزانهٔ مغازهٔ موبایل را می‌بندد: فروش سریال‌دار با IMEI، تعمیرات، اقساط و چک، پیامک خودکار و گزارش سود. ۱۴ روز رایگان، بدون کارت بانکی.">
    <meta name="theme-color" content="#FFFFFF">
    <link rel="canonical" href="{{ url('/') }}">

    <meta property="og:type" content="website">
    <meta property="og:title" content="موبایل‌یار — نرم‌افزار فروشگاه موبایل">
    <meta property="og:description" content="از پذیرش تعمیر تا تسویه، روی یک قبض.">
    <meta property="og:locale" content="fa_IR">
    <meta property="og:url" content="{{ url('/') }}">

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
            موبایل‌یار
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

</main>

@include('landing.sections.closing')

</body>
</html>
