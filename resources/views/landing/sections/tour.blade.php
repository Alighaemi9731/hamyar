{{--
    ================================================================= tour ===

    Section 5 — «گشت‌وگذار در محصول». Six real screens of the running product.

    ## Why a mosaic and not five alternating rows

    The page this replaces put one screenshot beside one paragraph, five times, sides
    swapping each row. That zebra is on a thousand generated landing pages, and it also
    lies about how somebody looks at software: nobody reads a feature essay, they scan
    screens and stop at the one that looks like their own counter.

    So the six shots are all on the page at once, at deliberately unequal sizes — 7/5,
    5/7, 4/8 across a twelve-column grid. The rhythm is the composition; there is no
    heading above each tile and no icon beside it. Where a shot earns more room (the POS
    screen, the profit report) it gets more room, and scale does the ranking that an
    eyebrow label would otherwise have to announce.

    ## The tiles are not interactive, and do not pretend to be

    No hover lift, no cursor change, no "click to enlarge" that enlarges nothing. An
    affordance that leads nowhere is a small lie, and this section is already asking to
    be believed about screenshots.

    ## Cost

    Zero JavaScript of its own. Six `loading="lazy"` images below the fold, the shared
    `.frame` chrome, and the shared `.rise` entry fade that landing.js already drives.
--}}
@php
    use App\Support\Digits;

    /**
     * [file, name, body, span, alt].
     *
     * `span` is the desktop column count out of twelve; it is read by the stylesheet as
     * `data-span` and ignored below 960px, where every tile is full width.
     *
     * The last tile is the IMEI record on purpose: the visitor has just built one by
     * hand in the section above, and this is the same thing inside the software. A tour
     * that ends by pointing back at the argument is a page, not a list.
     *
     * @var array<int, array{0:string,1:string,2:string,3:int,4:string}>
     */
    $screens = [
        [
            'pos',
            'صندوق فروش',
            'بارکد یا IMEI را می‌زنید و دستگاه با همان شناسه روی فاکتور می‌نشیند. معاوضه، تخفیف و چند روش پرداخت، همه روی همین یک صفحه.',
            7,
            'صفحهٔ صندوق فروش موبایل‌یار: سبد فاکتور با یک گوشی سریال‌دار، جعبهٔ اسکن بارکد و روش‌های پرداخت.',
        ],
        [
            'repairs',
            'بورد تعمیرات',
            'هر قبض پذیرش یک کارت است و بین وضعیت‌های واقعی کارگاه جابه‌جا می‌شود: پذیرش، در دست تعمیر، آمادهٔ تحویل، رسوبی.',
            5,
            'بورد تعمیرات موبایل‌یار: کارت‌های قبض پذیرش در ستون‌های پذیرش، در دست تعمیر و آمادهٔ تحویل.',
        ],
        [
            'installments',
            'جدول اقساط',
            'چه کسی امروز باید بیاید، چه کسی عقب افتاده و چقدر هنوز وصول نشده — به‌جای دفترچه‌ای که فقط خودتان می‌توانید بخوانید.',
            5,
            'جدول اقساط موبایل‌یار: سررسیدها، مبلغ هر قسط و وضعیت وصول برای چند مشتری.',
        ],
        [
            'profit',
            'گزارش سود',
            'بهای تمام‌شده در همان لحظهٔ فروش ثبت می‌شود، پس سود آخر ماه سود واقعی است، نه تفاضل قیمت امروز با قیمت خرید.',
            7,
            'گزارش سود موبایل‌یار: فروش، بهای تمام‌شده و سود به تفکیک کالا در یک بازهٔ شمسی.',
        ],
        [
            'sms',
            'پیامک',
            'پیامکِ «دستگاه آماده است» و یادآوری قسط از روی رویدادهای خود سیستم می‌رود، نه از روی فهرستی که باید یادتان بماند.',
            4,
            'صفحهٔ پیامک موبایل‌یار: قالب‌های آماده و سیاههٔ پیامک‌های ارسال‌شده به مشتریان.',
        ],
        [
            'imei',
            'پروندهٔ دستگاه',
            'همان پرونده‌ای که بالاتر ورق زدید، این بار داخل نرم‌افزار: خرید، تعمیر، حواله و فروش، همه زیر یک شناسه.',
            8,
            'پروندهٔ یک دستگاه در موبایل‌یار: شناسهٔ IMEI و سابقهٔ خرید، تعمیر و فروش همان گوشی.',
        ],
    ];
@endphp

<section class="sec sec--alt tour" id="tour" aria-labelledby="tour-title">
    <div class="shell">
        {{--
            A masthead rule instead of a centred stack: the title on one side, the claim
            that has to be checkable on the other, and one hairline under both. It is an
            editorial device rather than a landing-page device, which is the point.
        --}}
        {{--
            A running head, not a masthead.

            Six of the eight rebuilt sections arrived opening the same way — a heading on
            one side, a supporting line on the other, baseline-aligned. Four authors each
            killed the centred eyebrow and then independently reached for the same
            replacement, which is a new template rather than none. This section is one of
            the four that gives it up: the title sits on one line with the claim beside it,
            and the largest screenshot carries the section instead of a heading block.
        --}}
        <div class="tour-run rise">
            <h2 class="tour-title" id="tour-title">همان صفحه‌هایی که هر روز باز می‌کنید</h2>
            <p class="tour-claim">تصویرها از خود نرم‌افزار گرفته شده‌اند — نه ماکت، نه طرح.</p>
        </div>

        <div class="tour-grid">
            @foreach ($screens as [$file, $name, $body, $span, $alt])
                <figure class="tour-item rise" data-span="{{ $span }}">
                    <div class="frame">
                        <div class="frame__bar" aria-hidden="true">
                            <span class="frame__dot"></span><span class="frame__dot"></span><span class="frame__dot"></span>
                        </div>
                        <img src="{{ Vite::asset("resources/landing/shots/{$file}.webp") }}"
                             alt="{{ $alt }}"
                             width="1440" height="900" loading="lazy" decoding="async">
                    </div>

                    <figcaption class="tour-cap">
                        {{-- No index here. The rebuilt page arrived with THREE separate
                             numbering devices — ۰۱–۰۶ in the margins of §3, ۰۱–۰۶ again on
                             these tiles, and ۱–۶ on the FAQ — two of them the same numerals
                             in the same order, 40% of the page apart. Generated pages love
                             numbered decoration; three flavours of it is the tell wearing a
                             hat. The mosaic's unequal tile sizes already carry the ranking,
                             which is this file's own argument for the layout. --}}
                        <span class="tour-cap__text">
                            <b class="tour-name">{{ $name }}</b>
                            <span class="tour-body">{{ $body }}</span>
                        </span>
                    </figcaption>
                </figure>
            @endforeach
        </div>
    </div>
</section>
