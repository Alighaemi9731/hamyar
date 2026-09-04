{{--
    ================================================================= IMEI ===

    Section 4 — «شناسنامهٔ IMEI». The one claim this product genuinely differs on, and
    the page's dark anchor: navy ground, with white above and below it *because* it is
    not (ADR 0016, Direction B revised).

    ## Why this is a console and not three cards

    The rejected version stated the three questions as three cards side by side. Three
    cards is a claim; a record you can query is evidence. So the visitor picks — or types
    — a serial, and the actual file for that handset builds in front of them: who it was
    bought from, when it was repaired, who it went to, and what was made on it.

    ## Not empty on arrival — the one rule ADR 0016 paid for twice

    Every record is rendered by Blade, all three of them, and the first one is *open*. The
    JavaScript only swaps which is visible. A visitor who arrives with JS blocked, broken,
    or still downloading sees a complete, finished record rather than an empty frame
    waiting for a script — which is exactly the failure the dark direction shipped.

    The cost of that choice is honest and small: with no JS the two other picker buttons
    are inert. A dead button is a worse than a dead animation, so they are real
    `<button type="button">` elements which do nothing rather than links to nowhere.

    ## What the interaction costs

    Nothing scroll-linked. No observer, no listener on scroll or resize — this section
    reacts to a click and a keystroke and is otherwise as static as printed paper. The
    page's scroll budget (one IntersectionObserver, one throttled scroll handler) is
    untouched by it.

    ## Copy

    The three sample records are seed-shaped fiction from a demo shop, not a customer's
    data. They are deliberately three *different* stories — a phone bought and sold, a
    trade-in repaired before resale, and one still sitting on a shelf in the second
    branch — because a shopkeeper recognises the third case as fast as the first.
--}}
@php
    /**
     * The three sample records.
     *
     * Rule on digits, applied here and not everywhere else on this page yet: prose and
     * money carry Persian digits; IMEI, and invoice/receipt/transfer numbers stay Latin
     * and are wrapped `dir="ltr"`. A serial read aloud to a supplier over the phone is a
     * Latin string, and rendering it in Persian digits makes it un-copyable.
     *
     * `state` is the record's own word for where the unit is, not a colour: a green
     * "sold" pill would be a second accent hue, which this page does not have.
     *
     * @var array<int, array{
     *     imei: string, name: string, state: string, events: array<int, array<string, string|bool|null>>,
     *     result: array{label: string, value: string, muted: bool}
     * }>
     */
    $devices = [
        [
            'imei' => '354879116234901',
            'name' => 'اپل آیفون ۱۳ — ۱۲۸ گیگ',
            'state' => 'فروخته شده',
            'events' => [
                [
                    'icon' => 'store',
                    'ask' => 'از که خریدم؟',
                    'title' => 'خرید از پخش موبایل ایرانیان',
                    'date' => '۱۴۰۵/۰۲/۱۱',
                    'doc' => 'PUR-00924',
                    'note' => 'همان روز با اسکن IMEI وارد انبار شد.',
                    'label' => 'بهای تمام‌شده',
                    'amount' => '۴۱٬۲۰۰٬۰۰۰ تومان',
                ],
                [
                    'icon' => 'trend',
                    'ask' => 'به که فروختم؟',
                    'title' => 'فروش به سمیرا احمدی',
                    'date' => '۱۴۰۵/۰۳/۰۴',
                    'doc' => 'INV-001873',
                    'note' => 'شش قسط ماهانه، با دو چک ضمانت.',
                    'label' => 'مبلغ فاکتور',
                    'amount' => '۴۴٬۹۰۰٬۰۰۰ تومان',
                ],
                [
                    'icon' => 'wrench',
                    'ask' => 'کِی تعمیر شد؟',
                    'title' => 'تعمیر: تعویض گلس',
                    'date' => '۱۴۰۵/۰۵/۲۹',
                    'doc' => 'REP-000184',
                    'note' => 'شش ماه بعد از فروش، خارج از گارانتی؛ هزینه از مشتری گرفته شد.',
                    'label' => 'اجرت تعمیر',
                    'amount' => '۴۲۰٬۰۰۰ تومان',
                ],
            ],
            'result' => ['label' => 'سود این دستگاه', 'value' => '۳٬۷۰۰٬۰۰۰ تومان', 'muted' => false],
        ],
        [
            'imei' => '356938035643809',
            'name' => 'سامسونگ گلکسی A54',
            'state' => 'فروخته شده',
            'events' => [
                [
                    'icon' => 'store',
                    'ask' => 'از که خریدم؟',
                    'title' => 'معاوضه از مرتضی کاظمی',
                    'date' => '۱۴۰۵/۰۴/۱۸',
                    'doc' => 'INV-001902',
                    'note' => 'به‌عنوان معاوضه، پای فاکتور فروش یک گوشی دیگر تحویل گرفته شد.',
                    'label' => 'بهای تمام‌شده',
                    'amount' => '۱۴٬۳۰۰٬۰۰۰ تومان',
                ],
                [
                    'icon' => 'wrench',
                    'ask' => 'کِی تعمیر شد؟',
                    'title' => 'تعویض باتری، پیش از فروش',
                    'date' => '۱۴۰۵/۰۴/۲۱',
                    'doc' => 'REP-000171',
                    'note' => 'باتری از انبار کم شد و هزینه‌اش روی بهای تمام‌شدهٔ همین دستگاه نشست.',
                    'label' => 'هزینهٔ قطعه',
                    'amount' => '۹۸۰٬۰۰۰ تومان',
                ],
                [
                    'icon' => 'trend',
                    'ask' => 'به که فروختم؟',
                    'title' => 'فروش به فاطمه رستمی',
                    'date' => '۱۴۰۵/۰۵/۰۹',
                    'doc' => 'INV-001955',
                    'note' => 'نقدی، با کارتخوان فروشگاه.',
                    'label' => 'مبلغ فاکتور',
                    'amount' => '۱۷٬۵۰۰٬۰۰۰ تومان',
                ],
            ],
            'result' => ['label' => 'سود این دستگاه', 'value' => '۲٬۲۲۰٬۰۰۰ تومان', 'muted' => false],
        ],
        [
            'imei' => '861234037654321',
            'name' => 'شیائومی ردمی نوت ۱۲',
            'state' => 'موجود در انبار',
            'events' => [
                [
                    'icon' => 'store',
                    'ask' => 'از که خریدم؟',
                    'title' => 'خرید از پخش موبایل ایرانیان',
                    'date' => '۱۴۰۵/۰۵/۰۲',
                    'doc' => 'PUR-01037',
                    'note' => 'یکی از هفت دستگاه همان فاکتور؛ هرکدام سطر خودش را دارد.',
                    'label' => 'بهای تمام‌شده',
                    'amount' => '۹٬۶۵۰٬۰۰۰ تومان',
                ],
                [
                    'icon' => 'arrow',
                    'ask' => 'الان کجاست؟',
                    'title' => 'حواله از انبار مرکزی به شعبهٔ ۲',
                    'date' => '۱۴۰۵/۰۵/۲۰',
                    'doc' => 'TRF-000318',
                    'note' => 'همان دستگاه، همان شناسه، انبار دیگر — نه یک ردیف جدید.',
                    'label' => null,
                    'amount' => null,
                ],
                [
                    'icon' => 'calendar',
                    'ask' => 'به که فروختم؟',
                    'title' => 'هنوز فروخته نشده',
                    'date' => null,
                    'doc' => null,
                    'note' => 'روی رَف شعبهٔ ۲ است. تا وقتی نرود، این سطر خالی می‌ماند.',
                    'label' => null,
                    'amount' => null,
                    'pending' => true,
                ],
            ],
            'result' => ['label' => 'سود این دستگاه', 'value' => 'بعد از فروش', 'muted' => true],
        ],
    ];

    /**
     * The serial in the masthead is the FIRST record's, not a fourth invented number:
     * the head names the subject, the input below is placeheld with it, and the record
     * open on arrival is the one it belongs to. Three places, one handset.
     */
    $lead = $devices[0]['imei'];
