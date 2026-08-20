@php
    use App\Support\Money;

    /** Yearly = twelve months for the price of ten. Stated on screen, never implied. */
    $yearFactor = 10;

    $shots = [
        'sales' => ['کالا و فروش', 'فروش سریال‌دار با IMEI', 'بارکد یا IMEI را اسکن کنید؛ دستگاه با همان شناسه از انبار کم می‌شود و روی فاکتور می‌نشیند. معاوضه، تخفیف و چند روش پرداخت روی همان صفحه.', 'pos'],
        'repairs' => ['تعمیرات', 'از پذیرش تا تحویل', 'قبض پذیرش با QR پیگیری، وضعیت‌های واقعی کارگاه، قطعات مصرفی از انبار، و دستگاه‌های رسوبی که فراموش نمی‌شوند.', 'repairs'],
        'installments' => ['اقساط و چک', 'میز وصول، نه دفترچه', 'اقساط و چک‌ها با سررسید و وضعیت. چه کسی امروز باید بیاید، چه کسی عقب افتاده، و چقدر هنوز وصول نشده.', 'installments'],
        'sms' => ['پیامک', 'مشتری خودش می‌فهمد', 'پیامک آماده تحویل، یادآوری قسط، تشکر بعد از فروش — از روی رویدادهای واقعی سیستم، نه فهرست دستی.', 'sms'],
        'reporting' => ['گزارش‌ها', 'سود، نه فقط فروش', 'بهای تمام‌شده در لحظهٔ فروش ثبت می‌شود، پس سود هر کالا و هر دستگاه واقعی است — نه تفاضل قیمت امروز.', 'profit'],
    ];
@endphp
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>مویار — نرم‌افزار فروشگاه موبایل: فروش، تعمیرات، اقساط</title>
    <meta name="description" content="مویار کار روزانهٔ مغازهٔ موبایل را می‌بندد: فروش سریال‌دار با IMEI، تعمیرات، اقساط و چک، پیامک خودکار و گزارش سود. ۱۴ روز رایگان، بدون کارت بانکی.">
    <meta name="theme-color" content="#070B0E">
    <link rel="canonical" href="{{ url('/') }}">

    <meta property="og:type" content="website">
    <meta property="og:title" content="مویار — نرم‌افزار فروشگاه موبایل">
    <meta property="og:description" content="از پذیرش تعمیر تا تسویه، روی یک قبض.">
    <meta property="og:locale" content="fa_IR">
    <meta property="og:url" content="{{ url('/') }}">

    @vite(['resources/landing/landing.css', 'resources/landing/landing.js'])
</head>
<body>

{{-- Skip link: the first stop for a keyboard, before a sticky nav and a pinned hero. --}}
<a href="#main" class="l-btn l-btn--ghost" style="position:absolute;inset-inline-start:-9999px" onfocus="this.style.insetInlineStart='1rem';this.style.insetBlockStart='1rem';this.style.zIndex='99'" onblur="this.style.insetInlineStart='-9999px'">پرش به محتوا</a>

{{-- ================================================================= nav === --}}
<header class="l-nav" data-nav>
    <div class="l-shell l-nav__inner">
        <a href="/" class="l-nav__brand">
            <svg class="l-nav__mark" viewBox="0 0 32 32" fill="none" aria-hidden="true">
                <rect x="6" y="2" width="20" height="28" rx="4" stroke="#3FD9C8" stroke-width="2"/>
                <path d="M11 24h10" stroke="#FFD84D" stroke-width="2" stroke-linecap="round"/>
            </svg>
            مویار
        </a>

        <nav class="l-nav__links" aria-label="پیمایش اصلی">
            <a href="#features">امکانات</a>
            <a href="#pricing">تعرفه‌ها</a>
            <a href="#faq">سوالات</a>
        </nav>

        <div class="l-nav__cta">
            <a href="#enter" class="l-btn l-btn--ghost">ورود</a>
            <a href="{{ route('register') }}" class="l-btn l-btn--label">ثبت‌نام رایگان</a>
        </div>
    </div>
</header>

<main id="main">

