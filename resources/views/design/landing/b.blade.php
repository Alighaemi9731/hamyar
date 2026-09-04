{{--
    Gate 16.2 · direction B — «دفتر» (the ledger). IMPECCABLE'S PICK: my top-ranked grounded
    world, shown because the roll assigned another.

    THESIS: the page is the shop's ruled ledger, and the software is the ledger that never
    runs out of pages. Every shop keeps one; every claim the product makes is a row in it.
    The page is one ruled sheet: rules run the full width, the reading edge carries the red
    margin line and the index tabs, figures align on the units digit, and the sections are
    the ledger's own headings. It refuses the hero-plus-cards arrangement and the centred
    column: nothing floats, everything sits on a rule.

    OWN-WORLD: cream-free — the sheet is white, the rules are a cool hairline, the margin
    is the one red, the ink is navy, the accent stays the product's blue for links and the
    action; display type heavy and tight, figures tabular, labels small and quiet.

    STORY: the visitor recognises the object on the counter, sees today's real rows fill
    it, and understands the software is the same ledger with the arithmetic done.

    FIRST VIEWPORT: the sheet with the H1 written across its first rules, the lede under
    it, the actions on the next rule, and — on the same sheet, on the wide track — today's
    page of the ledger: the day's real invoices, one per rule, summed at the foot. The
    primary action is the first thing after the lede.

    FORM: candidate 1 of the grounded list («دفتر»), seed key fd28c358.

    Honest risk: familiar-editorial — the place a careful run most often lands, and the
    nearest relative of the last landing's "rows" idea.
--}}
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>جهت B — دفتر — سامانه همیار</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @vite(['resources/landing/gate.css'])
    <style>
        :root {
            --b-ink: var(--color-navy);
            --b-soft: var(--color-navy-soft);
            --b-mute: var(--color-navy-mute);
            --b-rule: #d9e1ea;
            --b-margin: #c2412d;
            --b-blue: var(--color-accent);
            --b-row: 3.25rem;
        }
        html { background: #fff; }
        html, body { overflow-x: clip; }
        @media (max-width: 899px) { .nav__links { display: none; } }
        body { color: var(--b-ink); font-size: 1.0625rem; line-height: var(--b-row); }
        /* The sheet: rules across the whole viewport, aligned to a row height everything on the
           page sits on. This is the thesis, not decoration — a ledger is its rules. */
        .sheet {
            background-image: linear-gradient(to bottom, var(--b-rule) 1px, transparent 1px);
            background-size: 100% var(--b-row);
            background-position: 0 calc(var(--b-row) - 1px);
            min-height: 100vh;
        }
        .grid {
            display: grid;
            grid-template-columns:
                [full-start] minmax(1.25rem, 1fr)
                [wide-start] minmax(0, 5rem)
                [content-start] min(70rem, 100% - 2.5rem) [content-end]
                minmax(0, 5rem) [wide-end]
                minmax(1.25rem, 1fr) [full-end];
            position: relative;
        }
        .grid > * { grid-column: content; }
        .grid > .wide { grid-column: wide; }
        /* The red margin, on the reading edge, full height. */
        .sheet::before { content: ''; position: fixed; inset-block: 0; inset-inline-start: clamp(1.25rem, 6vw, 5.5rem); width: 2px; background: var(--b-margin); opacity: .85; z-index: 1; pointer-events: none; }

        .nav { display: flex; align-items: center; justify-content: space-between; height: var(--b-row); border-block-end: 1px solid var(--b-ink); }
        .nav__brand { display: inline-flex; align-items: center; gap: .6rem; font-family: var(--font-display); font-weight: 700; font-size: 1.25rem; color: var(--b-ink); text-decoration: none; }
        .nav__brand svg { width: 26px; height: 26px; }
        .nav__links { display: flex; gap: 1.5rem; font-size: .9375rem; }
        .nav__links a { color: var(--b-soft); text-decoration: none; }
        .nav__cta { display: flex; gap: .5rem; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: .5rem; height: 2.5rem; padding: 0 1.1rem; font-weight: 600; font-size: .9375rem; text-decoration: none; border-radius: 4px; line-height: 1; }
        .btn--primary { background: var(--b-ink); color: #fff; }
        .btn--primary:hover { background: var(--b-blue); }
        .btn--quiet { color: var(--b-ink); border: 1px solid var(--b-ink); background: transparent; }
        .btn--lg { height: 3rem; padding: 0 1.4rem; font-size: 1rem; }

        /* Index tabs on the reading edge — the ledger's section nav. */
        .tabs { position: fixed; inset-inline-end: 0; inset-block-start: 40%; display: grid; gap: 2px; z-index: 2; }
        .tabs a { writing-mode: vertical-rl; transform: rotate(180deg); padding: .8rem .35rem; background: var(--b-ink); color: #fff; font-size: .75rem; text-decoration: none; letter-spacing: .02em; }
        .tabs a:nth-child(2) { background: var(--b-blue); }
        @media (max-width: 899px) { .tabs { display: none; } }

        /* ---- hero on the rules ---- */
        .hero { padding-block-start: calc(var(--b-row) * 2); }
        .eyebrow { font-size: .8125rem; color: var(--b-mute); height: var(--b-row); }
        h1 { font-family: var(--font-display); font-weight: 700; font-size: clamp(2.5rem, 6vw, 5.25rem); line-height: calc(var(--b-row) * 2); letter-spacing: -.025em; margin: 0; text-wrap: balance; }
        @media (max-width: 899px) { h1 { line-height: var(--b-row); font-size: 2.25rem; } }
        .lede { max-width: 42ch; color: var(--b-soft); font-size: 1.25rem; margin: 0; line-height: var(--b-row); }
        .actions { display: flex; align-items: center; gap: .75rem; height: calc(var(--b-row) * 1.5); }
        .fine { font-size: .875rem; color: var(--b-mute); }
        .fine span + span::before { content: '·'; margin-inline: .6rem; }

        /* ---- today's page of the ledger ---- */
        .today { padding-block: calc(var(--b-row) * 1.5) calc(var(--b-row) * 2); }
        .today__head { display: flex; justify-content: space-between; align-items: baseline; border-block-end: 1px solid var(--b-ink); font-family: var(--font-display); font-weight: 600; font-size: 1.125rem; }
        .today__head small { font-family: var(--font-sans); font-weight: 400; color: var(--b-mute); font-size: .875rem; }
        .ledger { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
        .ledger th { font-weight: 500; font-size: .75rem; color: var(--b-mute); text-align: start; height: var(--b-row); }
        .ledger td { height: var(--b-row); border-block-end: 1px solid transparent; font-size: .9375rem; white-space: nowrap; }
        .ledger .n { text-align: end; }
        .ledger .id { color: var(--b-mute); font-size: .8125rem; }
        .ledger .muted { color: var(--b-mute); }
        .ledger tfoot td { border-block-start: 1px solid var(--b-ink); font-weight: 700; }
        .ledger tfoot .blue { color: var(--b-blue); }
        .stamp { display: inline-block; border: 1.5px solid var(--b-margin); color: var(--b-margin); border-radius: 3px; padding: 0 .4rem; font-size: .75rem; line-height: 1.5rem; transform: rotate(-3deg); }

        /* ---- the passport as a ledger page ---- */
        .passport { padding-block-end: calc(var(--b-row) * 2); }
        h2 { font-family: var(--font-display); font-weight: 700; font-size: clamp(1.75rem, 3vw, 2.5rem); line-height: var(--b-row); letter-spacing: -.015em; margin: 0; }
        .passport__grid { display: grid; gap: 0 3rem; }
        @media (min-width: 900px) { .passport__grid { grid-template-columns: minmax(0, 1fr) minmax(0, 1.4fr); } }
        .passport__lede { color: var(--b-soft); margin: 0; max-width: 38ch; }
        .imei-line { font-size: 1.75rem; font-weight: 700; letter-spacing: .04em; height: calc(var(--b-row) * 1); }
        .frame { border: 1px solid var(--b-rule); border-radius: 4px; overflow: hidden; margin-block-start: calc(var(--b-row) * .5); box-shadow: 0 24px 48px -28px rgb(14 27 44 / .3); }
        .frame img { display: block; width: 100%; height: auto; }

        @media (prefers-reduced-motion: no-preference) {
            .rise { animation: rise .5s cubic-bezier(.28,.11,.32,1) both; }
            @keyframes rise { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
        }
    </style>
</head>
<body>
<div class="sheet">
    <nav class="tabs" aria-label="بخش‌ها"><a href="#today">امروز</a><a href="#passport">شناسنامهٔ IMEI</a><a href="#pricing">تعرفه‌ها</a><a href="#faq">سؤالات</a></nav>

    <div class="grid">
        <header class="nav">
            <a class="nav__brand" href="/">{!! file_get_contents(resource_path('brand/mark-c.svg')) !!} همیار</a>
            <div class="nav__links"><a href="#today">امکانات</a><a href="#passport">شناسنامهٔ IMEI</a><a href="#pricing">تعرفه‌ها</a><a href="#faq">سؤالات</a></div>
            <div class="nav__cta"><a class="btn btn--quiet" href="/login">ورود</a><a class="btn btn--primary" href="/register">ثبت‌نام</a></div>
        </header>
    </div>

    <main class="grid">
        <section class="hero rise">
            <p class="eyebrow">دفتر فروشگاه · نرم‌افزار ابری فروشگاه موبایل</p>
            <h1>همهٔ کارِ فروشگاه موبایل، در یک سامانه</h1>
            <p class="lede">فروش با IMEI، تعمیرات، اقساط و چک، پیامک و گزارش سود — هر گوشی یک سطر با شناسهٔ خودش است و سود هر فروش همان لحظه معلوم.</p>
            <div class="actions">
                <a class="btn btn--primary btn--lg" href="/register">رایگان شروع کنید</a>
                <a class="btn btn--quiet btn--lg" href="#today">دیدن نرم‌افزار</a>
            </div>
            <p class="fine"><span>بدون کارت بانکی</span><span>در مرورگر</span><span>تقویم شمسی</span></p>
        </section>

        <section class="today wide rise" id="today" aria-labelledby="today-title">
            <div class="today__head"><span id="today-title">صفحهٔ امروز — ۱۴۰۵/۰۶/۱۴</span><small>از فروشگاه آزمایشی، با یک ماه فروش واقعی</small></div>
            <table class="ledger">
                <thead><tr><th>شماره</th><th>مشتری</th><th>کالا</th><th class="n">مبلغ</th><th class="n">پرداخت</th><th class="n">مانده</th><th>وضعیت</th></tr></thead>
                <tbody>
                    <tr><td class="id" dir="ltr">INV-000061</td><td>سارا جعفری</td><td>آیفون ۱۵ — ۱۲۸ گیگ · گلس</td><td class="n">81,785,000</td><td class="n">81,785,000</td><td class="n muted">—</td><td>نهایی</td></tr>
                    <tr><td class="id" dir="ltr">INV-000060</td><td>مهدی عباسی</td><td>هندزفری بلوتوث · کابل تایپ‌سی</td><td class="n">3,157,000</td><td class="n">3,157,000</td><td class="n muted">—</td><td>نهایی</td></tr>
                    <tr><td class="id" dir="ltr">INV-000059</td><td>الهام شریفی</td><td>پاوربانک ۲۰ هزار · قاب</td><td class="n">2,486,000</td><td class="n">1,490,000</td><td class="n">996,000</td><td>باقی‌مانده</td></tr>
                    <tr><td class="id" dir="ltr">REP-000012</td><td>الهام شریفی</td><td>گلکسی A55 — تعویض گلس و تاچ</td><td class="n">6,800,000</td><td class="n">6,800,000</td><td class="n muted">—</td><td><span class="stamp">تحویل شد</span></td></tr>
                    <tr><td class="id" dir="ltr">CHQ-338201</td><td>حسین موسوی‌نژاد</td><td>چک بانک ملت — سررسید امروز</td><td class="n">28,000,000</td><td class="n muted">—</td><td class="n">28,000,000</td><td>در انتظار وصول</td></tr>
                </tbody>
                <tfoot><tr><td colspan="3">جمع امروز</td><td class="n">122,228,000</td><td class="n">93,232,000</td><td class="n blue">28,996,000</td><td>سود امروز ۸,۶۴۸,۷۵۰</td></tr></tfoot>
            </table>
        </section>

        <section class="passport rise" id="passport" aria-labelledby="passport-title">
            <div class="passport__grid">
                <div>
                    <h2 id="passport-title">این شناسه را وارد کنید؛ بقیه‌اش پیداست.</h2>
                    <p class="passport__lede">در همیار گوشی «تعداد» نیست. هر دستگاه یک سطر با شناسهٔ خودش است و هر خرید، تعمیر، حواله و فروش زیر همان شناسه می‌ماند — دو سال بعد هم.</p>
                    <p class="imei-line" dir="ltr">356938035643809</p>
                    <p class="fine">همتا API عمومی ندارد؛ ثبت نهایی را خودتان انجام می‌دهید. همیار وضعیت هر دستگاه را نگه می‌دارد و یادآوری می‌کند.</p>
                </div>
                <div class="frame"><img src="{{ Vite::asset('resources/landing/shots/imei.webp') }}" alt="پروندهٔ یک دستگاه در همیار: شناسهٔ IMEI و سابقهٔ خرید، تعمیر و فروش." width="1440" height="900" loading="lazy" decoding="async"></div>
            </div>
        </section>
    </main>
</div>
</body>
</html>
