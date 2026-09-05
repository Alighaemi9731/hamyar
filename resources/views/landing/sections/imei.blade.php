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
     * The serial in the masthead is the FIRST record's, not a fourth invented number:
     * the head names the subject, the input below is placeheld with it, and the record
     * open on arrival is the one it belongs to. Three places, one handset — and read
     * from the same key the record itself renders, so reordering the three above cannot
     * leave the masthead pointing at a handset that is no longer open.
     */
    $lead = __('landing.imei.records.'.array_key_first($records).'.imei');
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

            {{-- An `_html` key: the heading breaks after the comma with a `<br>`, and
                 where that break falls is the sentence's own rhythm rather than the
                 layout's — so it travels with the words. --}}
            <h2 class="imei-title" id="imei-title">{!! __('landing.imei.title_html') !!}</h2>

            <p class="imei-lede">{{ __('landing.imei.lede') }}</p>
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

                <div class="imei-field">
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
                                <p class="imei-record__no nums" dir="ltr">{{ $device['imei'] }}</p>
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
</section>