{{-- ================================================================ hero === --}}
<section class="l-hero" data-hero>
    <div class="l-shell l-hero__grid">
        <div>
            <p class="l-hero__eyebrow">
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="2"/></svg>
                ۱۴ روز رایگان — بدون کارت بانکی
            </p>

            <h1 class="l-hero__title">از پذیرش تعمیر تا تسویه،<br><em>روی یک قبض.</em></h1>

            <p class="l-hero__lede">
                مویار کار روزانهٔ مغازهٔ موبایل را می‌بندد: فروش سریال‌دار با IMEI، تعمیرات،
                اقساط و چک، پیامک خودکار به مشتری، و گزارش سودی که واقعاً سود است.
            </p>

            <div class="l-hero__actions">
                <a href="{{ route('register') }}" class="l-btn l-btn--label l-btn--lg">۱۴ روز رایگان شروع کنید</a>
                <a href="#features" class="l-btn l-btn--ghost l-btn--lg">امکانات را ببینید</a>
            </div>

            <p class="l-hero__note">راه‌اندازی چند دقیقه‌ای · بدون نصب · فارسی و تقویم شمسی</p>
        </div>

        {{--
            The signature. `data-print` is switched to "on" by effects.js only when the
            choreography is actually going to run — so with no JavaScript, a failed
            chunk, or reduced motion, this is simply a fully printed receipt.
        --}}
        <div class="l-receipt" data-print="off" role="img"
             aria-label="نمونهٔ قبض پذیرش تعمیر: دستگاه اپل آیفون ۱۳ با شناسهٔ ۳۵۴۸۷۹۱۱۶۲۳۴۹۰۱، برآورد اولیه ۴۵۰٬۰۰۰ تومان، پیامک آماده تحویل، و تسویهٔ نهایی ۴۲۰٬۰۰۰ تومان.">
            <div class="l-receipt__head">
                <div class="l-receipt__shop">موبایل مویار</div>
                <div class="l-receipt__kind">قبض پذیرش تعمیر</div>
            </div>

            <dl>
                <div class="l-receipt__line"><dt>شماره قبض</dt><dd>REP-۰۰۰۱۸۴</dd></div>
                <div class="l-receipt__line"><dt>تاریخ</dt><dd>۱۴۰۵/۰۵/۲۹</dd></div>
                <div class="l-receipt__line"><dt>مشتری</dt><dd>سمیرا احمدی</dd></div>
                <div class="l-receipt__line"><dt>دستگاه</dt><dd>اپل آیفون ۱۳</dd></div>
                <div class="l-receipt__line"><dt>IMEI</dt><dd>۳۵۴۸۷۹۱۱۶۲۳۴۹۰۱</dd></div>
                <div class="l-receipt__line"><dt>ایراد</dt><dd>شکستگی گلس</dd></div>
                <div class="l-receipt__line"><dt>برآورد اولیه</dt><dd>۴۵۰٬۰۰۰ تومان</dd></div>
            </dl>

            <div class="l-receipt__rule"></div>
            <p class="l-receipt__act">— دستگاه آمادهٔ تحویل شد —</p>

            <p class="l-receipt__sms">
                پیامک به ۰۹۳۵۱۲۳۴۵۶۷:<br>
                «موبایل مویار — دستگاه شما آمادهٔ تحویل است. لطفاً قبض را همراه بیاورید.»
            </p>

            <div class="l-receipt__rule"></div>

            <dl>
                <div class="l-receipt__line"><dt>هزینهٔ نهایی</dt><dd>۴۲۰٬۰۰۰ تومان</dd></div>
                <div class="l-receipt__line"><dt>پیش‌پرداخت</dt><dd>۱۰۰٬۰۰۰ تومان</dd></div>
            </dl>

            <div class="l-receipt__total"><span>تسویه</span><span>۳۲۰٬۰۰۰ تومان</span></div>
        </div>
    </div>
</section>

