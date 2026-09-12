{{--
    ================================================================= IMEI ===

    Section 4 — «شناسنامهٔ IMEI». The one claim this product genuinely differs on, and
    the page's dark anchor in the middle (ADR 0016, Direction B revised).

    ## 2026-09-12 — the anchor stopped being a slab and became an object

    The owner's complaint, pointing at this section by quoting its own serial: «کلا
    انگار با بقیهٔ سایت نمی‌خونه، رنگ و فلانش خیلی فرق داره با بقیه قسمتا.» It was not
    the navy — measured, the ground here and the ground under the closing sign-off are
    the same #0A1628 token. It was that this section was the only one on the page still
    written in the page's OLD grammar, in three ways that all read at once:

      · It was the last section opening with its own head shape. ADR 0021 reversed the
        "every section opens differently" policy and gave the page one centred head —
        eyebrow, H2, 40px rule, lede — and problems/tour/pricing/faq were all converted.
        This head never was, so section 4 was the one place the page changed its mind.
      · It was full-bleed, square-cornered, edge to edge, wedged between two sections
        that share one ground (#EDF2F8 above and below it). A dark rectangle cut into a
        light page, with the same grey on both sides, is a slab pasted on — not a step
        in the page's rhythm.
      · Its head was broken in RTL, which is most of what «ناهمگون» was pointing at: the
        `dir="ltr"` sat on the serial's own `<p>`, so the serial aligned to the LEFT edge
        of the shell while the H2 it belongs to aligned right. At 1440 the two halves of
        the "lockup" were ~1000px apart, at opposite ends of the band.

    What it is now: the section keeps the page's white ground and the navy becomes an
    INSET ROUNDED PANEL on it — `--radius-xl`, the step the shared contract reserves for
    anchors and which had no user left, plus `--shadow-band`. The page's ground
    alternation is restored (white · alt · WHITE+panel · alt · white · alt · navy) and
    the dark is now an object the page holds rather than a hole cut in it.

    ## The page's two dark anchors, and why they are now different shapes

    Section 8 stays a full-bleed navy tail, square and edge to edge. That is deliberate
    and it is the distinction: **the middle anchor is an object in the page, the last
    one is the floor the page ends on.** A band that ends the page has nothing after it
    to interrupt; a band in the middle has two neighbours and interrupts both. Same
    ground token, same glow, same type — two jobs, two shapes. `closing.css` is
    untouched.

    REJECTED: lightening this section. The owner objected to incoherence, not to weight,
    and the section carries the product's one uncopyable claim; a white card here would
    leave the closing tail as the page's only dark element, an orphan. Also rejected: a
    gradient fade between the grey and the navy (a smear, and the seam is not the
    problem once the band is no longer a slab), and any texture overlay to "tie it
    together" — ADR 0021 deleted the `.mesh` grid and its tombstone says why.

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

    All of it — the handsets, the dates, the amounts, the notes — is in
    `lang/fa/landing.php` under `imei.records`, keyed by the slugs below. What stays here
    is the timeline's shape: which events each record carries, in which order, which icon
    marks each one, and the two states the stylesheet reads.
--}}
@php
    /**
     * The three sample records, as a spine.
     *
     * `events` is `<event slug> => <icon>`, and its ORDER is the order the timeline
     * renders in — which differs per record on purpose: the second handset was repaired
     * before it was sold, the third has not been sold at all. The copy for each event is
     * `landing.imei.records.<record>.events.<event>`.
     *
     * `pending` names the one event that has not happened yet (a `data-pending` row); the
     * third record's sale is the only one. `muted` greys the result footer for the same
     * record, whose profit is not knowable until it sells.
     *
     * Rule on digits, applied here and not everywhere else on this page yet: prose and
     * money carry Persian digits; IMEI, and invoice/receipt/transfer numbers stay Latin
     * and are wrapped `dir="ltr"`. A serial read aloud to a supplier over the phone is a
     * Latin string, and rendering it in Persian digits makes it un-copyable.
     *
     * `state` is the record's own word for where the unit is, not a colour: a green
     * "sold" pill would be a second accent hue, which this page does not have.
     *
     * @var array<string, array{events: array<string, string>, pending: string|null, muted: bool}>
     */
    $records = [
        'iphone-13' => [
            'events' => ['bought' => 'store', 'sold' => 'trend', 'repaired' => 'wrench'],
            'pending' => null,
            'muted' => false,
        ],
        'galaxy-a54' => [
            'events' => ['bought' => 'store', 'repaired' => 'wrench', 'sold' => 'trend'],
            'pending' => null,
            'muted' => false,
        ],
        'redmi-note-12' => [
            'events' => ['bought' => 'store', 'moved' => 'arrow', 'sold' => 'calendar'],
            'pending' => 'sold',
            'muted' => true,
        ],
    ];

    /**
     * The serial in the head is the FIRST record's, not a fourth invented number:
     * the head names the subject, the input below is placeheld with it, and the record
     * open on arrival is the one it belongs to. Three places, one handset — and read
     * from the same key the record itself renders, so reordering the three above cannot
     * leave the head pointing at a handset that is no longer open.
     */
    $lead = __('landing.imei.records.'.array_key_first($records).'.imei');
@endphp

<section class="sec imei" id="imei" aria-labelledby="imei-title">

    {{-- The navy, as a panel rather than as the section. `.band` moves from the
         `<section>` onto this wrapper and brings everything it always brought — the
         ground, the two radial lights, the `h2/h3/b → --color-on-dark` rule — so
         landing.css still owns what a dark band looks like and this file still only
         consumes it. What the section keeps is the page's own ground and rhythm.

         Its edges are the page's WIDE TRACK, `.shell--wide` — the same 1280px track the
         fold's grid sits on, and the only other place on the page that uses it. So the
         panel does not invent a width: the page's two largest compositions line up on
         one edge. Every edge it has is one the neighbours already have: at 1440 the
         `.shell` inside it lands exactly on the 1120px column of the cards above and
         the tiles below; below ~1330 the panel's own edge is that column's edge and
         the content sits one gutter inside it. Measured at 390/768/1024/1440.

         One element, no `.rise`: a 1400px panel fading in on scroll is the animation
         ADR 0016 ruled out, and the section's one entry level is already spent on the
         three sample files below. --}}
    <div class="band shell shell--wide imei-panel">
        <div class="shell">
            {{--
                The page's shared section head — `.sec__head`, the same eyebrow-slot,
                centred H2, 40px rule and lede that problems, tour, pricing and faq open
                with. This section was the last one still opening its own way, and one
                section changing the page's mind at section 4 is the whole of «با بقیه
                نمی‌خونه».

                What survives of the serial-first lockup is the idea, not the shape: the
                serial STANDS IN THE EYEBROW'S PLACE, so the section still names its subject
                before it says a word — the one thing no other section can do, because no
                other section has an object with a number on it. It is the first record's
                own serial (see `$lead` above), and it is `<bdi dir="ltr">` rather than a
                `dir` on the paragraph: the isolation belongs to the digits, and putting it
                on the block is what threw the serial to the far edge of the band, opposite
                the heading it names.

                REJECTED: keeping the start-aligned head and merely fixing its alignment.
                That leaves section 4 the only section on the page with no eyebrow and no
                rule, which is the same complaint one fix later.

                No `.rise` here either: the section head never rises (landing.css, THE RULE).
            --}}
            <header class="sec__head">
                <p class="imei-serial nums"><bdi dir="ltr">{{ $lead }}</bdi></p>

                {{-- An `_html` key: the heading breaks after the comma with a `<br>`, and
                     where that break falls is the sentence's own rhythm rather than the
                     layout's — so it travels with the words. --}}
                <h2 class="sec__title imei-title" id="imei-title">{!! __('landing.imei.title_html') !!}</h2>

                <span class="sec__rule" aria-hidden="true"></span>

                <p class="sec__lede">{{ __('landing.imei.lede') }}</p>
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
                    <label class="imei-pick__label" for="imei-input">{{ __('landing.imei.field_label') }}</label>

                    {{-- `dir="ltr"` on the WRAPPER, not only on the input — the same fix, for
                         the same bug, that `components/domain/imei-input.tsx` carries in the
                         product. The scan mark is placed with `inset-inline-end`, which
                         resolves against its containing block: with the wrapper RTL that is
                         the LEFT edge, where the LTR digits start, so the icon sat on top of
                         the serial while the input reserved its 3rem on the empty side.
                         Measured before this change at 1440: input 976→1280, mark 990→1010,
                         `padding-left: 16px / padding-right: 48px`. One LTR pocket and every
                         logical property inside it agrees again. --}}
                    <div class="imei-field" dir="ltr">
                        <input class="imei-field__input nums" id="imei-input" type="text"
                               inputmode="numeric" autocomplete="off" spellcheck="false"
                               maxlength="24" dir="ltr" placeholder="{{ $lead }}"
                               aria-describedby="imei-hint" data-imei-input>
                        <span class="imei-field__mark" aria-hidden="true">
                            @include('landing.icon', ['name' => 'scan', 'size' => 20])
                        </span>
                    </div>

                    <p class="imei-hint" id="imei-hint">{{ __('landing.imei.hint') }}</p>

                    {{-- `role="list"`: Safari + VoiceOver drop list semantics from a list
                         styled `list-style: none`, and this is one. --}}
                    <ul class="imei-devices" role="list" data-imei-devices>
                        @foreach ($records as $slug => $record)
                            @php($device = __("landing.imei.records.{$slug}"))
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
                    @foreach ($records as $slug => $record)
                        @php($device = __("landing.imei.records.{$slug}"))
                        <article class="imei-record" data-imei-panel
                                 data-imei="{{ $device['imei'] }}"
                                 data-imei-name="{{ $device['name'] }}"
                                 @unless ($loop->first) hidden @endunless>
                            <header class="imei-record__head">
                                <div>
                                    <h3 class="imei-record__name">{{ $device['name'] }}</h3>
                                    {{-- `<bdi dir="ltr">` around the digits, not `dir` on the
                                         paragraph: the same block-level flip that threw the
                                         head's serial to the wrong edge. Here it only looked
                                         right because the flex item happens to be as wide as
                                         the serial. --}}
                                    <p class="imei-record__no nums"><bdi dir="ltr">{{ $device['imei'] }}</bdi></p>
                                </div>
                                <span class="imei-state">{{ $device['state'] }}</span>
                            </header>

                            {{-- `role="list"` for the same reason, and the `--i` below is
                                 the stylesheet's own row delay on a panel swap, not the
                                 entry stagger — these rows carry no `.rise`. --}}
                            <ol class="imei-track" role="list">
                                @foreach ($record['events'] as $eventSlug => $icon)
                                    @php($event = $device['events'][$eventSlug])
                                    <li class="imei-ev" style="--i:{{ $loop->index }}"
                                        @if ($record['pending'] === $eventSlug) data-pending @endif>
                                        <span class="imei-ev__mark" aria-hidden="true">
                                            @include('landing.icon', ['name' => $icon, 'size' => 15])
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

                            <footer class="imei-result" @if ($record['muted']) data-muted @endif>
                                <span>{{ $device['result']['label'] }}</span>
                                <b class="nums">{{ $device['result']['value'] }}</b>
                            </footer>
                        </article>
                    @endforeach

                    {{-- Typed digits that match no sample. A dead end is a bad answer, so this
                         one says what the visitor would actually get in their own account. --}}
                    <p class="imei-miss" data-imei-miss hidden>
                        {{ __('landing.imei.miss') }}
                        <span>{{ __('landing.imei.miss_detail') }}</span>
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
            <p class="imei-honesty">{{ __('landing.imei.honesty') }}</p>
        </div>
    </div>
</section>
