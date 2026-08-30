{{--
    Section 6 — تعرفه‌ها.

    ## Why this is a ladder of rows and not three cards

    Three cards with the middle one filled dark and badged "most popular" is the single
    most generated shape on the web, and it is one of the seven tells the owner named
    when he said the page was «معلومه کلاد کد زده». It also happens to be a bad fit for
    what a shopkeeper is doing here: he is not admiring three products side by side, he
    is running his finger down a price list looking for the line that matches his shop.

    So the plans are ROWS in one bordered sheet, stacked, with the prices aligned on a
    common edge so they can be compared by eye. The bigger plan's row is taller because
    it genuinely contains more — the ladder shape is the argument, not decoration.

    The recommended plan is marked with a tinted ground and a 4px accent rail on its
    inline-start edge, not by being the dark one. ADR 0016 is right that a 1px accent
    border was invisible; a filled ground and a rail are visible without importing the
    shape the owner rejected.

    ## Prices come from the database

    `$plans` and `$metrics` are supplied by Platform\Http\Controllers\LandingController.
    Roadmap 11.4 promises a price change at launch is "a panel edit, not a deploy", and
    a number typed into this file would break that promise on the one page a prospect
    actually reads. Every figure below is `money()` over a column.

    ## JavaScript contract (landing.js — do not change it to suit this file)

    · `[data-plan-toggle]` wraps `button[data-interval="month|year"]`.
    · Every element carrying `data-monthly` / `data-yearly` has its textContent swapped.
    · Every element carrying `data-unit` swaps between `data-unit-month`/`data-unit-year`.
    · `[data-saving]` is unhidden on yearly. `querySelector` — exactly one on the page.

    With the script absent the section renders the monthly price and every link works;
    only the toggle goes inert, which is why it is a real `<button>` and not a checkbox
    pretending to be one.

    ## What the rows show, since DECISION GATE 6

    Not modules — every plan opens every module now. What a plan sells is **how much work
    a shop may record in a Jalali month**, so each row lists its headline monthly credits.
    That also makes the ladder shape truer than it was: the taller row genuinely contains
    more, because it is more of the same thing rather than a longer list of names.

    The add-on shelf that used to sit under the ladder is gone with the product it
    advertised.

    @param \Illuminate\Support\Collection<int, \App\Modules\Platform\Models\Plan> $plans
    @param list<\App\Support\Quota\Metric>                                        $metrics
--}}
@php
    use App\Support\Digits;
    use App\Support\Money;

    /**
     * Yearly = twelve months for the price of ten. Declared here rather than in the page
     * template so this partial is self-contained, and stated on screen in both the toggle
     * note and every row — never implied by a smaller number appearing.
     */
    $yearFactor = 10;
    $monthsFree = Digits::toPersian((string) (12 - $yearFactor));

    /**
     * The credits a shopkeeper actually compares plans by. Anything else is true and
     * makes the row a specification sheet rather than an argument.
     */
    $headline = ['sales.invoices', 'inventory.units', 'repairs.tickets', 'catalog.products', 'crm.parties', 'messaging.sms'];

    $shown = collect($metrics)->filter(fn ($metric) => in_array($metric->key, $headline, true))
        ->sortBy(fn ($metric) => array_search($metric->key, $headline, true));
@endphp

