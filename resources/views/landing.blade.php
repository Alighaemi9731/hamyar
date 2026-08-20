@php
    use App\Support\Digits;
    use App\Support\Money;

    /** Yearly = twelve months for the price of ten. Stated on screen, never implied. */
    $yearFactor = 10;

    /** [title, body, screenshot] — five flagship modules, alternating down the page. */
    $features = [
        ['فروش سریال‌دار با IMEI', 'بارکد یا IMEI را اسکن کنید؛ دستگاه با همان شناسه از انبار کم می‌شود و روی فاکتور می‌نشیند. معاوضه، تخفیف و چند روش پرداخت روی همان صفحه.', 'pos'],
        ['تعمیرات، از پذیرش تا تحویل', 'قبض پذیرش با QR پیگیری، وضعیت‌های واقعی کارگاه، قطعات مصرفی از انبار، و دستگاه‌های رسوبی که فراموش نمی‌شوند.', 'repairs'],
        ['اقساط و چک — میز وصول، نه دفترچه', 'اقساط و چک‌ها با سررسید و وضعیت. چه کسی امروز باید بیاید، چه کسی عقب افتاده، و چقدر هنوز وصول نشده.', 'installments'],
        ['پیامک، از روی رویدادهای واقعی', 'پیامک آمادهٔ تحویل، یادآوری قسط، تشکر بعد از فروش — از روی اتفاق‌های خود سیستم، نه فهرست دستی.', 'sms'],
        ['گزارش سود، نه فقط فروش', 'بهای تمام‌شده در لحظهٔ فروش ثبت می‌شود، پس سود هر کالا و هر دستگاه واقعی است — نه تفاضل قیمت امروز.', 'profit'],
    ];
@endphp
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>مویار — نرم‌افزار فروشگاه موبایل: فروش، تعمیرات، اقساط</title>
    <meta name="description" content="مویار کار روزانهٔ مغازهٔ موبایل را می‌بندد: فروش سریال‌دار با IMEI، تعمیرات، اقساط و چک، پیامک خودکار و گزارش سود. ۱۴ روز رایگان، بدون کارت بانکی.">
    <meta name="theme-color" content="#FFFFFF">
    <link rel="canonical" href="{{ url('/') }}">

    <meta property="og:type" content="website">
    <meta property="og:title" content="مویار — نرم‌افزار فروشگاه موبایل">
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
            مویار
        </a>

        <nav class="nav__links" aria-label="پیمایش اصلی">
            <a href="#features">امکانات</a>
            <a href="#pricing">تعرفه‌ها</a>
            <a href="#faq">سوالات</a>
        </nav>

        <div class="nav__cta">
            <a href="#enter" class="btn btn--quiet">ورود</a>
            <a href="{{ route('register') }}" class="btn btn--primary">ثبت‌نام رایگان</a>
        </div>
    </div>
</header>

<main id="main">

{{-- ================================================================ hero === --}}
<section class="hero">
    <div class="shell hero__grid">
        <div>
            <h1 class="hero__title">از پذیرش تعمیر تا تسویه،<br>روی یک قبض.</h1>

            <p class="hero__lede">
                مویار کار روزانهٔ مغازهٔ موبایل را می‌بندد: فروش سریال‌دار با IMEI، تعمیرات،
                اقساط و چک، پیامک خودکار به مشتری، و گزارش سودی که واقعاً سود است.
            </p>

            <div class="hero__actions">
                <a href="{{ route('register') }}" class="btn btn--primary btn--lg">۱۴ روز رایگان شروع کنید</a>
                <a href="#features" class="btn btn--quiet btn--lg">امکانات را ببینید</a>
            </div>

            <p class="hero__note">بدون کارت بانکی · راه‌اندازی چند دقیقه‌ای · فارسی و تقویم شمسی</p>
        </div>

        {{--
            The signature object, and now static.

            The rejected direction printed this line by line on scroll, pinned, driven by
            GSAP (ADR 0016). It is a rendered artefact instead: there is nothing to
            trigger, nothing to fail, and nothing to switch off for reduced motion.
        --}}
        <div class="receipt" role="img"
             aria-label="نمونهٔ قبض پذیرش تعمیر: اپل آیفون ۱۳ با شناسهٔ ۳۵۴۸۷۹۱۱۶۲۳۴۹۰۱، برآورد اولیه ۴۵۰٬۰۰۰ تومان، پیامک آماده تحویل، و تسویهٔ ۳۲۰٬۰۰۰ تومان.">
            <div class="receipt__head">
                <div class="receipt__shop">موبایل مویار</div>
                <div class="receipt__kind">قبض پذیرش تعمیر</div>
            </div>

            <dl>
                <div class="receipt__line"><dt>شماره قبض</dt><dd>REP-۰۰۰۱۸۴</dd></div>
                <div class="receipt__line"><dt>تاریخ</dt><dd>۱۴۰۵/۰۵/۲۹</dd></div>
                <div class="receipt__line"><dt>مشتری</dt><dd>سمیرا احمدی</dd></div>
                <div class="receipt__line"><dt>دستگاه</dt><dd>اپل آیفون ۱۳</dd></div>
                <div class="receipt__line"><dt>IMEI</dt><dd>۳۵۴۸۷۹۱۱۶۲۳۴۹۰۱</dd></div>
                <div class="receipt__line"><dt>ایراد</dt><dd>شکستگی گلس</dd></div>
                <div class="receipt__line"><dt>برآورد اولیه</dt><dd>۴۵۰٬۰۰۰ تومان</dd></div>
            </dl>

            <div class="receipt__rule"></div>
            <p class="receipt__act">— دستگاه آمادهٔ تحویل شد —</p>

            <p class="receipt__sms">
                «موبایل مویار — دستگاه شما آمادهٔ تحویل است. لطفاً قبض را همراه بیاورید.»
            </p>

            <div class="receipt__rule"></div>

            <dl>
                <div class="receipt__line"><dt>هزینهٔ نهایی</dt><dd>۴۲۰٬۰۰۰ تومان</dd></div>
                <div class="receipt__line"><dt>پیش‌پرداخت</dt><dd>۱۰۰٬۰۰۰ تومان</dd></div>
            </dl>

            <div class="receipt__total"><span>تسویه</span><span>۳۲۰٬۰۰۰ تومان</span></div>
        </div>
    </div>
