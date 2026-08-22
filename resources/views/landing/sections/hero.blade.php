{{--
    Section 1 — هیرو, and the page's signature element.

    ## Why this hero is not the previous hero

    The rejected page opened with a centred pill, a headline, a lede, two buttons and a
    rendered 80mm receipt in the second column. Every part of that shape appears on a
    thousand generated landing pages, and the owner named it: «معلومه کلاد کد زده».

    Three things changed, and each is a decision rather than a reshuffle:

    · **The eyebrow is not a centred pill.** It is a start-aligned caption with a short
      accent rule running into it. Same words, different device — and the centred
      eyebrow above every section was the first tell on the list.
    · **The claim names the enemy.** All six shopkeeper pains in the section below this
      one are the same fault wearing different clothes: the record was on paper, and the
      paper lost it — a handwritten intake ticket, a cheque in a drawer, an instalment
      booklet, a delivery nobody remembered to mention. So the headline is about the
      shop's ledger, in the merchant's own noun (دفتر مغازه), and the promise is the one
      a merchant actually wants: nothing goes missing.
    · **The signature is the counter, not a receipt.** See below.

    ## The signature element — «دفترِ مغازه»

    The owner's verdict on the receipt was «ایده خوب است ولی اجرایش تکراری شده». The idea
    worth keeping is *paper*, because paper is what this trade runs on. The idea worth
    dropping is one pretty rendered artefact sitting still next to the text.

    So: a navy panel — the counter, after hours — with four real slips of the shop's day
    lying on it in a pile: the intake ticket, the invoice, the cheque, the instalment.
    As the visitor scrolls the first half-screen, the pile **squares up into the ledger's
    rows** and each slip takes a ثبت mark. The headline says the papers stop getting
    lost; the panel shows exactly that happening, once, to real slips with real numbers
    on them. It is the argument, not a decoration of the argument.

    Four properties this had to satisfy, three of them learned the expensive way in
    ADR 0016:

    · **It is never empty on arrival.** The panel, its heading, all four slips and every
      word on them are painted at first paint. Scroll changes where the slips *sit*, not
      whether they exist. The rejected direction drove a whole receipt from scroll
      position zero and a visitor at the top of the page got a blank sheet of paper.
    · **It cannot hijack the scroll.** One passive rAF-throttled listener writes one
      custom property (`--fold`); CSS does the rest. Nothing is pinned, nothing is
      scrubbed, no library is involved. See `sections/signature.js`.
    · **It degrades to a still.** Below 1024px — and under `prefers-reduced-motion`, and
      with no JavaScript at all — `--fold` stays at its default `1`, which is the
      *finished* state: a tidy ledger with every slip filed and every mark shown.
      Reduced motion gets the ending, never a disabled-looking middle.
    · **It is the only expensive animation on the page.** Nothing else here moves.

    The slips are illustrative sample data, so their figures are literals in this
    template rather than `money()` output — the same call the existing receipt makes.
    The one rule they still obey: Persian digits in prose, and Latin + `dir="ltr"` for
    the two things that are genuinely Latin identifiers (the intake number and a handset
    model), because a serial that reorders under bidi is a serial nobody can read back.
--}}
<section class="pitch" id="hero">
    {{-- The shared 46px hairline mesh, masked to fade out. Reused, not re-declared:
         it is the same surface the rest of the page is drawn on. --}}
    <div class="mesh" aria-hidden="true"></div>

    <div class="shell pitch__grid">
        <div class="pitch__say">
            <p class="pitch__kicker">
                <span class="pitch__rule" aria-hidden="true"></span>
                نرم‌افزار ابری فروشگاه موبایل
            </p>

            {{-- The accent lives in the largest type on the page and nowhere else in this
                 section. #0066cc on white is 5.6:1, so the loudest colour costs nothing. --}}
            <h1 class="pitch__title">دفترِ مغازه‌تان،<br><em>این‌بار هیچ‌چیز گم نمی‌شود.</em></h1>

            <p class="pitch__lede">
                گوشی را با IMEI می‌فروشید، قبض پذیرش را چاپ می‌کنید، قسط و چک را با سررسید
                ثبت می‌کنید و مشتری خودش پیامک تحویل می‌گیرد. آخر ماه هم سودِ واقعیِ هر
                فروش را می‌بینید، نه جمعِ فروش را.
            </p>

            <div class="pitch__actions">
                <a href="{{ route('register') }}" class="btn btn--primary btn--lg">
                    ۱۴ روز رایگان شروع کنید
                    @include('landing.icon', ['name' => 'arrow', 'size' => 16])
                </a>

                {{-- Points at the product tour, which is the only honest demo this page
                     has: there is no video, and autoplay video is out of budget. --}}
                <a href="#tour" class="btn btn--quiet btn--lg">دموی ۳ دقیقه‌ای</a>
            </div>

            {{-- «بدون کارت بانکی» is a qualifier, not part of the button label. Putting it
                 inside the target would make the one thing a visitor is meant to press
                 read as a paragraph. --}}
            <p class="pitch__fine">بدون کارت بانکی · بدون قرارداد · راه‌اندازی چند دقیقه‌ای</p>
        </div>

        {{-- A <figure> rather than a decorative div: every word inside is real, readable
             content that makes the argument, and a screen reader should get the four
             slips as a list with the caption that explains them. Only the tick marks are
             aria-hidden, because "ثبت شد" is already what the caption says. --}}
        <figure class="counter" data-counter>
            <div class="counter__head">
                <span class="counter__title">دفترِ مغازه</span>
                {{-- «امروز», not a printed date: a date literal in a template is stale the
                     day after it ships, and this panel has no reason to be. --}}
                <span class="counter__day">امروز</span>
            </div>

            <ol class="counter__list">
                <li class="counter__slip" style="--k:1.5;--dx:-0.9rem;--r:-3.6deg;--z:1">
                    <span class="counter__kind">قبض پذیرش</span>
                    <span class="counter__what">آیفون ۱۳ — تعویض گلس</span>
                    <span class="counter__meta">
                        <span class="nums" dir="ltr">REP-000184</span>
                        <span class="nums">۹:۴۰</span>
                    </span>
                    <span class="counter__tick" aria-hidden="true">
                        @include('landing.icon', ['name' => 'check', 'size' => 12])
                    </span>
                </li>

                <li class="counter__slip" style="--k:0.5;--dx:0.7rem;--r:2.4deg;--z:2">
                    <span class="counter__kind">فاکتور فروش</span>
                    <span class="counter__what">سامسونگ گلکسی <span dir="ltr">A54</span> — یک دستگاه</span>
                    <span class="counter__meta">
                        <b class="counter__amount nums">۱۸٬۹۰۰٬۰۰۰ تومان</b>
                        <span class="nums">۱۱:۰۵</span>
                    </span>
                    <span class="counter__tick" aria-hidden="true">
                        @include('landing.icon', ['name' => 'check', 'size' => 12])
                    </span>
                </li>

                <li class="counter__slip" style="--k:-0.5;--dx:-0.6rem;--r:-1.8deg;--z:3">
                    <span class="counter__kind">چک</span>
                    <span class="counter__what">بانک ملت — سررسید <span class="nums">۱۴۰۵/۰۷/۱۲</span></span>
                    <span class="counter__meta">
                        <b class="counter__amount nums">۱۲٬۰۰۰٬۰۰۰ تومان</b>
                        <span class="nums">۱۳:۱۵</span>
                    </span>
                    <span class="counter__tick" aria-hidden="true">
                        @include('landing.icon', ['name' => 'check', 'size' => 12])
                    </span>
                </li>

                <li class="counter__slip" style="--k:-1.5;--dx:0.9rem;--r:3.2deg;--z:4">
                    <span class="counter__kind">قسط</span>
                    <span class="counter__what">قسط <span class="nums">۳</span> از <span class="nums">۱۲</span> — سررسید امروز</span>
                    <span class="counter__meta">
                        <b class="counter__amount nums">۲٬۵۰۰٬۰۰۰ تومان</b>
                        <span class="nums">۱۶:۲۰</span>
                    </span>
                    <span class="counter__tick" aria-hidden="true">
                        @include('landing.icon', ['name' => 'check', 'size' => 12])
                    </span>
                </li>
            </ol>

            <figcaption class="counter__note">
                هر اتفاقی که پشت پیشخوان می‌افتد، همین‌جا یک سطر می‌شود — و سطرها آخر ماه
                جمع می‌شوند.
            </figcaption>
        </figure>
    </div>
</section>
