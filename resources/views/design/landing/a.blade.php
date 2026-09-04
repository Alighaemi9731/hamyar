{{--
    Gate 16.2 · direction A — «جعبه و برچسب» (the box and its label). ASSIGNED by the roll.

    THESIS: the page is the label on the box, and the box is the product. A phone shop
    handles one object all day — the retail box with its spec label, barcode and IMEI —
    and the software's central claim (one handset, one row, one history) is exactly what
    that label promises and never keeps. So the landing is written in the label's grammar:
    box-board white, hairline-ruled panels with square corners, the model name as the
    biggest type, every figure in tabular digits, one signal colour where a label carries
    a regulatory mark. It refuses the centred hero-plus-cards arrangement.

    OWN-WORLD: white board, ink and one blue, 1px rules, 2px corners, panels that touch
    each other on a shared lattice (never floating cards), Persian display face heavy at
    the top and quiet small labels everywhere else, digits always tabular.

    STORY: a visitor sees the box of a real phone with its history filled in, understands
    that the software keeps that label alive after the box is thrown away, and starts.

    FIRST VIEWPORT: right column (reading start) — the label's header: a small field
    «نرم‌افزار ابری فروشگاه موبایل», the H1, the lede, the two actions. Left column, on the
    wide track and overhanging the fold — the label of a real device: model, barcode, IMEI,
    then the history rows and the profit line. The primary action sits under the H1.

    FORM: candidate 6 of the grounded list («جعبه و برچسب»), seed key fd28c358.

    Disposable: real markup for the gate, inline CSS on the brand layer; the chosen
    direction is rebuilt as proper section files in 16.3. Never registered in production.
--}}
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>جهت A — جعبه و برچسب — سامانه همیار</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @vite(['resources/landing/gate.css'])
    <style>
        :root {
            --a-ink: var(--color-navy);
            --a-soft: var(--color-navy-soft);
            --a-mute: var(--color-navy-mute);
            --a-rule: var(--color-hair);
            --a-blue: var(--color-accent);
            --a-board: #ffffff;
            --a-board-alt: #f4f6f9;
        }
        body { background: var(--a-board); color: var(--a-ink); line-height: 1.7; font-size: 1.0625rem; }
        .grid {
            display: grid;
            grid-template-columns:
                [full-start] minmax(1.25rem, 1fr)
                [wide-start] minmax(0, 6rem)
                [content-start] min(68rem, 100% - 2.5rem) [content-end]
                minmax(0, 6rem) [wide-end]
                minmax(1.25rem, 1fr) [full-end];
        }
        .grid > * { grid-column: content; }
        .grid > .wide { grid-column: wide; }
        .grid > .full { grid-column: full; }

        /* ---- the label grammar ---- */
        .lbl { border: 1px solid var(--a-rule); border-radius: 2px; background: var(--a-board); }
        .lbl + .lbl { border-block-start: 0; }
        .field { display: flex; justify-content: space-between; gap: 1rem; padding: .55rem .9rem; border-block-start: 1px solid var(--a-rule); font-size: .9375rem; }
        .field:first-child { border-block-start: 0; }
        .field__k { color: var(--a-mute); font-size: .8125rem; }
        .field__v { font-variant-numeric: tabular-nums; }
        .tag { display: inline-flex; align-items: center; gap: .4rem; border: 1px solid var(--a-rule); border-radius: 2px; padding: .15rem .55rem; font-size: .75rem; color: var(--a-soft); }
        .tag--blue { border-color: var(--a-blue); color: var(--a-blue); }
        .mono { font-variant-numeric: tabular-nums; letter-spacing: .04em; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: .5rem; min-height: 3rem; padding: 0 1.4rem; border-radius: 2px; font-weight: 600; font-size: 1rem; text-decoration: none; border: 1px solid transparent; }
        .btn--primary { background: var(--a-ink); color: #fff; }
        .btn--primary:hover { background: var(--a-blue); }
        .btn--quiet { border-color: var(--a-rule); color: var(--a-ink); background: #fff; }

        /* ---- nav as the label strip ---- */
        .nav { position: sticky; inset-block-start: 0; z-index: 20; background: var(--a-board); border-block-end: 1px solid var(--a-rule); }
        .nav__in { display: flex; align-items: center; justify-content: space-between; gap: 1.5rem; padding-block: .75rem; }
        .nav__brand { display: inline-flex; align-items: center; gap: .6rem; font-family: var(--font-display); font-weight: 700; font-size: 1.25rem; color: var(--a-ink); text-decoration: none; }
        .nav__brand svg { width: 28px; height: 28px; }
        .nav__links { display: flex; gap: 1.5rem; font-size: .9375rem; }
        .nav__links a { color: var(--a-soft); text-decoration: none; }
        .nav__cta { display: flex; gap: .5rem; }
        .nav__cta .btn { min-height: 2.5rem; padding: 0 1rem; font-size: .9375rem; }

        /* ---- hero ---- */
        .hero { padding-block: clamp(2.5rem, 6vw, 5rem) 0; }
        .hero__in { display: grid; gap: clamp(2rem, 4vw, 4rem); align-items: start; }
        @media (min-width: 900px) { .hero__in { grid-template-columns: minmax(0, 1.05fr) minmax(24rem, .95fr); } }
        .kicker { display: inline-flex; gap: .5rem; align-items: center; border: 1px solid var(--a-rule); border-radius: 2px; padding: .3rem .7rem; font-size: .8125rem; color: var(--a-soft); margin-block-end: 1.5rem; }
        .kicker b { width: .5rem; height: .5rem; border-radius: 50%; background: var(--a-blue); }
        h1 { font-family: var(--font-display); font-weight: 600; font-size: clamp(2.375rem, 5.2vw, 4.25rem); line-height: 1.12; letter-spacing: -.02em; margin: 0 0 1.25rem; text-wrap: balance; }
        .lede { font-size: clamp(1.125rem, 1.6vw, 1.3125rem); color: var(--a-soft); max-width: 34ch; margin: 0 0 2rem; }
        .actions { display: flex; flex-wrap: wrap; gap: .75rem; margin-block-end: 1rem; }
        .fine { font-size: .875rem; color: var(--a-mute); }
        .fine span + span::before { content: '·'; margin-inline: .6rem; }

        /* the box label */
        .box { position: relative; }
        .box__label { border: 1px solid var(--a-ink); border-radius: 2px; background: #fff; box-shadow: 0 30px 60px -30px rgb(14 27 44 / .25); }
        .box__head { padding: 1.1rem 1.2rem .9rem; border-block-end: 1px solid var(--a-ink); display: grid; grid-template-columns: 1fr auto; gap: 1rem; align-items: start; }
        .box__model { font-family: var(--font-display); font-weight: 700; font-size: 1.75rem; line-height: 1.15; margin: 0; }
        .box__sub { color: var(--a-mute); font-size: .875rem; margin-top: .25rem; }
        .box__mark { width: 40px; height: 40px; color: var(--a-ink); }
        .box__code { padding: 1rem 1.2rem; border-block-end: 1px solid var(--a-rule); display: grid; gap: .6rem; }
        .barcode { display: block; width: 100%; height: 56px; }
        .imei { display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; }
        .imei__n { font-size: 1.5rem; font-weight: 600; }
        .box__rows { }
        .row { display: grid; grid-template-columns: 6.5rem 1fr auto; gap: 1rem; padding: .8rem 1.2rem; border-block-start: 1px solid var(--a-rule); align-items: baseline; }
        .row:first-child { border-block-start: 0; }
        .row__k { font-size: .8125rem; color: var(--a-mute); }
        .row__v { font-size: .9375rem; }
        .row__v small { display: block; color: var(--a-mute); font-size: .8125rem; }
        .row__n { font-variant-numeric: tabular-nums; font-size: .9375rem; white-space: nowrap; }
        .row--sum { background: var(--a-board-alt); border-block-start: 1px solid var(--a-ink); }
        .row--sum .row__k { color: var(--a-ink); font-weight: 600; }
        .row--sum .row__n { font-weight: 700; color: var(--a-blue); font-size: 1.125rem; }
        .box__foot { display: flex; justify-content: space-between; gap: 1rem; padding: .7rem 1.2rem; border-block-start: 1px solid var(--a-rule); font-size: .75rem; color: var(--a-mute); }
        .box__foot .marks { display: flex; gap: .5rem; }
        .box__foot .marks span { border: 1px solid var(--a-rule); border-radius: 2px; padding: .05rem .4rem; }
        .box__note { margin: .9rem .2rem 0; font-size: .875rem; color: var(--a-mute); }

        /* ---- proof strip ---- */
        .proof { margin-block: clamp(3rem, 6vw, 5rem) 0; }
        .proof__in { display: grid; border: 1px solid var(--a-rule); border-radius: 2px; }
        @media (min-width: 900px) { .proof__in { grid-template-columns: repeat(4, 1fr); } }
        .proof__cell { padding: 1rem 1.2rem; border-block-start: 1px solid var(--a-rule); font-size: .9375rem; }
        @media (min-width: 900px) { .proof__cell { border-block-start: 0; border-inline-start: 1px solid var(--a-rule); } .proof__cell:first-child { border-inline-start: 0; } }
        .proof__cell b { display: block; font-size: .75rem; color: var(--a-mute); font-weight: 500; margin-block-end: .2rem; }

        /* ---- the spec-sheet tour ---- */
        .tour { padding-block: clamp(4rem, 8vw, 7rem); }
        .tour__head { display: grid; gap: .75rem; margin-block-end: 2rem; }
        @media (min-width: 900px) { .tour__head { grid-template-columns: 1fr 1fr; align-items: end; } }
        h2 { font-family: var(--font-display); font-weight: 600; font-size: clamp(1.75rem, 3vw, 2.5rem); line-height: 1.2; letter-spacing: -.015em; margin: 0; }
        .tour__claim { color: var(--a-soft); font-size: 1.0625rem; }
        .sheet { display: grid; border: 1px solid var(--a-rule); border-radius: 2px; overflow: hidden; }
        @media (min-width: 900px) { .sheet { grid-template-columns: repeat(12, 1fr); } }
        .cell { position: relative; border-block-start: 1px solid var(--a-rule); background: #fff; display: grid; grid-template-rows: auto 1fr; }
        @media (min-width: 900px) {
            .cell { border-block-start: 0; border-inline-start: 1px solid var(--a-rule); border-block-end: 1px solid var(--a-rule); grid-column: span var(--span, 6); }
            .cell:nth-child(2n+1) { border-inline-start: 0; }
            .cell:nth-child(n+5) { border-block-end: 0; }
        }
        .cell__cap { display: grid; grid-template-columns: 1fr auto; gap: 1rem; padding: .9rem 1.1rem; border-block-end: 1px solid var(--a-rule); }
        .cell__name { font-weight: 700; font-size: 1.0625rem; margin: 0; }
        .cell__body { font-size: .9375rem; color: var(--a-soft); margin: .2rem 0 0; }
        .cell__no { font-size: .75rem; color: var(--a-mute); font-variant-numeric: tabular-nums; }
        .cell__shot { padding: 1.1rem; background: var(--a-board-alt); }
        .cell__shot img { display: block; width: 100%; height: auto; border: 1px solid var(--a-rule); border-radius: 2px; }

        @media (prefers-reduced-motion: no-preference) {
            .rise { animation: rise .5s cubic-bezier(.28,.11,.32,1) both; }
            @keyframes rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
        }
    </style>
</head>
<body>
<header class="nav grid">
    <div class="nav__in">
        <a class="nav__brand" href="/">{!! file_get_contents(resource_path('brand/mark-c.svg')) !!} همیار</a>
        <nav class="nav__links" aria-label="پیمایش اصلی">
            <a href="#tour">امکانات</a><a href="#imei">شناسنامهٔ IMEI</a><a href="#pricing">تعرفه‌ها</a><a href="#faq">سؤالات</a>
        </nav>
        <div class="nav__cta"><a class="btn btn--quiet" href="/login">ورود</a><a class="btn btn--primary" href="/register">ثبت‌نام</a></div>
    </div>
</header>

<main class="grid">
    <section class="hero wide">
        <div class="hero__in">
            <div class="rise">
                <p class="kicker"><b aria-hidden="true"></b>نرم‌افزار ابری فروشگاه موبایل</p>
                <h1>همهٔ کارِ فروشگاه موبایل، در یک سامانه</h1>
                <p class="lede">فروش با IMEI، تعمیرات، اقساط و چک، پیامک و گزارش سود — هر گوشی با شناسهٔ خودش ثبت می‌شود و سود هر فروش همان لحظه معلوم است.</p>
                <div class="actions">
                    <a class="btn btn--primary" href="/register">رایگان شروع کنید</a>
                    <a class="btn btn--quiet" href="#tour">دیدن نرم‌افزار</a>
                </div>
                <p class="fine"><span>بدون کارت بانکی</span><span>در مرورگر</span><span>تقویم شمسی</span></p>
            </div>

            <figure class="box rise" id="imei">
                <div class="box__label">
                    <div class="box__head">
                        <div>
                            <p class="box__model">آیفون ۱۵ — ۱۲۸ گیگ</p>
                            <p class="box__sub">اپل · مشکی · نو</p>
                        </div>
                        <span class="box__mark">{!! file_get_contents(resource_path('brand/mark-c.svg')) !!}</span>
                    </div>
                    <div class="box__code">
                        <svg class="barcode" viewBox="0 0 220 56" preserveAspectRatio="none" aria-hidden="true">
                            @php $x = 0; $w = [2,1,3,1,2,2,1,3,1,1,2,3,1,2,1,1,3,2,1,2,2,1,1,3,2,1,2,1,3,1,2,2,1,1,3,1,2,1,2,3,1,2,1,1,2,3,1,2,2,1,3,1,1,2,1,3,2,1,2,1,1,3,2,1]; @endphp
                            @foreach ($w as $i => $bar)
                                @if ($i % 2 === 0)<rect x="{{ $x }}" y="0" width="{{ $bar * 1.6 }}" height="56" fill="#0e1b2c"/>@endif
                                @php $x += $bar * 1.6; @endphp
                            @endforeach
                        </svg>
                        <div class="imei">
                            <span class="field__k">IMEI</span>
                            <span class="imei__n mono" dir="ltr">356938035643809</span>
                        </div>
                    </div>
                    <div class="box__rows">
                        <div class="row"><span class="row__k">خرید</span><span class="row__v">پخش موبایل ایرانیان<small>PUR-00924 · ۱۴۰۵/۰۲/۱۱</small></span><span class="row__n">۴۱٬۲۰۰٬۰۰۰</span></div>
                        <div class="row"><span class="row__k">فروش</span><span class="row__v">سمیرا احمدی‌فر<small>INV-001873 · ۱۴۰۵/۰۳/۰۴</small></span><span class="row__n">۴۴٬۹۰۰٬۰۰۰</span></div>
                        <div class="row"><span class="row__k">تعمیر</span><span class="row__v">تعویض گلس<small>REP-000184 · ۱۴۰۵/۰۵/۲۹</small></span><span class="row__n">۴۲۰٬۰۰۰</span></div>
                        <div class="row"><span class="row__k">همتا</span><span class="row__v">ثبت‌شده<small>انتقال مالکیت ۱۴۰۵/۰۳/۰۴</small></span><span class="row__n"><span class="tag tag--blue">تأیید</span></span></div>
                        <div class="row row--sum"><span class="row__k">سود این دستگاه</span><span class="row__v"></span><span class="row__n">۳٬۷۰۰٬۰۰۰ تومان</span></div>
                    </div>
                    <div class="box__foot">
                        <span class="marks"><span>RTL</span><span>شمسی</span><span>IRR</span></span>
                        <span class="mono" dir="ltr">HAMYAR · 0001873</span>
                    </div>
                </div>
                <figcaption class="box__note">پروندهٔ یک دستگاه واقعی از فروشگاه آزمایشی — همان چیزی که برچسب جعبه وعده می‌دهد و بعد از دور انداختن جعبه، همیار نگه می‌دارد.</figcaption>
            </figure>
        </div>
    </section>

    <section class="proof">
        <div class="proof__in">
            <div class="proof__cell"><b>ساخته‌شده برای</b>بازار ایران — فارسی، تقویم شمسی، تومان و ریال</div>
            <div class="proof__cell"><b>نصب</b>ندارد؛ روی هر مرورگر، روی هر گوشی</div>
            <div class="proof__cell"><b>در حال استفاده</b>در فروشگاه‌های پایلوت (نام‌ها با اجازه)</div>
            <div class="proof__cell"><b>پشتیبانی</b>از داخل نرم‌افزار و کانال تماس</div>
        </div>
    </section>

    <section class="tour wide" id="tour">
        <div class="tour__head">
            <h2>همان صفحه‌هایی که هر روز باز می‌کنید</h2>
            <p class="tour__claim">تصویرها از خود نرم‌افزار گرفته شده‌اند، از یک فروشگاه آزمایشی با یک ماه فروش واقعی — نه ماکت.</p>
        </div>
        @php
            $cells = [
                ['pos', 'صندوق فروش', 'بارکد یا IMEI را اسکن کنید؛ دستگاه با همان شناسه روی فاکتور می‌نشیند. معاوضه، تخفیف و چند روش پرداخت، همین‌جا.', 7],
                ['repairs', 'تختهٔ تعمیرات', 'هر قبض پذیرش یک کارت است و بین وضعیت‌های واقعی کارگاه جابه‌جا می‌شود.', 5],
                ['installments', 'میز وصول', 'امروز چه کسی باید بیاید، چه کسی عقب افتاده و چقدر هنوز وصول نشده.', 5],
                ['profit', 'گزارش سود', 'بهای تمام‌شده در لحظهٔ فروش ثبت می‌شود؛ سود آخر ماه سود واقعی است.', 7],
                ['sms', 'پیامک', '«دستگاه آماده است» و یادآوری قسط از روی رویدادهای خود سیستم فرستاده می‌شود.', 5],
                ['imei', 'پروندهٔ دستگاه', 'خرید، تعمیر، حواله و فروش، همه زیر یک شناسه.', 7],
            ];
        @endphp
        <div class="sheet">
            @foreach ($cells as $i => [$file, $name, $body, $span])
                <figure class="cell rise" style="--span: {{ $span }}">
                    <figcaption class="cell__cap">
                        <div><p class="cell__name">{{ $name }}</p><p class="cell__body">{{ $body }}</p></div>
                        <span class="cell__no">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>
                    </figcaption>
                    <div class="cell__shot"><img src="{{ Vite::asset("resources/landing/shots/{$file}.webp") }}" alt="" width="1440" height="900" loading="lazy" decoding="async"></div>
                </figure>
            @endforeach
        </div>
    </section>
</main>
</body>
</html>