</section>

{{-- ============================================================== strip === --}}
<div class="strip">
    <div class="shell strip__row">
        <span>فروش سریال‌دار</span>
        <span>تعمیرات</span>
        <span>اقساط و چک</span>
        <span>چندشعبه</span>
        <span>تقویم شمسی، مبلغ به تومان</span>
    </div>
</div>

{{-- =========================================================== features === --}}
<section class="sec" id="features">
    <div class="shell">
        <div class="sec__head rise">
            <p class="sec__eyebrow">امکانات</p>
            <h2 class="sec__title">پنج کاری که هر روز پشت پیشخوان تکرار می‌شود</h2>
            <p class="sec__lede">همهٔ تصویرها از خود محصول گرفته شده‌اند — نه طرح، نه ماکت.</p>
        </div>

        @foreach ($features as [$title, $body, $file])
            <article class="feature rise">
                <div class="feature__text">
                    <h3 class="feature__title">{{ $title }}</h3>
                    <p class="feature__body">{{ $body }}</p>
                </div>

                <div class="frame">
                    <div class="frame__bar" aria-hidden="true">
                        <span class="frame__dot"></span><span class="frame__dot"></span><span class="frame__dot"></span>
                    </div>
                    <img src="{{ Vite::asset("resources/landing/shots/{$file}.webp") }}"
                         alt="نمای واقعی صفحهٔ {{ $title }} در مویار"
                         width="1440" height="900" loading="lazy" decoding="async">
                </div>
            </article>
        @endforeach
    </div>
</section>

{{-- =============================================================== IMEI === --}}
<section class="sec sec--alt">
    <div class="shell" style="text-align:center">
        <div class="rise">
            <p class="sec__eyebrow">تفاوت اصلی</p>
            <h2 class="sec__title">هر گوشی یک شناسنامه دارد</h2>

            <p class="imei__digits nums">۳۵۴۸۷۹۱۱۶۲۳۴۹۰۱</p>

            <p class="sec__lede" style="margin-inline:auto">
                دستگاه‌ها در مویار «تعداد» نیستند؛ هرکدام یک سطر با شناسهٔ خودشان هستند.
                به همین دلیل این سه سؤال همیشه جواب دارند:
            </p>
        </div>

        <div class="imei__trail rise">
            <div class="imei__step">
                <b>از چه کسی خریدم؟</b>
                <p>تاریخ خرید، تأمین‌کننده و بهای تمام‌شدهٔ همان دستگاه.</p>
            </div>
            <div class="imei__step">
                <b>به چه کسی فروختم؟</b>
                <p>فاکتور، مشتری و تاریخ فروش — با همان شناسه.</p>
            </div>
            <div class="imei__step">
                <b>کِی تعمیر شد؟</b>
                <p>هر بار پذیرش، قطعهٔ مصرفی و هزینه‌ای که گرفته شد.</p>
            </div>
        </div>

        <p class="hero__note rise" style="margin-block-start:2rem;max-inline-size:40rem;margin-inline:auto">
            دربارهٔ همتا صادق باشیم: سامانهٔ همتا API عمومی ندارد. مویار سوابق را نگه
            می‌دارد و مسیر کار را نشان می‌دهد، اما جای ثبت در همتا را نمی‌گیرد.
        </p>
    </div>
</section>

