{{--
    Gate 16.2 · direction C — the standing exit: the category standard, played straight.

    Not a world of its own; the modern SaaS landing executed at the craft level of the
    references the owner names, without irony or a smuggled quirk. Split hero with the
    product in a real browser frame; a proof strip; a bento tour of real captures; the
    plan rows. Restraint is the whole point: one accent, one shadow, a wider stage than
    the last landing so the sides are composition rather than margin.

    Offered on every round because it is the owner's door, never recommended by the
    build thread. If chosen, the owner names two or three products this should sit
    beside and their finish becomes the bar.
--}}
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>جهت C — استاندارد — سامانه همیار</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @vite(['resources/landing/gate.css'])
    <style>
        :root { --c-ink: var(--color-navy); --c-soft: var(--color-navy-soft); --c-mute: var(--color-navy-mute); --c-rule: var(--color-hair); --c-blue: var(--color-accent); --c-tint: #eef3f9; }
        html, body { overflow-x: clip; }
        body { background: #fff; color: var(--c-ink); font-size: 1.0625rem; line-height: 1.7; }
        .grid { display: grid; grid-template-columns: [full-start] minmax(1.25rem, 1fr) [wide-start] minmax(0, 6rem) [content-start] min(72rem, 100% - 2.5rem) [content-end] minmax(0, 6rem) [wide-end] minmax(1.25rem, 1fr) [full-end]; }
        .grid > * { grid-column: content; }
        .grid > .wide { grid-column: wide; }
        .grid > .full { grid-column: full; }

        .nav { position: sticky; inset-block-start: 0; z-index: 20; background: rgb(255 255 255 / .88); backdrop-filter: saturate(180%) blur(12px); border-block-end: 1px solid var(--c-rule); }
        .nav__in { display: flex; align-items: center; justify-content: space-between; gap: 1.5rem; height: 4rem; }
        .nav__brand { display: inline-flex; align-items: center; gap: .6rem; font-family: var(--font-display); font-weight: 700; font-size: 1.25rem; color: var(--c-ink); text-decoration: none; }
        .nav__brand svg { width: 28px; height: 28px; }
        .nav__links { display: flex; gap: 1.6rem; font-size: .9375rem; }
        .nav__links a { color: var(--c-soft); text-decoration: none; }
        .nav__cta { display: flex; gap: .5rem; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: .5rem; height: 2.75rem; padding: 0 1.25rem; border-radius: 999px; font-weight: 600; font-size: .9375rem; text-decoration: none; }
        .btn--primary { background: var(--c-blue); color: #fff; }
        .btn--primary:hover { background: #005bbb; }
        .btn--quiet { background: #fff; color: var(--c-ink); border: 1px solid var(--c-rule); }
        .btn--lg { height: 3.25rem; padding: 0 1.6rem; font-size: 1.0625rem; }

        .hero { padding-block: clamp(3rem, 7vw, 6rem) clamp(2rem, 4vw, 3rem); }
        .hero__in { display: grid; gap: clamp(2rem, 4vw, 4rem); align-items: center; }
        @media (min-width: 1000px) { .hero__in { grid-template-columns: minmax(0, .9fr) minmax(0, 1.1fr); } }
        .kicker { display: inline-flex; align-items: center; gap: .5rem; background: var(--c-tint); color: var(--c-blue); border-radius: 999px; padding: .35rem .9rem; font-size: .8125rem; font-weight: 600; margin-block-end: 1.5rem; }
        h1 { font-family: var(--font-display); font-weight: 600; font-size: clamp(2.375rem, 5vw, 4rem); line-height: 1.12; letter-spacing: -.02em; margin: 0 0 1.25rem; text-wrap: balance; }
        .lede { font-size: clamp(1.125rem, 1.6vw, 1.3125rem); color: var(--c-soft); max-width: 36ch; margin: 0 0 2rem; }
        .actions { display: flex; flex-wrap: wrap; gap: .75rem; margin-block-end: 1rem; }
        .fine { font-size: .875rem; color: var(--c-mute); }
        .fine span + span::before { content: '·'; margin-inline: .6rem; }

        .stage { position: relative; }
        .stage::before { content: ''; position: absolute; inset: -8% -12% -14% -10%; background: radial-gradient(60% 55% at 60% 40%, var(--c-tint), transparent 70%); z-index: -1; }
        .browser { border: 1px solid var(--c-rule); border-radius: 14px; overflow: hidden; background: #fff; box-shadow: 0 2px 4px rgb(14 27 44 / .05), 0 30px 60px -24px rgb(14 27 44 / .35); }
        .browser__bar { display: flex; align-items: center; gap: .5rem; height: 2.25rem; padding-inline: .9rem; background: #f7f9fc; border-block-end: 1px solid var(--c-rule); }
        .browser__bar i { width: .6rem; height: .6rem; border-radius: 50%; background: var(--c-rule); }
        .browser__bar span { margin-inline-start: auto; font-size: .75rem; color: var(--c-mute); direction: ltr; }
        .browser img { display: block; width: 100%; height: auto; }

        .proof { padding-block: 1rem clamp(3rem, 6vw, 5rem); }
        .proof__in { display: flex; flex-wrap: wrap; gap: .75rem 2rem; justify-content: center; font-size: .9375rem; color: var(--c-soft); border-block: 1px solid var(--c-rule); padding-block: 1.1rem; }
        .proof__in span { display: inline-flex; align-items: center; gap: .5rem; }
        .proof__in b { width: .45rem; height: .45rem; border-radius: 50%; background: var(--c-blue); }

        .tour { padding-block: clamp(2rem, 4vw, 3rem) clamp(4rem, 8vw, 7rem); }
        .tour__head { text-align: center; max-width: 44rem; margin: 0 auto clamp(2rem, 4vw, 3rem); }
        h2 { font-family: var(--font-display); font-weight: 600; font-size: clamp(1.875rem, 3.4vw, 2.75rem); line-height: 1.2; letter-spacing: -.015em; margin: 0 0 .75rem; }
        .tour__claim { color: var(--c-soft); margin: 0; }
        .bento { display: grid; gap: 1.25rem; }
        @media (min-width: 900px) { .bento { grid-template-columns: repeat(12, 1fr); } }
        .tile { grid-column: span var(--span, 6); border: 1px solid var(--c-rule); border-radius: 18px; background: #fff; overflow: hidden; display: grid; grid-template-rows: auto 1fr; box-shadow: 0 1px 2px rgb(14 27 44 / .04); }
        @media (max-width: 899px) { .tile { grid-column: auto; } }
        .tile__cap { padding: 1.25rem 1.4rem .5rem; }
        .tile__name { font-weight: 700; font-size: 1.125rem; margin: 0 0 .25rem; }
        .tile__body { color: var(--c-soft); font-size: .9375rem; margin: 0; }
        .tile__shot { padding: 1rem 1.4rem 0; background: linear-gradient(to bottom, #fff, var(--c-tint)); }
        .tile__shot img { display: block; width: 100%; height: auto; border: 1px solid var(--c-rule); border-block-end: 0; border-radius: 10px 10px 0 0; }

        @media (prefers-reduced-motion: no-preference) {
            .rise { animation: rise .5s cubic-bezier(.28,.11,.32,1) both; }
            @keyframes rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
        }
        @media (max-width: 899px) { .nav__links { display: none; } }
    </style>
</head>
<body>
<header class="nav grid">
    <div class="nav__in">
        <a class="nav__brand" href="/">{!! file_get_contents(resource_path('brand/mark-c.svg')) !!} همیار</a>
        <nav class="nav__links" aria-label="پیمایش اصلی"><a href="#tour">امکانات</a><a href="#imei">شناسنامهٔ IMEI</a><a href="#pricing">تعرفه‌ها</a><a href="#faq">سؤالات</a></nav>
        <div class="nav__cta"><a class="btn btn--quiet" href="/login">ورود</a><a class="btn btn--primary" href="/register">ثبت‌نام</a></div>
    </div>
</header>

<main class="grid">
    <section class="hero wide">
        <div class="hero__in">
            <div class="rise">
                <p class="kicker">نرم‌افزار ابری فروشگاه موبایل</p>
                <h1>همهٔ کارِ فروشگاه موبایل، در یک سامانه</h1>
                <p class="lede">فروش با IMEI، تعمیرات، اقساط و چک، پیامک و گزارش سود — هر گوشی با شناسهٔ خودش ثبت می‌شود و سود هر فروش همان لحظه معلوم است.</p>
                <div class="actions"><a class="btn btn--primary btn--lg" href="/register">رایگان شروع کنید</a><a class="btn btn--quiet btn--lg" href="#tour">دیدن نرم‌افزار</a></div>
                <p class="fine"><span>بدون کارت بانکی</span><span>در مرورگر</span><span>تقویم شمسی</span></p>
            </div>
            <div class="stage rise">
                <div class="browser">
                    <div class="browser__bar"><i></i><i></i><i></i><span>{{ config('app.domain') }}/dashboard</span></div>
                    <img src="{{ Vite::asset('resources/landing/shots/dashboard.webp') }}" alt="داشبورد همیار: فروش امروز، نمودار ۳۰ روز و کارهای معطل." width="1440" height="900" decoding="async" fetchpriority="high">
                </div>
            </div>
        </div>
    </section>

    <section class="proof">
        <div class="proof__in">
            <span><b aria-hidden="true"></b>ساخته‌شده برای بازار ایران</span>
            <span><b aria-hidden="true"></b>بدون نصب، روی هر مرورگر</span>
            <span><b aria-hidden="true"></b>در فروشگاه‌های پایلوت در حال استفاده</span>
            <span><b aria-hidden="true"></b>پشتیبانی از داخل نرم‌افزار</span>
        </div>
    </section>

    <section class="tour wide" id="tour">
        <div class="tour__head">
            <h2>همان صفحه‌هایی که هر روز باز می‌کنید</h2>
            <p class="tour__claim">تصویرها از خود نرم‌افزار گرفته شده‌اند، از یک فروشگاه آزمایشی با یک ماه فروش واقعی.</p>
        </div>
        @php
            $tiles = [
                ['pos', 'صندوق فروش', 'بارکد یا IMEI را اسکن کنید؛ دستگاه با همان شناسه روی فاکتور می‌نشیند.', 7],
                ['repairs', 'تختهٔ تعمیرات', 'هر قبض پذیرش یک کارت است و بین وضعیت‌های کارگاه جابه‌جا می‌شود.', 5],
                ['installments', 'میز وصول', 'امروز چه کسی باید بیاید و چه کسی عقب افتاده است.', 5],
                ['profit', 'گزارش سود', 'بهای تمام‌شده در لحظهٔ فروش ثبت می‌شود؛ سود واقعی است.', 7],
                ['sms', 'پیامک', '«دستگاه آماده است» و یادآوری قسط، از روی رویدادهای سیستم.', 5],
                ['imei', 'پروندهٔ دستگاه', 'خرید، تعمیر، حواله و فروش، همه زیر یک شناسه.', 7],
            ];
        @endphp
        <div class="bento">
            @foreach ($tiles as [$file, $name, $body, $span])
                <figure class="tile rise" style="--span: {{ $span }}">
                    <figcaption class="tile__cap"><p class="tile__name">{{ $name }}</p><p class="tile__body">{{ $body }}</p></figcaption>
                    <div class="tile__shot"><img src="{{ Vite::asset("resources/landing/shots/{$file}.webp") }}" alt="" width="1440" height="900" loading="lazy" decoding="async"></div>
                </figure>
            @endforeach
        </div>
    </section>
</main>
</body>
</html>