{{-- ============================================================== strip === --}}
<div class="l-strip">
    <div class="l-shell l-strip__row">
        <span>فروش سریال‌دار</span>
        <span>تعمیرات</span>
        <span>اقساط و چک</span>
        <span>چندشعبه</span>
        <span>تقویم شمسی، مبلغ به تومان</span>
    </div>
</div>

{{-- =========================================================== features === --}}
{{--
    ONE pinned stage, five scenes — not five pins. Below 900px this is an ordinary
    stacked list with no pinning at all: the tabs become headings and each screenshot
    simply follows its own text.
--}}
<section class="l-sec" id="features" data-stage>
    <div class="l-shell">
        <p class="l-sec__eyebrow l-reveal">امکانات</p>
        <h2 class="l-sec__title l-reveal">پنج کاری که هر روز پشت پیشخوان تکرار می‌شود</h2>
        <p class="l-sec__lede l-reveal">همهٔ تصویرها از خود محصول گرفته شده‌اند — نه طرح، نه ماکت.</p>

        <div class="l-stage__inner" data-stage-inner style="margin-block-start:3rem">
            <div class="l-stage__list">
                @foreach ($shots as $key => [$eyebrow, $title, $body, $file])
                    <div class="l-scene">
                        <div class="l-scene__tab" data-scene-tab data-active="{{ $loop->first ? 'true' : 'false' }}">
                            <div>
                                <strong>{{ $title }}</strong>
                                <span>{{ $body }}</span>
                            </div>
                        </div>

                        {{-- Mobile tier: the frame belongs with its text. Desktop CSS lifts
                             these out into the absolutely-positioned stack on the right. --}}
                        <div class="l-frame" data-scene-frame style="margin-block-start:1rem">
                            <div class="l-frame__bar" aria-hidden="true">
                                <span class="l-frame__dot"></span><span class="l-frame__dot"></span><span class="l-frame__dot"></span>
                            </div>
                            <img src="{{ Vite::asset("resources/landing/shots/{$file}.webp") }}"
                                 alt="نمای واقعی صفحهٔ {{ $title }} در مویار"
                                 width="1440" height="900" loading="lazy" decoding="async">
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Desktop stack target. Empty on purpose: the frames above are moved here
                 by CSS positioning, so there is exactly one copy of each image in the DOM
                 and the mobile tier needs no duplicate markup. --}}
            <div class="l-stage__frames" aria-hidden="true"></div>
        </div>
    </div>
</section>

{{-- =============================================================== IMEI === --}}
<section class="l-sec l-imei">
    <div class="l-shell" style="text-align:center">
        <p class="l-sec__eyebrow l-reveal">تفاوت اصلی</p>
        <h2 class="l-sec__title l-reveal">هر گوشی یک شناسنامه دارد</h2>

        <p class="l-imei__digits nums" data-imei aria-label="نمونه شناسهٔ IMEI">
            @foreach (str_split('۳۵۴۸۷۹۱۱۶۲۳۴۹۰۱') as $d)<span>{{ $d }}</span>@endforeach
        </p>

        <p class="l-sec__lede l-reveal" style="margin-inline:auto">
            دستگاه‌ها در مویار «تعداد» نیستند؛ هرکدام یک سطر با شناسهٔ خودشان هستند.
            به همین دلیل این سه سؤال همیشه جواب دارند:
        </p>

        <div class="l-imei__trail l-reveal">
            <div class="l-imei__step">
                <b>از چه کسی خریدم؟</b>
                <p>تاریخ خرید، تأمین‌کننده و بهای تمام‌شدهٔ همان دستگاه.</p>
            </div>
            <div class="l-imei__step">
                <b>به چه کسی فروختم؟</b>
                <p>فاکتور، مشتری و تاریخ فروش — با همان شناسه.</p>
            </div>
            <div class="l-imei__step">
                <b>کِی تعمیر شد؟</b>
                <p>هر بار پذیرش، قطعهٔ مصرفی و هزینه‌ای که گرفته شد.</p>
            </div>
        </div>

        <p class="l-hero__note" style="margin-block-start:1.5rem">
            دربارهٔ همتا صادق باشیم: سامانهٔ همتا API عمومی ندارد. مویار سوابق را
            نگه می‌دارد و مسیر کار را نشان می‌دهد، اما جای ثبت در همتا را نمی‌گیرد.
        </p>
    </div>
