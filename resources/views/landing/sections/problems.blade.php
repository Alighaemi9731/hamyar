{{--
    Section 3 — the problems, as a card grid.

    ## What this replaces

    A six-row ledger: a quoted complaint in one column, the answer in the other, hairline
    between, numbered ۰۱–۰۶ in the margin, each row fading in on scroll. It was 1,546px
    tall, it was the densest block on the page, and its copy was written in the
    colloquial register the gate retired — «توی کشو», «مغازه», «رُک بگوییم» — three of
    which are still on `bin/.copy-terms-baseline` because this file carried them.

    ADR 0021 takes the reference's shape for exactly this section, which it also has:
    a centred head, then a two-column card grid with a warm-tinted icon tile per card.
    The complaint moves into the card's heading and the answer into its body, so the
    same six facts read in half the height and in one pass rather than six.

    ## The copy

    Same six problems, in the professional register `docs/brand/voice.md` sets: the
    shopkeeper's own nouns (قبض پذیرش، همکار، سررسید، حواله، بهای تمام‌شده) without the
    slang. Each answer names what the software does, not how it feels.

    The numerals are gone with the rows. Numbered markers encode a sequence, and these
    six are not one — they are six independent faults, and numbering them was the
    decorative use of structure the craft rules warn about.
--}}
@php
    /** @var array<int, array{title: string, body: string, icon: string}> */
    $problems = [
        [
            'title' => 'ردیابی IMEI و شمارهٔ سریال',
            'body' => 'گوشی‌ها در موجودی «تعداد» می‌شوند و معلوم نیست کدام دستگاه از کدام همکار آمده و به کدام مشتری رفته. در همیار هر دستگاه یک سطر با شناسهٔ خودش است.',
            'icon' => 'smartphone',
        ],
        [
            'title' => 'قبض پذیرش دست‌نویس',
            'body' => 'قبض کاغذی گم می‌شود و مشتری برای خبر گرفتن زنگ می‌زند. قبض پذیرش با بارکد QR چاپ می‌شود و مشتری وضعیت دستگاهش را خودش می‌بیند.',
            'icon' => 'wrench',
        ],
        [
            'title' => 'اقساط و چک در دفترچه',
            'body' => 'سررسیدها در دفترچه و کشو می‌مانند تا روزی که دیر شده باشد. میز وصول هر روز می‌گوید چه کسی باید بیاید، چه کسی عقب افتاده و چقدر وصول نشده.',
            'icon' => 'calendar',
        ],
        [
            'title' => 'سود واقعی معلوم نیست',
            'body' => 'وقتی بهای خرید هر دستگاه جایی ثبت نشده، سود آخر ماه یک تخمین است. بهای تمام‌شده در لحظهٔ فروش ثبت می‌شود و سود هر فاکتور همان‌جا معلوم است.',
            'icon' => 'chart',
        ],
        [
            'title' => 'چند شعبه، چند حساب',
            'body' => 'موجودی هر شعبه جدا شمرده می‌شود و حواله بین آن‌ها ثبتی ندارد. انبار هر شعبه و حواله‌های بین شعبه‌ها در یک جا و روی یک موجودی است.',
            'icon' => 'transfer',
        ],
        [
            'title' => 'خبردادن به مشتری، دستی',
            'body' => 'یادآوری قسط و خبر آماده‌شدن دستگاه یادتان می‌رود یا وقت می‌گیرد. پیامک از روی رویدادهای خود سیستم فرستاده می‌شود.',
            'icon' => 'message',
        ],
    ];

    $icons = [
        'smartphone' => '<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/>',
        'wrench' => '<path d="M14.7 6.3a4 4 0 0 1-5 5L4 17v3h3l5.7-5.7a4 4 0 0 0 5-5z"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'chart' => '<path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/>',
        'transfer' => '<path d="M3 8h14l-4-4M21 16H7l4 4"/>',
        'message' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    ];
@endphp
<section class="sec sec--alt" id="problems" aria-labelledby="problems-title">
    <div class="shell">
        <div class="sec__head">
            <p class="sec__eyebrow">مسئله</p>
            <h2 class="sec__title" id="problems-title">
                شش گرفتاری که هر فروشندهٔ موبایل <em>می‌شناسد</em>
            </h2>
            <span class="sec__rule" aria-hidden="true"></span>
            <p class="sec__lede">
                هیچ‌کدام از این‌ها از فهرست امکانات درنیامده؛ کارهایی است که یا وقت فروشگاه
                را می‌گیرد یا پولش را. جلوی هرکدام نوشته‌ایم همیار دقیقاً چه می‌کند.
            </p>
        </div>

        {{-- `role="list"` is not redundant: Safari + VoiceOver drop list semantics from a
             <ul> whose `list-style` is `none`, and this is one. --}}
        <ul class="cards" role="list">
            @foreach ($problems as $problem)
                <li class="card">
                    <span class="card__icon card__icon--warm" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round">
                            {!! $icons[$problem['icon']] !!}
                        </svg>
                    </span>
                    <h3>{{ $problem['title'] }}</h3>
                    <p>{{ $problem['body'] }}</p>
                </li>
            @endforeach
        </ul>
    </div>
</section>