@endphp

<section class="sec band imei" id="imei" aria-labelledby="imei-title">

    <div class="shell">
        {{--
            NOT a masthead — a serial-first lockup, and section 4 is the only place on the
            page that opens this way. The section names its subject before it says a word:
            a real IMEI, Latin and `dir="ltr"` because a serial that reorders under bidi is
            a serial nobody can read back, set in accent-lit above a single-column H2.
            Nothing sits opposite it.

            The asymmetric title/lede-plus-second-column head this replaces was the same
            shape six of the eight sections had arrived with, which is the template the
            owner was pointing at. The three questions that used to stack in that second
            column have moved down into the record, where they are the timeline's row
            labels («از که خریدم؟» / «به که فروختم؟» / «کِی تعمیر شد؟») — beside the
            answer instead of above it, and stated once instead of twice.

            No `.rise` here: the section head never rises (landing.css, THE RULE).
        --}}
        <header class="imei-head">
            <p class="imei-serial nums" dir="ltr">{{ $lead }}</p>

            <h2 class="imei-title" id="imei-title">این شناسه را بزنید،<br>بقیه‌اش پیداست.</h2>

            <p class="imei-lede">
                گوشی در همیار «تعداد» نیست؛ هر دستگاه یک سطر با شناسهٔ خودش است.
                هر خرید، تعمیر، حواله و فروشی که رویش ثبت شود زیر همان شناسه می‌ماند —
                حتی اگر دو سال بعد سراغش را بگیرید.
            </p>
        </header>

        {{-- No `.rise` on this container either. THE RULE puts the section's one entry
             animation at the level where the content is a list of peers, and in this
             section that is the three sample files in `.imei-devices` — NOT the timeline
             rows, which spend most of their life inside a `hidden` panel where an
             IntersectionObserver can never reach them and would leave them permanently
             invisible the moment a visitor picked the second handset. --}}
        <div class="imei-console">
            {{-- The query side. --}}
            <div class="imei-pick">
                <label class="imei-pick__label" for="imei-input">شناسهٔ دستگاه را وارد کنید</label>

                <div class="imei-field">
                    <input class="imei-field__input nums" id="imei-input" type="text"
                           inputmode="numeric" autocomplete="off" spellcheck="false"
                           maxlength="24" dir="ltr" placeholder="{{ $lead }}"
                           aria-describedby="imei-hint" data-imei-input>
                    <span class="imei-field__mark" aria-hidden="true">
                        @include('landing.icon', ['name' => 'scan', 'size' => 20])
                    </span>
                </div>

                <p class="imei-hint" id="imei-hint">
                    سه پروندهٔ نمونه از یک فروشگاه آزمایشی. یکی را انتخاب کنید، یا شناسه را
                    رقم‌به‌رقم تایپ کنید.
                </p>

                {{-- `role="list"`: Safari + VoiceOver drop list semantics from a list
                     styled `list-style: none`, and this is one. --}}
                <ul class="imei-devices" role="list" data-imei-devices>
                    @foreach ($devices as $device)
                        {{-- The section's one `.rise` level: a list of peers, always in
                             the document and never hidden, so the observer can always
                             finish what it starts. `--i` is written per-parent by
                             landing.js. --}}
                        <li class="rise">
                            <button type="button" class="imei-device"
                                    data-imei-pick="{{ $device['imei'] }}"
                                    aria-pressed="{{ $loop->first ? 'true' : 'false' }}">
                                <span class="imei-device__name">{{ $device['name'] }}</span>
                                <span class="imei-device__no nums" dir="ltr">{{ $device['imei'] }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>

            {{--
                The record side. All three are in the document; `hidden` on two of them.
                `.imei-record[hidden]` is restated in the stylesheet because the element
                carries `display:grid`, which would otherwise beat the UA's [hidden] rule.
            --}}
            <div class="imei-stage" data-imei-stage>
                @foreach ($devices as $device)
                    <article class="imei-record" data-imei-panel
                             data-imei="{{ $device['imei'] }}"
                             data-imei-name="{{ $device['name'] }}"
                             @unless ($loop->first) hidden @endunless>
                        <header class="imei-record__head">
                            <div>
                                <h3 class="imei-record__name">{{ $device['name'] }}</h3>
                                <p class="imei-record__no nums" dir="ltr">{{ $device['imei'] }}</p>
                            </div>
                            <span class="imei-state">{{ $device['state'] }}</span>
                        </header>

                        {{-- `role="list"` for the same reason, and the `--i` below is
                             the stylesheet's own row delay on a panel swap, not the
                             entry stagger — these rows carry no `.rise`. --}}
                        <ol class="imei-track" role="list">
                            @foreach ($device['events'] as $event)
                                <li class="imei-ev" style="--i:{{ $loop->index }}"
                                    @if ($event['pending'] ?? false) data-pending @endif>
                                    <span class="imei-ev__mark" aria-hidden="true">
                                        @include('landing.icon', ['name' => $event['icon'], 'size' => 15])
                                    </span>

                                    <p class="imei-ev__ask">{{ $event['ask'] }}</p>
                                    <b class="imei-ev__title">{{ $event['title'] }}</b>

                                    @if ($event['date'] || $event['doc'])
                                        <p class="imei-ev__meta">
                                            @if ($event['date'])
                                                <span class="nums">{{ $event['date'] }}</span>
                                            @endif
                                            @if ($event['doc'])
                                                <span class="imei-ev__doc nums" dir="ltr">{{ $event['doc'] }}</span>
                                            @endif
                                        </p>
                                    @endif

                                    <p class="imei-ev__note">{{ $event['note'] }}</p>

                                    @if ($event['amount'])
                                        <p class="imei-ev__amt">
                                            <span>{{ $event['label'] }}</span>
                                            <b class="nums">{{ $event['amount'] }}</b>
                                        </p>
                                    @endif
                                </li>
                            @endforeach
                        </ol>

                        <footer class="imei-result" @if ($device['result']['muted']) data-muted @endif>
                            <span>{{ $device['result']['label'] }}</span>
                            <b class="nums">{{ $device['result']['value'] }}</b>
                        </footer>
                    </article>
                @endforeach

                {{-- Typed digits that match no sample. A dead end is a bad answer, so this
                     one says what the visitor would actually get in their own account. --}}
                <p class="imei-miss" data-imei-miss hidden>
                    این شناسه بین سه نمونهٔ بالا نیست.
                    <span>در حساب خودتان، هر دستگاهی که ثبت کرده باشید همین صفحه را می‌سازد.</span>
                </p>

                <p class="imei-say" role="status" aria-live="polite" data-imei-say></p>
            </div>
        </div>

        {{--
            The HAMTA caveat belongs beside the claim it qualifies — a shopkeeper who
            believes we register in HAMTA and finds out later has been mis-sold. But the
            FAQ answers the same question at length, and the two arrived as near-identical
            paragraphs opening with the same word, 120 words apart, on the page's most
            negative message. One line here, the full answer there.
        --}}
        <p class="imei-honesty">
            همتا API عمومی ندارد، پس ثبت نهایی را خودتان انجام می‌دهید — همیار وضعیت هر
            دستگاه را نگه می‌دارد و یادآوری می‌کند.
        </p>
    </div>
</section>
