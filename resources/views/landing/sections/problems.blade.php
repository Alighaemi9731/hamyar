{{--
    Section 3 — مسئله ← راه‌حل.

    Six things a shop owner already complains about, each with the one thing this
    product does about it. Included by `landing.blade.php`; it renders nothing on its
    own and owns no page chrome.

    ## Why this is a ledger and not six cards

    Six equal rounded cards, each opening with an icon in a circle, is the shape a
    generated landing page reaches for — and it is on the list of tells the third
    rejection named. So this is drawn as a printed ledger instead: hairline rules, a
    numbered margin, and one vertical rule that every answer hangs off. It reads as a
    document, which is what the product is. That is the same argument ADR 0016 used
    against the cinematic direction: the page should feel like the tool.

    ## The head sits ON the list's grid, not centred above it

    A centred eyebrow over a centred H2 is the signature tell, and it appears above
    every section of the page being replaced. Here the heading occupies the complaint
    column and the lede occupies the answer column of the very same two-column grid the
    rows use — so the head reads as the list's first row, and the section's argument
    («این مسئله ← این جواب») is stated by the composition before a word is read.

    ## The copy

    Written for somebody standing behind a counter with a customer waiting: their own
    nouns (قبض پذیرش، همکار، سررسید، حواله، بهای تمام‌شده)، their own sentences. The
    complaint half is quoted because it is meant to be heard in their voice; the answer
    half never uses a verb the shopkeeper would not use.

    Motion: none of its own. Each row carries the page's shared `.rise`, so the six
    arrive in sequence on the existing IntersectionObserver and reduced motion is
    already handled by `landing.css`.
--}}
@php
    /**
     * The six rows, in the order the owner set them.
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: string}>
     *      [margin numeral, the complaint, the answer's name, what it actually does]
     *
     * The numerals are authored, not computed: they are Persian glyphs in content, and
     * a `$loop->iteration` run through a digit helper would only be a slower way of
     * writing the same six characters.
     */
    $problems = [
        [
            '۰۱',
            '«کدام گوشی را از کی خریدم و به کی فروختم؟»',
            'شناسنامهٔ IMEI',
            'هر دستگاه یک سطر جداست، نه یک عدد در موجودی. روی همان سطر می‌بینید از کدام همکار خریده‌اید و با چه قیمتی، به کدام مشتری فروخته‌اید، و کِی برای تعمیر برگشته.',
        ],
        [
            '۰۲',
            '«قبض پذیرش دست‌نویس، و مشتری‌ای که هر روز زنگ می‌زند»',
            'پذیرش تعمیر با رهگیری آنلاین',
            'قبض پذیرش با یک بارکد QR چاپ می‌شود. مشتری همان را می‌زند و وضعیت دستگاهش را خودش می‌بیند؛ تلفن مغازه برای فروش آزاد می‌ماند.',
        ],
        [
            '۰۳',
            '«دفترچهٔ اقساط و چک‌های توی کشو»',
            'اقساط و چک، روی یک میز وصول',
            'هر قسط و هر چک سررسید دارد. صبح که مغازه را باز می‌کنید معلوم است امروز از چه کسی باید بگیرید، چه کسی عقب افتاده، و چقدر هنوز وصول نشده.',
        ],
        [
            '۰۴',
            '«یادم رفت خبر بدهم گوشی‌اش آماده است»',
            'پیامک خودکار',
            'تا وضعیت تعمیر «آمادهٔ تحویل» می‌شود، پیامکش رفته. یادآوری قسط و سررسید چک هم همین‌طور — از روی اتفاق‌های خود سیستم، نه از روی فهرستی که باید یادتان بماند.',
        ],
        [
            '۰۵',
            '«دو شعبه، یک انبار، و تلفنی که مدام می‌پرسد هست یا نه»',
            'چندشعبه و حوالهٔ بین شعب',
            'موجودی هر شعبه جداست و از هر شعبه دیده می‌شود. جابه‌جایی دستگاه بین شعبه‌ها حواله می‌خورد، پس هیچ گوشی‌ای بین راه گم نمی‌شود.',
        ],
        [
            '۰۶',
            '«آخر ماه نمی‌دانم سود کردم یا نه»',
            'گزارش سود، نه فقط فروش',
            'بهای تمام‌شده در همان لحظهٔ فروش ثبت می‌شود، پس سود هر فاکتور و هر دستگاه همان چیزی است که واقعاً به دست آورده‌اید — نه تفاضل قیمت امروز.',
        ],
    ];
@endphp

<section class="sec probs" id="problems" aria-labelledby="probs-title">
    <div class="shell">

        {{-- The head. Two columns, on the same grid as a row: title where the
             complaints go, lede where the answers go. --}}
        <header class="probs__head rise">
            <div>
                <p class="chip"><span class="chip__dot" aria-hidden="true"></span>پشت پیشخوان</p>
                <h2 class="sec__title probs__title" id="probs-title">
                    شش گرفتاری که هر مغازه‌دار می‌شناسد.<br>
                    <em>و کاری که موبایل‌یار با هرکدام می‌کند.</em>
                </h2>
            </div>

            <div class="probs__intro">
                <p class="sec__lede">
                    هیچ‌کدام از این‌ها از فهرست امکانات درنیامده؛ کارهایی است که یا وقت مغازه را
                    می‌گیرد یا پولش را. جلوی هرکدام نوشته‌ایم موبایل‌یار دقیقاً چه می‌کند.
                </p>
                <p class="probs__note">
                    اگر هیچ‌کدام برایتان آشنا نبود، احتمالاً هنوز به این نرم‌افزار احتیاجی ندارید.
                </p>
            </div>
        </header>

        {{-- An ordered list, and ordered on purpose: the margin numerals are
             `aria-hidden` decoration, so the numbering a screen reader announces is the
             list's own. `role="list"` is restated because `list-style: none` drops list
             semantics in Safari/VoiceOver. --}}
        <ol class="probs__list" role="list">
            @foreach ($problems as [$n, $pain, $fix, $body])
                <li class="probs__row rise">
                    <div class="probs__ask">
                        <span class="probs__n nums" aria-hidden="true">{{ $n }}</span>
                        <p class="probs__pain">{{ $pain }}</p>
                    </div>

                    {{-- The answer hangs off a 2px accent rule — the section's only
                         piece of colour, and the thing that makes «مسئله ← راه‌حل»
                         legible when the row stacks on a phone and the two halves are
                         no longer side by side. --}}
                    <div class="probs__fix">
                        <h3 class="probs__fix-title">
                            @include('landing.icon', ['name' => 'arrow', 'size' => 18])
                            {{ $fix }}
                        </h3>
                        <p class="probs__fix-body">{{ $body }}</p>
                    </div>
                </li>
            @endforeach
        </ol>

    </div>
</section>
