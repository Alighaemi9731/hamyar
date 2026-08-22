{{--
    Section 7 — سؤالات پرتکرار. Six questions, per the owner's brief.

    ## These are purchase objections, not trivia

    Every question here is one a shopkeeper asks before he pays, and two of them are ones
    a marketing page would rather not print: what happens with همتا (nothing automatic —
    it has no public API), and what happens when the subscription lapses. Answering those
    plainly is worth more than a sixth feature claim, and it is what «حرفه‌ای» means to a
    merchant who has been sold software before.

    ## This section has no head above its list — the aside IS the head

    Five of the eight sections were opening the same way: a title on one side, a
    supporting sentence on the other, baseline-aligned above the content. Two keep that
    shape because it is load-bearing there (§3, where the head is the ledger's first row;
    §6, where the far side is the billing control). This is not one of them.

    So nothing sits above the questions at all. The H2, the lede and the contact line are
    the aside COLUMN, set beside the six questions and travelling with them while they
    scroll — a reader four questions deep can still see what he is reading and where to
    write if his question is not there. That is a use no banner has: a centred head is
    gone from the screen by question two.

    The rejected page also had a stack of rounded cards with a chevron in a circle. Here
    the questions are a hairline list, and the marker is a bare plus with no disc around
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

    /*
     | No ordinals. A <details> list does not need to be counted, and the page already
     | carries a numbering device in §3 — see the note in tour.blade.php.
     */
@endphp

<section class="sec sec--alt" id="faq" aria-labelledby="qa-title">
    <div class="shell qa__grid">

        {{-- Not a masthead: this column IS the section's opening, standing beside the
             list rather than above it. It carries no `.rise` — a heading that fades in
             is a heading that is missing when the reader looks for it, and the section's
             entry motion belongs to the questions, which are the peers here. --}}
        <div class="qa__aside">
            <h2 class="qa__title" id="qa-title">قبل از اینکه بپرسید</h2>
            <p class="qa__lede">
                شش سؤالی که هر مغازه‌داری پیش از خرید می‌پرسد — با جواب رُک، حتی آنجا که
                جوابش «نه» است.
            </p>
            <p class="qa__help">
                سؤالتان اینجا نبود؟ بنویسید
                <a href="mailto:{{ $contactEmail }}" dir="ltr">{{ $contactEmail }}</a>
            </p>
        </div>

        {{-- `.rise` goes on the questions, one level, and on nothing above or below
             them: not this container, not the aside, not the summary inside. Stagger is
             `min(--i, 3) × 60ms` with `--i` written per-parent by `landing.js`, so the
             sixth question does not arrive a full half-second after the first. --}}
        <div class="qa__list" data-faq>
            @foreach ($questions as $i => [$question, $answer])
                <details class="qa__item rise" @if ($i === 0) open @endif>
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
