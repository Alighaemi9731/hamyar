{{--
    The og:image, as a page.

    Rendered at 1200×630 by `bin/shots` (screen id `og`) into `resources/landing/og/og.png`
    and referenced from the landing head through Vite::asset(), so a re-capture invalidates
    its own URL. Built from the same brand layer and the same product capture as the page
    it represents — the dashboard the visitor will actually see — rather than from an
    illustration. Local/testing only: the route sits beside /design and never ships.

    No hostname literal: the domain shown is config('app.domain') (golden rule 1b).
--}}
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex">
    <title>og — سامانه همیار</title>
    @vite(['resources/landing/landing.css'])
    <style>
        html, body { margin: 0; width: 1200px; height: 630px; overflow: hidden; }
        body { background: var(--color-page); color: var(--color-navy); font-family: var(--font-sans); }
        .og { position: relative; width: 1200px; height: 630px; }
        .og__copy { position: absolute; inset-block: 0; inset-inline-start: 0; width: 640px; padding: 72px 72px 64px; display: flex; flex-direction: column; justify-content: space-between; }
        .og__brand { display: flex; align-items: center; gap: 16px; font-family: var(--font-display); font-weight: 700; font-size: 34px; letter-spacing: -0.01em; }
        .og__brand svg { width: 52px; height: 52px; }
        .og__title { font-family: var(--font-display); font-weight: 600; font-size: 60px; line-height: 1.15; letter-spacing: -0.02em; margin: 0; text-wrap: balance; }
        .og__lede { font-size: 24px; line-height: 1.6; color: var(--color-navy-soft); margin: 20px 0 0; }
        .og__domain { font-size: 22px; color: var(--color-navy-mute); font-variant-numeric: tabular-nums; }
        .og__domain b { color: var(--color-accent); font-weight: 600; }
        .og__stage { position: absolute; inset-block: 0; inset-inline-end: 0; width: 560px; background: var(--color-navy-900); overflow: hidden; }
        .og__frame { position: absolute; inset-block-start: 64px; inset-inline-start: 64px; width: 720px; border-radius: 18px; box-shadow: 0 30px 60px -20px rgb(0 0 0 / 0.55); background: #fff; overflow: hidden; }
        .og__frame img { display: block; width: 720px; height: auto; }
    </style>
</head>
<body>
<div class="og">
    <div class="og__copy">
        <div class="og__brand">
            {!! file_get_contents(resource_path('brand/mark-c.svg')) !!}
            <span>سامانه همیار</span>
        </div>
        <div>
            <h1 class="og__title">همهٔ کارِ فروشگاه موبایل، در یک سامانه</h1>
            <p class="og__lede">فروش با IMEI، تعمیرات، اقساط و چک، پیامک و گزارش سود.</p>
        </div>
        <div class="og__domain" dir="ltr"><b>{{ config('app.domain') }}</b></div>
    </div>
    <div class="og__stage">
        <div class="og__frame">
            <img src="{{ Vite::asset('resources/landing/shots/dashboard.webp') }}" alt="">
        </div>
    </div>
</div>
</body>
</html>
