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

    `$plans` and `$addons` are supplied by Platform\Http\Controllers\LandingController.
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

    @param \Illuminate\Support\Collection<int, \App\Modules\Platform\Models\Plan>   $plans
    @param \Illuminate\Support\Collection<int, \App\Modules\Platform\Models\Module> $addons
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

    /** How many module chips a row shows before it starts counting the rest. */
    $chipLimit = 8;
@endphp

<section class="sec" id="pricing">
    <div class="shell">

        {{-- The head is asymmetric on purpose: title and argument on the inline-start,
             the billing control on the inline-end. A centred eyebrow over a centred H2
             is the page's other signature tell, and it is not repeated here. --}}
        <header class="tariff__head rise">
            <div>
                <h2 class="tariff__title">قیمت همینی است که می‌بینید</h2>
                <p class="tariff__lede">
                    ۱۴ روز اول رایگان است و کارت بانکی نمی‌خواهد. هر ماه می‌توانید پلن را
                    بالا و پایین ببرید یا قطع کنید — قرارداد سالانه و جریمهٔ فسخ نداریم.
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

        <div class="tariff__list rise">
            @foreach ($plans as $plan)
                @php
                    $recommended = $plan->code === 'pro';
                    $modules = $plan->modules->sortBy('name_fa');
                    $overflow = $modules->count() - $chipLimit;
                @endphp

                <article class="tariff__plan" data-recommended="{{ $recommended ? 'true' : 'false' }}">
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
                        <p class="tariff__price nums"
                           data-monthly="{{ money($plan->price, Money::UNIT_TOMAN, true) }}"
                           data-yearly="{{ money($plan->price * $yearFactor, Money::UNIT_TOMAN, true) }}">{{ money($plan->price, Money::UNIT_TOMAN, true) }}</p>

                        <p class="tariff__unit" data-unit
                           data-unit-month="تومان / ماه"
                           data-unit-year="تومان / سال">تومان / ماه</p>

                        {{-- Both totals visible at once, whichever way the toggle is set.
                             This line is deliberately NOT swapped by the script: it states
                             the annual figure permanently, so a shopkeeper comparing plans
                             never has to flip the control to find out what a year costs.
                             When the toggle is on yearly it reads as confirmation of the
                             number above it rather than as a second, different price. --}}
                        <p class="tariff__year nums">
                            معادل سالانه {{ money($plan->price * $yearFactor, Money::UNIT_TOMAN, true) }} تومان
                            · {{ $monthsFree }} ماه رایگان
                        </p>
                    </div>

                    <div class="tariff__go">
                        <a href="{{ route('register') }}"
                           class="btn {{ $recommended ? 'btn--primary' : 'btn--quiet' }}">
                            رایگان شروع کنید
                        </a>
                    </div>

                    <div class="tariff__included">
                        <p class="tariff__included__label">شامل:</p>
                        <ul class="tariff__mods">
                            @foreach ($modules->take($chipLimit) as $module)
                                <li>{{ $module->name_fa }}</li>
                            @endforeach

                            @if ($overflow > 0)
                                <li data-more="true">و {{ Digits::toPersian((string) $overflow) }} ماژول دیگر</li>
                            @endif
                        </ul>
                    </div>
                </article>
            @endforeach
        </div>

        {{-- Add-ons as shelf price tags rather than a four-column grid of name/price
             pairs. A shopkeeper reads a price tag without being taught how, and it keeps
             the section's one repeated shape from being "another grid of cards". --}}
        <div class="tariff__addons rise">
            <h3 class="tariff__addons__title">ماژول‌ها را جدا هم می‌فروشیم</h3>
            <p class="tariff__addons__lede">
                اگر فقط یک چیز از پلن‌تان کم بود، لازم نیست پلن بالاتر بخرید؛ همان یکی را
                ماهانه اضافه کنید و هر وقت خواستید بردارید.
            </p>

            <ul class="tariff__tags">
                @foreach ($addons as $addon)
                    <li>
                        <span class="tariff__tags__name">{{ $addon->name_fa }}</span>
                        <b class="nums">{{ money((int) $addon->addon_price, Money::UNIT_TOMAN, true) }}</b>
                        <span class="tariff__tags__unit">تومان / ماه</span>
                    </li>
                @endforeach
            </ul>
        </div>

    </div>
</section>