<section class="sec" id="pricing" aria-labelledby="tariff-title">
    <div class="shell">

        {{-- The head is asymmetric on purpose, and it is one of only two mastheads left
             on the page — §3's, and this one. The test the other five failed: is the far
             column doing work no other arrangement could do? Here it is. This is the one
             head on the page whose far side is a CONTROL rather than a second sentence,
             and the control sits beside the numbers it changes, where a shopkeeper's eye
             already is when he wonders what a year costs. The two survivors are three
             sections apart so they never read as a repeated template.

             No `.rise` on the head — the section's entry motion lives on the plan rows
             and nowhere else. --}}
        <header class="tariff__head">
            <div>
                <h2 class="tariff__title" id="tariff-title">قیمت همینی است که می‌بینید</h2>
                <p class="tariff__lede">
                    همهٔ امکانات در همهٔ پلن‌ها باز است؛ فقط سهمیهٔ ماهانه فرق می‌کند. پلن پایه
                    رایگان است و کارت بانکی نمی‌خواهد. هر ماه می‌توانید پلن را بالا و پایین
                    ببرید یا قطع کنید — قرارداد سالانه و جریمهٔ فسخ نداریم.
                </p>
            </div>

            <div class="tariff__billing">
                <div class="tariff__switch" data-plan-toggle role="group" aria-label="دورهٔ پرداخت">
                    <button type="button" data-interval="month" aria-pressed="true">ماهانه</button>
                    <button type="button" data-interval="year" aria-pressed="false">سالانه</button>
                </div>
                <p class="tariff__saving" data-saving hidden>
                    ۱۲ ماه به قیمت ۱۰ ماه — {{ $monthsFree }} ماه رایگان.
                </p>
            </div>
        </header>

        {{-- `.rise` is on the plan ROWS, not on this sheet. The whole sheet carrying it
             made the section arrive as one slab — a single 40px lift of a 600px-tall
             object, which is the heaviest piece of motion on the page and says nothing,
             because a slab has no internal order to reveal. The rows are the peers, so
             the rows are the level: three of them, 60ms apart, and the reader's eye
             travels down the ladder in the direction he is about to read it. --}}
        <div class="tariff__list">
            @foreach ($plans as $plan)
                @php($recommended = $plan->code === 'pro')

                <article class="tariff__plan rise" data-recommended="{{ $recommended ? 'true' : 'false' }}">
                    <div class="tariff__id">
                        {{-- The owner's own words for this mark, from the brief. Navy fill,
                             white label: 18.1:1, and it does not need the accent. --}}
                        @if ($recommended)
                            <span class="tariff__pick">پیشنهاد ما</span>
                        @endif

                        <h3 class="tariff__name">{{ $plan->name_fa }}</h3>
                        <p class="tariff__tag">{{ $plan->tagline_fa }}</p>
                    </div>

                    <div class="tariff__money">
                        @if ($plan->price === 0)
                            {{-- The free rung. No toggle attributes: twelve times nothing is
                                 still nothing, and a «۰ تومان» with a yearly discount beside
                                 it reads as a trick rather than an offer. --}}
                            <p class="tariff__price">رایگان</p>
                            <p class="tariff__unit">برای همیشه</p>
                            <p class="tariff__year">بدون کارت بانکی — هر وقت خواستید ارتقا دهید</p>
                        @else
                            <p class="tariff__price nums"
                               data-monthly="{{ money($plan->price, Money::UNIT_TOMAN, true) }}"
                               data-yearly="{{ money($plan->price * $yearFactor, Money::UNIT_TOMAN, true) }}">{{ money($plan->price, Money::UNIT_TOMAN, true) }}</p>

                            <p class="tariff__unit" data-unit
                               data-unit-month="تومان / ماه"
                               data-unit-year="تومان / سال">تومان / ماه</p>

                            {{-- Both totals visible at once, whichever way the toggle is
                                 set. Deliberately NOT swapped by the script: it states the
                                 annual figure permanently, so a shopkeeper comparing plans
                                 never has to flip the control to find out what a year
                                 costs. --}}
                            <p class="tariff__year nums">
                                معادل سالانه {{ money($plan->price * $yearFactor, Money::UNIT_TOMAN, true) }} تومان
                                · {{ $monthsFree }} ماه رایگان
                            </p>
                        @endif
                    </div>

                    <div class="tariff__go">
                        <a href="{{ route('register') }}"
                           class="btn {{ $recommended ? 'btn--primary' : 'btn--quiet' }}">
                            رایگان شروع کنید
                        </a>
                    </div>

                    <div class="tariff__included">
                        <p class="tariff__included__label">سهمیهٔ ماهانه:</p>
                        <ul class="tariff__mods">
                            @foreach ($shown as $metric)
                                @php($value = $plan->limit($metric->key))
                                <li>
                                    @if ($value === null)
                                        <b>نامحدود</b> {{ $metric->labelFa }}
                                    @else
                                        <b class="nums">{{ Digits::toPersian(number_format($value)) }}</b> {{ $metric->labelFa }}
                                    @endif
                                </li>
                            @endforeach
                            <li data-more="true">و همهٔ ماژول‌های دیگر، بدون استثنا</li>
                        </ul>
                    </div>
                </article>
            @endforeach
        </div>

    </div>
</section>
