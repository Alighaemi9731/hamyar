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
        /* Height only. The old rule was `width: 52px; height: 52px` — right for the square
           symbol that used to sit here, and a squashed wordmark now that an 8.87:1 drawing
           does. Sizing a logo by one axis is the rule everywhere it appears, and it is why
           the commissioned mark landing at 8.87:1 instead of 6.5:1 needed nothing here:
           46px tall is 408px wide, inside the 496px this column has. */
        .og__brand { display: flex; align-items: center; color: var(--color-accent); }
        .og__brand svg { height: 46px; width: auto; }
        /* 800, not the 600 this asked for: Estedad ships 700 and 800 and nothing between
           (ADR 0020 amendment), so 600 was being snapped or synthesised on the one image
           that represents this product in every link unfurl. */
        .og__title { font-family: var(--font-display); font-weight: 800; font-size: 60px; line-height: 1.15; letter-spacing: -0.02em; margin: 0; text-wrap: balance; }
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
        {{--
            The wordmark, and nothing beside it.

            This showed the retired symbol next to «سامانه همیار» set as text — so the card
            that represents this product in every Telegram, WhatsApp and Twitter unfurl was
            the last place still advertising a logo the owner dropped on 2026-09-04. It is
            also the surface nobody on the team ever looks at, which is why it outlived the
            symbol everywhere else.

            Re-render with `bin/shots og` after touching this file, or the PNG on disk keeps
            the old card and the change is invisible where it matters.
        --}}
        <div class="og__brand">
            {!! file_get_contents(resource_path('brand/wordmark.svg')) !!}
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
