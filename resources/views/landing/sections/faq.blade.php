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
     * The six questions, in the order the owner listed them, read from
     * `lang/fa/landing.php` under `faq.items` as `['q' => …, 'a' => …]`.
     *
     * Read ONCE, into one variable, and used by both the JSON-LD below and the list
     * further down. That is the whole point: a seventh question is one entry in the lang
     * file, and it cannot appear on the page while being missing from the rich result —
     * nor the other way round, which is how an unflattering answer gets quietly dropped
     * from search. `tests/Feature/LandingSeoTest.php` asserts it.
     *
     * @var list<array{q: string, a: string}>
     */
    $questions = __('landing.faq.items');

    /*
     | No ordinals. A <details> list does not need to be counted, and the page already
     | carries a numbering device in §3 — see the note in tour.blade.php.
     */
@endphp

{{--
    The same six questions, for a search result.

    It sits here rather than in the document head, beside the variable it is built from,
    so a seventh question cannot appear on the page and be missing from the rich result —
    which is exactly what a second copy in the head would guarantee within two edits.
    JSON-LD is valid anywhere in the document and is inert data, so the nonce-only CSP
    does not apply to it.

    The questions moved to `lang/fa/landing.php` with the rest of the page's copy and
    this block followed them: it maps over the same `$questions` the list below renders,
    so the single source is now the lang file rather than an array in this template. The
    answers are the *rendered* answers, including the two that say «نه». An FAQ block
    that quietly drops the unflattering questions is how a page ends up promising in
    search what it does not promise on the page.
--}}
@php
    $faqData = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'inLanguage' => 'fa-IR',
        'mainEntity' => array_map(fn (array $pair): array => [
            '@type' => 'Question',
            'name' => $pair['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $pair['a']],
        ], $questions),
    ];
@endphp
<script type="application/ld+json">
    {!! json_encode($faqData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}
</script>

<section class="sec sec--alt" id="faq" aria-labelledby="qa-title">
    <div class="shell">

        {{-- The shared head, like every section below the fold. It replaces a sticky
             aside column that stood beside the list: distinctive, and the reason the
             page read as eight pages stapled together. See `.sec__head` in landing.css. --}}
        <div class="sec__head">
            <p class="sec__eyebrow">{{ __('landing.faq.eyebrow') }}</p>
            <h2 class="sec__title" id="qa-title">{!! __('landing.faq.title_html') !!}</h2>
            <span class="sec__rule" aria-hidden="true"></span>
            <p class="sec__lede">{{ __('landing.faq.lede') }}</p>
        </div>

        {{-- `.rise` goes on the questions, one level, and on nothing above or below
             them: not this container, not the aside, not the summary inside. Stagger is
             `min(--i, 3) × 60ms` with `--i` written per-parent by `landing.js`, so the
             sixth question does not arrive a full half-second after the first. --}}
        <div class="qa__list qa__list--wide" data-faq>
            @foreach ($questions as $i => ['q' => $question, 'a' => $answer])
                <details class="qa__item rise" @if ($i === 0) open @endif>
                    <summary class="qa__q">
                        <span>{{ $question }}</span>
                        <span class="qa__mark" aria-hidden="true"></span>
                    </summary>
                    <p class="qa__a">{{ $answer }}</p>
                </details>
            @endforeach
        </div>

        <p class="qa__help">
            {{ __('landing.faq.help') }}
            <a href="mailto:{{ $contactEmail }}" dir="ltr">{{ $contactEmail }}</a>
        </p>

    </div>
</section>
