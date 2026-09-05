{{--
    ================================================================= tour ===

    Section 5 — «گشت‌وگذار در محصول». Six real screens of the running product.

    ## Why a mosaic and not five alternating rows

    The page this replaces put one screenshot beside one paragraph, five times, sides
    swapping each row. That zebra is on a thousand generated landing pages, and it also
    lies about how somebody looks at software: nobody reads a feature essay, they scan
    screens and stop at the one that looks like their own counter.

    So the six shots are all on the page at once, at deliberately unequal sizes — 7/5,
    5/7, 4/8 across a twelve-column grid. The rhythm is the composition; there is no
    heading above each tile and no icon beside it. Where a shot earns more room (the POS
    screen, the profit report) it gets more room, and scale does the ranking that an
    eyebrow label would otherwise have to announce.

    ## The tiles are not interactive, and do not pretend to be

    No hover lift, no cursor change, no "click to enlarge" that enlarges nothing. An
    affordance that leads nowhere is a small lie, and this section is already asking to
    be believed about screenshots.

    ## On a phone this section was six grey rectangles

    A 1440×900 shot inside a 358px shell renders about 224px tall, which puts the UI text
    inside it at roughly two pixels. The claim under the title is «تصویرها از خود نرم‌افزار
    گرفته شده‌اند» — and on a phone the visitor could verify none of it, because there was
    nothing legible to verify. Six unreadable grey blocks is worse than three readable
    ones: it asks to be believed and then shows nothing.

    So below `--bp-s` (640) the section shows THREE shots, each cropped to a meaningful
    region at about 4:3 and enlarged so the interface inside is actually readable, and the
    other three are removed from the layout and from the accessibility tree. Which three
    is a content decision and it is the `phone` key in `$screens` below: صندوق فروش
    (the screen a shop is open on all day), بورد تعمیرات (the second module they buy) and
    پروندهٔ دستگاه (the callback to the IMEI section directly above). The three that go are
    the three whose value is a dense table — اقساط, گزارش سود, پیامک — which is precisely
    the kind of screen a phone-sized crop cannot rescue.

    ## The copy

    The caption, its sentence and the alt text of all six are in `lang/fa/landing.php`
    under `tour.screens`, keyed by the same slug that names the capture file.

    ## Cost

    Zero JavaScript of its own. Six `loading="lazy"` images below the fold, the shared
    `.frame` chrome, and the shared `.rise` entry fade that landing.js already drives.
--}}
@php
    /**
     * `<slug> => [span, phone]`, where the slug both names the capture file
     * (`resources/landing/shots/<slug>.webp`) and keys the caption copy in
     * `lang/fa/landing.php` under `tour.screens`.
     *
     * `span` is the desktop column count out of twelve; it is read by the stylesheet as
     * `data-span` and applies only from 1200px (`--bp-l`), where the twelve-column grid
     * exists. Between 640 and 1200 the tiles are an even two-column pair; below 640 the
     * surviving tiles are one column.
     *
     * `phone` is `null` for a shot that is dropped below 640px, or the crop for one that
     * survives. `focus` is one point, spent by both `object-position` and
     * `transform-origin`: x runs 0→100% across the image and y 0→100% down it, in the
     * image's own frame. Neither property has a logical form, so the values are
     * percentages — that keeps the direction keywords the RTL gate rejects out of the
     * stylesheet entirely. The app is RTL, so the primary column of every screenshot
     * sits near the 100% end of x, which is where these points cluster. `zoom` is how
     * far the shot is enlarged inside the frame before the frame clips it.
     *
     * The last tile is the IMEI record on purpose: the visitor has just built one by
     * hand in the section above, and this is the same thing inside the software. A tour
     * that ends by pointing back at the argument is a page, not a list.
     *
     * @var array<string, array{span: int, phone: null|array{focus: string, zoom: string}}>
     */
    $screens = [
        // The invoice lines and the scan box, high on the start side.
        'pos' => ['span' => 7, 'phone' => ['focus' => '86% 26%', 'zoom' => '2.3']],
        // The first kanban column with its header and top two cards.
        'repairs' => ['span' => 5, 'phone' => ['focus' => '88% 34%', 'zoom' => '2.2']],
        'installments' => ['span' => 5, 'phone' => null],
        'profit' => ['span' => 7, 'phone' => null],
        'sms' => ['span' => 4, 'phone' => null],
        // The serial header and the first rows of the device's history.
        'imei' => ['span' => 8, 'phone' => ['focus' => '84% 30%', 'zoom' => '2.4']],
    ];
@endphp

<section class="sec sec--alt tour" id="tour" aria-labelledby="tour-title">
    <div class="shell">
        {{--
            A running head, not a masthead.

            Six of the eight rebuilt sections arrived opening the same way — a heading on
            one side, a supporting line on the other, baseline-aligned. Four authors each
            killed the centred eyebrow and then independently reached for the same
            replacement, which is a new template rather than none. This section is one of
            the four that gives it up: the title sits on one line with the claim beside it,
            and the largest screenshot carries the section instead of a heading block.
        --}}
        {{-- The shared head (ADR 0021). It was a running head — title and claim on one
             baseline under a hairline — one of the eight different openings this page
             used to have. --}}
        <div class="sec__head">
            <p class="sec__eyebrow">{{ __('landing.tour.eyebrow') }}</p>
            <h2 class="sec__title" id="tour-title">{!! __('landing.tour.title_html') !!}</h2>
            <span class="sec__rule" aria-hidden="true"></span>
            <p class="sec__lede">{{ __('landing.tour.lede') }}</p>
        </div>

        <div class="tour-grid">
            @foreach ($screens as $file => ['span' => $span, 'phone' => $phone])
                <figure class="tour-item rise"
                        data-span="{{ $span }}"
                        data-phone="{{ $phone ? 'on' : 'off' }}"
                        @if ($phone) style="--tour-focus: {{ $phone['focus'] }}; --tour-zoom: {{ $phone['zoom'] }};" @endif>
                    <div class="frame">
                        <div class="frame__bar" aria-hidden="true">
                            <span class="frame__dot"></span><span class="frame__dot"></span><span class="frame__dot"></span>
                        </div>
                        <img src="{{ Vite::asset("resources/landing/shots/{$file}.webp") }}"
                             alt="{{ __("landing.tour.screens.{$file}.alt") }}"
                             width="1440" height="900" loading="lazy" decoding="async">
                    </div>

                    <figcaption class="tour-cap">
                        {{-- No index here. The rebuilt page arrived with THREE separate
                             numbering devices — ۰۱–۰۶ in the margins of §3, ۰۱–۰۶ again on
                             these tiles, and ۱–۶ on the FAQ — two of them the same numerals
                             in the same order, 40% of the page apart. Generated pages love
                             numbered decoration; three flavours of it is the tell wearing a
                             hat. The mosaic's unequal tile sizes already carry the ranking,
                             which is this file's own argument for the layout. --}}
                        <span class="tour-cap__text">
                            <b class="tour-name">{{ __("landing.tour.screens.{$file}.name") }}</b>
                            <span class="tour-body">{{ __("landing.tour.screens.{$file}.body") }}</span>
                        </span>
                    </figcaption>
                </figure>
            @endforeach
        </div>
    </div>
</section>