{{-- ============================================================ pricing === --}}
<section class="sec" id="pricing">
    <div class="shell">
        <div class="sec__head rise" style="margin-block-end:0">
            <p class="sec__eyebrow">تعرفه‌ها</p>
            <h2 class="sec__title">به اندازهٔ مغازه‌تان</h2>
            <p class="sec__lede">۱۴ روز رایگان روی همهٔ پلن‌ها. بدون کارت بانکی، بدون قرارداد.</p>

            <div class="toggle" data-plan-toggle role="group" aria-label="دورهٔ پرداخت">
                <button type="button" data-interval="month" aria-pressed="true">ماهانه</button>
                <button type="button" data-interval="year" aria-pressed="false">سالانه</button>
            </div>
            <p class="hero__note" data-saving hidden>پرداخت سالانه: ۱۲ ماه به قیمت ۱۰ ماه.</p>
        </div>

        <div class="plans rise">
            @foreach ($plans as $plan)
                @php $featured = $plan->code === 'pro'; @endphp
                <div class="plan" data-featured="{{ $featured ? 'true' : 'false' }}">
                    @if ($featured)<span class="plan__badge">انتخاب بیشتر فروشگاه‌ها</span>@endif

                    <h3 class="plan__name">{{ $plan->name_fa }}</h3>
                    <p class="plan__tag">{{ $plan->tagline_fa }}</p>

                    <p class="plan__price nums"
                       data-monthly="{{ money($plan->price, Money::UNIT_TOMAN, true) }}"
                       data-yearly="{{ money($plan->price * $yearFactor, Money::UNIT_TOMAN, true) }}">{{ money($plan->price, Money::UNIT_TOMAN, true) }}</p>
                    <p class="plan__unit" data-unit data-unit-month="تومان / ماه" data-unit-year="تومان / سال">تومان / ماه</p>

                    <ul class="plan__list">
                        @foreach ($plan->modules->sortBy('name_fa')->take(7) as $module)
                            <li>
                                <svg width="13" height="13" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3 8.5l3.5 3.5L13 5" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                {{ $module->name_fa }}
                            </li>
                        @endforeach
                        @if ($plan->modules->count() > 7)
                            <li style="color:var(--color-navy-mute)">و {{ Digits::toPersian((string) ($plan->modules->count() - 7)) }} مورد دیگر</li>
                        @endif
                    </ul>

                    <a href="{{ route('register') }}" class="btn {{ $featured ? 'btn--primary' : 'btn--quiet' }}">شروع رایگان</a>
                </div>
            @endforeach
        </div>

        <div class="addons rise">
            <h3 style="font-size:1.125rem">افزودنی‌ها</h3>
            <p class="hero__note">هر ماژول را جدا هم می‌شود به پلن اضافه کرد.</p>
            <div class="addons__grid">
                @foreach ($addons as $addon)
                    <div class="addon">
                        <span>{{ $addon->name_fa }}</span>
                        <b class="nums">{{ money((int) $addon->addon_price, Money::UNIT_TOMAN, true) }}</b>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</section>

{{-- ================================================================ FAQ === --}}
<section class="sec sec--alt" id="faq">
    <div class="shell">
        <div class="sec__head rise" style="margin-block-end:0">
            <p class="sec__eyebrow">سوالات پرتکرار</p>
            <h2 class="sec__title">چیزهایی که معمولاً می‌پرسند</h2>
        </div>

        <div class="faq rise" data-faq>
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

        <p class="hero__note rise" style="margin-block-start:2rem">
            <a href="{{ route('legal.terms') }}" style="color:var(--color-accent)">قوانین و شرایط</a>
            ·
            <a href="{{ route('legal.privacy') }}" style="color:var(--color-accent)">حریم خصوصی</a>
        </p>
    </div>
</section>

{{-- ============================================================== enter === --}}
<section class="sec" id="enter" style="padding-block-end:0">
    <div class="shell" style="max-inline-size:30rem">
        <h2 style="font-size:1.5rem;margin-block-end:0.5rem">ورود به فروشگاه شما</h2>
        <p class="hero__note" style="margin-block-end:1.25rem">
            هر فروشگاه نشانی خودش را دارد. نام فروشگاه را بنویسید تا برویم.
        </p>
        <form data-enter style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center">
            <label for="shop" style="position:absolute;inset-inline-start:-9999px">نام فروشگاه</label>
            <input id="shop" name="shop" class="field" required autocomplete="off" placeholder="mobitest" style="flex:1 1 11rem">
            <span style="color:var(--color-navy-mute)">.{{ config('app.domain') }}</span>
            <button type="submit" class="btn btn--quiet">ورود</button>
        </form>
    </div>
</section>

{{-- ============================================================== final === --}}
<section class="sec final">
    <div class="shell rise">
        <h2 class="sec__title">امروز عصر می‌توانید اولین فاکتور را بزنید</h2>
        <p class="sec__lede" style="margin-inline:auto;margin-block-end:2rem">
            ۱۴ روز رایگان، بدون کارت بانکی. اگر نپسندیدید، هیچ اتفاقی نمی‌افتد.
        </p>
        <a href="{{ route('register') }}" class="btn btn--primary btn--lg">ثبت‌نام رایگان</a>
    </div>
</section>

</main>

<footer class="foot">
    <div class="shell foot__row">
        <span>© ۱۴۰۵ مویار</span>
        <nav style="display:flex;gap:1.5rem" aria-label="پیوندهای حقوقی">
            <a href="{{ route('legal.terms') }}">قوانین و شرایط</a>
            <a href="{{ route('legal.privacy') }}">حریم خصوصی</a>
            <a href="#pricing">تعرفه‌ها</a>
        </nav>
    </div>
</footer>

</body>
</html>