</section>

{{-- ============================================================ pricing === --}}
<section class="l-sec" id="pricing">
    <div class="l-shell">
        <p class="l-sec__eyebrow l-reveal">تعرفه‌ها</p>
        <h2 class="l-sec__title l-reveal">به اندازهٔ مغازه‌تان</h2>
        <p class="l-sec__lede l-reveal">۱۴ روز رایگان روی همهٔ پلن‌ها. بدون کارت بانکی، بدون قرارداد.</p>

        <div class="l-toggle l-reveal" data-plan-toggle role="group" aria-label="دورهٔ پرداخت">
            <button type="button" data-interval="month" aria-pressed="true">ماهانه</button>
            <button type="button" data-interval="year" aria-pressed="false">سالانه</button>
        </div>
        <p class="l-hero__note" data-saving hidden>پرداخت سالانه: ۱۲ ماه به قیمت ۱۰ ماه.</p>

        <div class="l-plans" style="margin-block-start:1.5rem">
            @foreach ($plans as $plan)
                @php $featured = $plan->code === 'pro'; @endphp
                <div class="l-plan l-reveal" data-featured="{{ $featured ? 'true' : 'false' }}">
                    @if ($featured)<span class="l-plan__badge">انتخاب بیشتر فروشگاه‌ها</span>@endif

                    <h3 class="l-plan__name">{{ $plan->name_fa }}</h3>
                    <p class="l-plan__tag">{{ $plan->tagline_fa }}</p>

                    <p class="l-plan__price nums"
                       data-monthly="{{ money($plan->price, Money::UNIT_TOMAN, true) }}"
                       data-yearly="{{ money($plan->price * $yearFactor, Money::UNIT_TOMAN, true) }}">{{ money($plan->price, Money::UNIT_TOMAN, true) }}</p>
                    <p class="l-plan__unit" data-unit data-unit-month="تومان / ماه" data-unit-year="تومان / سال">تومان / ماه</p>

                    <ul class="l-plan__list">
                        @foreach ($plan->modules->sortBy('name_fa')->take(7) as $module)
                            <li>
                                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3 8.5l3.5 3.5L13 5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                {{ $module->name_fa }}
                            </li>
                        @endforeach
                        @if ($plan->modules->count() > 7)
                            <li style="color:var(--color-say-soft)">و {{ \App\Support\Digits::toPersian((string) ($plan->modules->count() - 7)) }} مورد دیگر</li>
                        @endif
                    </ul>

                    <a href="{{ route('register') }}" class="l-btn {{ $featured ? 'l-btn--label' : 'l-btn--ghost' }}">شروع رایگان</a>
                </div>
            @endforeach
        </div>

        <div class="l-addons l-reveal">
            <h3 style="font-size:1.0625rem">افزودنی‌ها</h3>
            <p class="l-hero__note">هر ماژول را جدا هم می‌شود به پلن اضافه کرد.</p>
            <div class="l-addons__grid">
                @foreach ($addons as $addon)
                    <div class="l-addon">
                        <span>{{ $addon->name_fa }}</span>
                        <b class="nums">{{ money((int) $addon->addon_price, Money::UNIT_TOMAN, true) }}</b>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</section>

