{{--
    Section 7 — سؤالات پرتکرار. Six questions, per the owner's brief.

    ## These are purchase objections, not trivia

    Every question here is one a shopkeeper asks before he pays, and two of them are ones
    a marketing page would rather not print: what happens with همتا (nothing automatic —
    it has no public API), and what happens when the subscription lapses. Answering those
    plainly is worth more than a sixth feature claim, and it is what «حرفه‌ای» means to a
    merchant who has been sold software before.

    ## Why it does not look like the accordion it replaces

    The rejected page had a centred eyebrow, a centred H2 and a stack of rounded cards
    with a chevron in a circle. Here the heading sits beside the questions in its own
    column and stays with them while they scroll, the questions are a numbered hairline
    list rather than a stack of cards, and the marker is a bare plus with no disc around
    it — circled icons are on the owner's list of tells.

    `<details>`/`<summary>` is kept because it is keyboard-operable and screen-reader
    correct with no script at all. `landing.js` finds `[data-faq]` and enforces
    one-open-at-a-time, which is a preference; with the script absent every question
    still opens. The first is open on arrival so the section is never a wall of shut
    doors — the same lesson ADR 0016 records about the signature element not being empty
    when you get there.
--}}
@php
    /**
     * Never a hostname literal — golden rule 1b, and `bin/check-apex-domain` scans this
     * directory. The mailbox name is ours; the host is whatever `config('app.domain')`
     * says it is, which is the only reason the same template renders correctly on the
     * local stack and in production without an edit.
     */
    $contactEmail = 'info@'.config()->string('app.domain');

    /**
     * [question, answer] — six, in the order the owner listed them.
     *
     * Kept as data rather than six copies of the same markup so a seventh question is a
     * line here instead of a block below, and so the numbering cannot drift from the
     * order on screen.
     */
    $questions = [
        [
            'با سامانهٔ همتا چه می‌کند؟',
            'رُک بگوییم: همتا API عمومی ندارد، پس هیچ نرم‌افزاری — از جمله ما — نمی‌تواند مستقیم در آن ثبت کند و هر کس خلافش را بگوید دارد چیزی می‌فروشد که ندارد. کاری که موبایل‌یار می‌کند این است: وضعیت همتای هر IMEI را کنار خود دستگاه نگه می‌دارد، دستگاه‌های ثبت‌نشده را یادآوری می‌کند و مرحله‌های کار را نشان می‌دهد. ثبت نهایی را خودتان در سامانه انجام می‌دهید.',
        ],
        [
            'سامانهٔ مودیان چطور؟',
            'ماژول مودیان صورتحساب‌ها را با همان قالبی که سامانه می‌خواهد آماده می‌کند و صف ارسال دارد. ارسال واقعی از راه همان شرکت معتمدی انجام می‌شود که خودتان با آن قرارداد دارید؛ شناسه و کلید حافظهٔ مالیاتی را یک بار در تنظیمات وارد می‌کنید و بعد از آن کاری ندارید.',
        ],
        [
            'از نرم‌افزار قبلی‌ام می‌توانم بیایم؟',
            'بله، با فایل اکسل: فهرست کالاها، مشتری‌ها و مانده‌حساب‌ها. قبل از ثبت نهایی یک پیش‌نمایش می‌بینید و ستون‌ها را خودتان تطبیق می‌دهید، پس هیچ چیز کورکورانه وارد نمی‌شود و یک فایل به‌هم‌ریخته، انبارتان را به هم نمی‌ریزد.',
        ],
        [
            'داده‌های من مال کیست؟',
            'مال شما. هر وقت بخواهید از همه‌چیز خروجی اکسل می‌گیرید — کالا، فاکتور، مشتری، چک و قسط — و برای بردن‌شان لازم نیست از کسی اجازه بگیرید. اطلاعات هر فروشگاه هم از بقیه جداست، و این جداسازی در خودِ پایگاه داده اعمال می‌شود، نه فقط در نرم‌افزار.',
        ],
        [
            'پشتیبانی چطور است؟',
            'وارد کردن فهرست کالاها از فایل اکسل خودتان است و راهنمای مرحله‌به‌مرحله دارد؛ اگر جایی گیر کردید کمک می‌کنیم. پشتیبانی از داخل خود نرم‌افزار و با ایمیل است و کسی جواب می‌دهد که نرم‌افزار را می‌شناسد.',
        ],
        [
            'اگر اشتراکم تمام شود چه می‌شود؟',
            'داده‌هایتان پاک نمی‌شود و سر جای خودش می‌ماند — ولی تا وقتی تمدید نکنید، ورود به حساب بسته است. پس قبل از سررسید خروجی اکسل بگیرید؛ ما هم از یک هفته قبل یادآوری می‌کنیم. هر وقت تمدید کنید همه‌چیز دقیقاً همان‌جاست که گذاشته بودید.',
        ],
    ];

    {{-- No ordinals. A <details> list does not need to be counted, and the page already
         carries a numbering device in §3 — see the note in tour.blade.php. --}}
@endphp

<section class="sec sec--alt" id="faq">
    <div class="shell qa__grid">

        {{-- The heading is a column, not a centred banner, and it stays with the questions
             on a desktop so the reader always knows what he is reading. --}}
        <div class="qa__aside rise">
            <h2 class="qa__title">قبل از اینکه بپرسید</h2>
            <p class="qa__lede">
                شش سؤالی که هر مغازه‌داری پیش از خرید می‌پرسد — با جواب رُک، حتی آنجا که
                جوابش «نه» است.
            </p>
            <p class="qa__help">
                سؤالتان اینجا نبود؟ بنویسید
                <a href="mailto:{{ $contactEmail }}" dir="ltr">{{ $contactEmail }}</a>
            </p>
        </div>

        <div class="qa__list rise" data-faq>
            @foreach ($questions as $i => [$question, $answer])
                <details class="qa__item" @if ($i === 0) open @endif>
                    <summary class="qa__q">
                        <span>{{ $question }}</span>
                        <span class="qa__mark" aria-hidden="true"></span>
                    </summary>
                    <p class="qa__a">{{ $answer }}</p>
                </details>
            @endforeach
        </div>

    </div>
</section>
