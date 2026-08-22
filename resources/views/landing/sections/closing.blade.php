{{--
    Section 8 — دعوت پایانی و فوتر کامل.

    ## One navy tail, not "a dark CTA band and then a grey footer"

    "A dark band with two centred buttons in it, followed by a light footer strip" is on
    the owner's list of tells, and it is also two weak endings instead of one strong one.
    So the closing call and the whole footer share ONE navy ground: the call sits at the
    top of it, asymmetric — the claim on the inline-start, the buttons on the inline-end —
    and the footer columns sit under a hairline below. The page ends with weight, which is
    the exact complaint («بی‌روح») that ADR 0016 records against the calm version.

    This is the page's dark anchor at the bottom. The IMEI section is the one in the
    middle. Colour carries meaning here: dark means "remember this", and spending it
    twice on a long page is the budget.

    ## Where to include this

    **After `</main>`, not inside it.** This partial emits the site `<footer>`, and a
    `<footer>` inside `<main>` is not `contentinfo` — the landmark would simply vanish
    for a screen reader. The wrapping element is a plain `<div>` (not a sectioning
    element) precisely so the `<footer>` inside it still maps to `contentinfo`.

    It also REPLACES the page's existing `<footer class="foot">`; leaving both would ship
    two footers.

    @see resources/landing/sections/closing.css
--}}
@php
    /**
     * Golden rule 1b: the apex is never written down, not even now that everyone knows
     * what it is. The mailbox name is ours, the host comes from config, and that is what
     * keeps the local stack and production rendering from the same template — and an
     * apex change a data migration rather than a code change.
     * `bin/check-apex-domain` scans this directory.
     */
    $contactEmail = 'info@'.config()->string('app.domain');

    /**
     * The modules column. Plain labels, not links: the anchors that would serve them
     * belong to sections other engineers own, and a footer full of links that all land on
     * the same place is worse than a list that admits it is a list. The integrator can
     * turn these into anchors once the section ids upstream are fixed.
     *
     * @var list<string>
     */
    $modules = [
        'فروش و صندوق',
        'انبار سریال‌دار و IMEI',
        'تعمیرات',
        'اقساط و چک',
        'خزانه و بانک',
        'پیامک',
        'گزارش سود',
        'چندشعبه و حواله',
    ];
@endphp

<div class="signoff">
    {{-- A 52px hairline grid in white, masked to fade out. Its own element and its own
         rules rather than the page's `.mesh`, so this file stays self-contained: `.mesh`
         only paints white lines inside `.band` and `.final__card`, and this is neither. --}}
    <div class="signoff__mesh" aria-hidden="true"></div>

    <section class="shell signoff__cta rise" aria-labelledby="signoff-title">
        <div>
            <h2 class="signoff__title" id="signoff-title">
                اولین فاکتورتان را <em>همین امروز</em> بزنید
            </h2>
            <p class="signoff__lede">
                چیزی نصب نمی‌شود. فروشگاه را می‌سازید، فهرست کالا را از اکسل وارد می‌کنید و
                پشت پیشخوان شروع می‌کنید. راه‌اندازی کار یک بعدازظهر است، نه یک پروژه.
            </p>
        </div>

        <div class="signoff__act">
            <div class="signoff__actions">
                <a href="{{ route('register') }}" class="btn btn--light btn--lg">
                    ۱۴ روز رایگان شروع کنید
                    @include('landing.icon', ['name' => 'arrow', 'size' => 16])
                </a>
                <a href="#pricing" class="btn btn--ghost btn--lg">دیدن تعرفه‌ها</a>
            </div>

            <p class="signoff__note">بدون کارت بانکی · بدون قرارداد سالانه · خروجی اکسل هر وقت خواستید</p>
        </div>
    </section>

    <footer class="shell signoff__foot">
        <div class="signoff__brand">
            <span class="signoff__brand__name">
                {{-- Same mark as the nav, drawn for a dark ground: the body inherits
                     `currentColor` (white) and only the accent stroke is stated. --}}
                <svg class="signoff__mark" viewBox="0 0 32 32" fill="none" aria-hidden="true">
                    <rect x="6.5" y="2.5" width="19" height="27" rx="4" stroke="currentColor" stroke-width="2"/>
                    <path class="signoff__mark__lit" d="M12 24.5h8" stroke-width="2" stroke-linecap="round"/>
                </svg>
                موبایل‌یار
            </span>

            <p>
                نرم‌افزار ابری مغازه‌های موبایل: فروش سریال‌دار، تعمیرات، اقساط و چک، پیامک و
                گزارش سود. فارسی، تقویم شمسی، و ساخته‌شده برای بازار ایران.
            </p>

            <a class="signoff__mail" href="mailto:{{ $contactEmail }}" dir="ltr">{{ $contactEmail }}</a>
        </div>

        <nav class="signoff__cols" aria-label="پیوندهای فوتر">
            <div class="signoff__col">
                <h3>محصول</h3>
                <ul>
                    <li><a href="#features">امکانات</a></li>
                    <li><a href="#pricing">تعرفه‌ها</a></li>
                    <li><a href="#faq">سؤالات پرتکرار</a></li>
                </ul>
            </div>

            <div class="signoff__col">
                <h3>ماژول‌ها</h3>
                <ul>
                    @foreach ($modules as $module)
                        <li><span>{{ $module }}</span></li>
                    @endforeach
                </ul>
            </div>

            <div class="signoff__col">
                <h3>شروع کنید</h3>
                <ul>
                    <li><a href="{{ route('register') }}">ساخت فروشگاه</a></li>
                    <li><a href="{{ route('login') }}">ورود به حساب</a></li>
                    <li><a href="mailto:{{ $contactEmail }}">تماس با ما</a></li>
                </ul>
            </div>

            <div class="signoff__col">
                <h3>قوانین</h3>
                <ul>
                    <li><a href="{{ route('legal.terms') }}">قوانین و شرایط</a></li>
                    <li><a href="{{ route('legal.privacy') }}">حریم خصوصی</a></li>
                    <li><a href="#faq">مالکیت داده‌های شما</a></li>
                </ul>
            </div>
        </nav>
    </footer>

    {{-- The year is rendered, not typed: a hardcoded «۱۴۰۵» is correct for four more
         months and then quietly wrong on the one page every prospect reads. --}}
    <div class="shell signoff__base">
        <span>© {{ jalali(now(), 'Y') }} موبایل‌یار — همهٔ حقوق محفوظ است.</span>
        <span>ساخته‌شده برای مغازه‌های موبایل ایران.</span>
    </div>
</div>