{{-- ================================================================ FAQ === --}}
<section class="l-sec" id="faq">
    <div class="l-shell">
        <p class="l-sec__eyebrow l-reveal">سوالات پرتکرار</p>
        <h2 class="l-sec__title l-reveal">چیزهایی که معمولاً می‌پرسند</h2>

        <div class="l-faq" data-faq>
            <details>
                <summary>با سامانهٔ همتا چه می‌کند؟</summary>
                <p>همتا API عمومی ندارد، پس هیچ نرم‌افزاری نمی‌تواند مستقیماً در آن ثبت کند — و ما هم ادعا نمی‌کنیم. مویار وضعیت همتای هر دستگاه را نگه می‌دارد، یادآوری می‌کند و راهنمای مرحله‌به‌مرحله دارد؛ ثبت نهایی را خودتان در سامانه انجام می‌دهید.</p>
            </details>
            <details>
                <summary>سامانهٔ مودیان چطور؟</summary>
                <p>ماژول مودیان روی پلن سازمانی هست و صورتحساب‌ها را آمادهٔ ارسال می‌کند. ارسال واقعی به شرکت معتمدی که با آن قرارداد دارید وابسته است.</p>
            </details>
            <details>
                <summary>از نرم‌افزار قبلی‌ام می‌توانم بیایم؟</summary>
                <p>بله. فهرست کالاها را از فایل اکسل وارد می‌کنید؛ قبل از ثبت، خروجی آزمایشی می‌بینید و ستون‌ها را خودتان تطبیق می‌دهید تا چیزی اشتباه وارد نشود.</p>
            </details>
            <details>
                <summary>داده‌های من مال کیست؟</summary>
                <p>مال شما. هر زمان بخواهید خروجی اکسل می‌گیرید، و اطلاعات هر فروشگاه از فروشگاه‌های دیگر جداست — این جداسازی در خود پایگاه داده اعمال می‌شود، نه فقط در نرم‌افزار.</p>
            </details>
            <details>
                <summary>اینترنت مغازه قطع شود چه؟</summary>
                <p>مویار روی مرورگر کار می‌کند و به اینترنت نیاز دارد. برای همین قبض‌ها چاپ می‌شوند و خروجی اکسل همیشه در دسترس است؛ اما اگر اینترنت مغازه‌تان ناپایدار است، این را قبل از شروع بدانید.</p>
            </details>
        </div>
    </div>
</section>

{{-- ============================================================== enter === --}}
<section class="l-sec" id="enter" style="padding-block-start:0">
    <div class="l-shell" style="max-inline-size:32rem">
        <h2 style="font-size:1.75rem;margin-block-end:0.5rem">ورود به فروشگاه شما</h2>
        <p class="l-hero__note" style="margin-block-end:1rem">
            هر فروشگاه نشانی خودش را دارد. نام فروشگاه را بنویسید تا برویم.
        </p>
        <form data-enter style="display:flex;gap:0.5rem;flex-wrap:wrap">
            <label for="shop" class="sr-only" style="position:absolute;inset-inline-start:-9999px">نام فروشگاه</label>
            <input id="shop" name="shop" required autocomplete="off" inputmode="latin" placeholder="mobitest"
                   style="flex:1 1 12rem;min-block-size:2.75rem;padding-inline:0.875rem;border-radius:0.625rem;background:var(--color-night-lift);border:1px solid var(--color-night-edge);color:#fff;direction:ltr;text-align:left">
            <span style="align-self:center;color:var(--color-say-soft)">.{{ config('app.domain') }}</span>
            <button type="submit" class="l-btn l-btn--ghost">ورود</button>
        </form>
    </div>
</section>

{{-- ============================================================== final === --}}
<section class="l-final">
    <div class="l-shell">
        <h2 class="l-sec__title l-reveal">امروز عصر می‌توانید اولین فاکتور را بزنید</h2>
        <p class="l-sec__lede l-reveal" style="margin-inline:auto;margin-block-end:2rem">
            ۱۴ روز رایگان، بدون کارت بانکی. اگر نپسندیدید، هیچ اتفاقی نمی‌افتد.
        </p>
        <a href="{{ route('register') }}" class="l-btn l-btn--label l-btn--lg l-reveal">ثبت‌نام رایگان</a>
    </div>
</section>

</main>

<footer class="l-foot">
    <div class="l-shell l-foot__row">
        <span>© ۱۴۰۵ مویار</span>
        <nav style="display:flex;gap:1.25rem" aria-label="پیوندهای حقوقی">
            <a href="{{ route('legal.terms') }}">قوانین و شرایط</a>
            <a href="{{ route('legal.privacy') }}">حریم خصوصی</a>
            <a href="#pricing">تعرفه‌ها</a>
        </nav>
    </div>
</footer>

</body>
</html>
