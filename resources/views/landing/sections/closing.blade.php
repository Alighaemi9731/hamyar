{{--
    Section 8 — دعوت پایانی و فوتر کامل.

    ## One navy tail, in TWO includes, because of one landmark

    "A dark band with two centred buttons in it, followed by a light footer strip" is on
    the owner's list of tells, and it is also two weak endings instead of one strong one.
    So the closing call and the whole footer share ONE navy ground: the call sits at the
    top of it, asymmetric — the claim on the inline-start, the buttons on the inline-end —
    and the footer columns sit under a hairline below. The page ends with weight, which is
    the exact complaint («بی‌روح») that ADR 0016 records against the calm version.

    This is the page's dark anchor at the bottom. The IMEI section is the one in the
    middle. Colour carries meaning here: dark means "remember this", and spending it
    twice on a long page is the budget.

    ## Why this partial renders in two parts

    The `<footer>` must sit OUTSIDE `<main>` or it is not `contentinfo` — a `<footer>`
    inside `<main>` simply stops being a landmark. But the biggest conversion CTA on the
    page must sit INSIDE `<main>`: it is content, it is the thing the whole page is for,
    and the skip link targets `#main`, so a CTA outside it is a CTA the skip link skips.

    Those two requirements cannot both be met by one wrapper element, because a `<div>`
    cannot straddle `</main>`. So this file renders one of two parts, and landing.blade.php
    includes it twice: with `part => 'call'` as the last thing inside `<main>`, then with
    `part => 'tail'` immediately after `</main>`. Nothing goes between them.

    Both parts carry `.signoff`, so the navy ground, the glows and the type all come from
    one class and the seam is invisible — see the two custom-property overrides below,
    which are what keep it invisible. The `<footer>` in the tail is wrapped in a plain
    `<div>` (not a sectioning element), so it still maps to `contentinfo`.

    It also REPLACES the page's old `<footer class="foot">`; leaving both would ship two
    footers.

    @see resources/landing/sections/closing.css
--}}
@php
    /** @var 'call'|'tail' $part */
    $part ??= 'call';
@endphp

@if ($part === 'call')

    {{--
        `.signoff::before` paints two lights: an accent glow at the top of the element and
        a navy-700 lift at 118% of it. On the undivided tail the second one landed below
        the page, where it belongs. On this shorter half it would land inside the CTA and
        wash the bottom of it, so it is switched off by nulling the only token it reads.
        Nothing else inside this part uses `--color-navy-700` — the two hairlines that do
        are both in the tail.
    --}}
    <div class="signoff signoff--call">
        <section class="shell signoff__cta rise" aria-labelledby="signoff-title">
            <div>
                <h2 class="signoff__title" id="signoff-title">{{ __('landing.closing.title') }}</h2>
                <p class="signoff__lede">{{ __('landing.closing.lede') }}</p>
            </div>

            <div class="signoff__act">
                <div class="signoff__actions">
                    <a href="{{ route('register') }}" class="btn btn--light btn--lg">
                        {{ __('landing.closing.cta_primary') }}
                        @include('landing.icon', ['name' => 'arrow', 'size' => 16])
                    </a>
                    <a href="#pricing" class="btn btn--ghost btn--lg">{{ __('landing.closing.cta_secondary') }}</a>
                </div>

                <p class="signoff__note">{{ __('landing.closing.note') }}</p>
            </div>
        </section>
    </div>

@else

    @php
        /**
         * Golden rule 1b: the apex is never written down, not even now that everyone knows
         * what it is. The mailbox name is ours, the host comes from config, and that is what
         * keeps the local stack and production rendering from the same template — and an
         * apex change a data migration rather than a code change.
         * `bin/check-apex-domain` scans this directory.
         */
        $contactEmail = 'info@'.config()->string('app.domain');

        /**
         * The product column. Every row is an anchor that a section on this page actually
         * carries — `#features` was in this list for three sections that no longer exist
         * under that id, which is the quietest kind of broken link: it scrolls nowhere and
         * reports nothing. The ids are the ones in the nav, plus the two the nav has no
         * room for.
         *
         * The anchors stay here for that reason; the labels are in `lang/fa/landing.php`
         * under `closing.footer.product`.
         *
         * @var list<array{0: string, 1: string}>
         */
        $product = [
            ['#problems', __('landing.closing.footer.product.problems')],
            ['#imei', __('landing.closing.footer.product.imei')],
            ['#tour', __('landing.closing.footer.product.tour')],
            ['#pricing', __('landing.closing.footer.product.pricing')],
            ['#faq', __('landing.closing.footer.product.faq')],
        ];

        /**
         * The modules column. Plain labels, not links: there is no per-module section on
         * this page to point them at, and a footer full of links that all land in the same
         * place is worse than a list that admits it is a list.
         *
         * @var list<string>
         */
        $modules = __('landing.closing.footer.modules');
    @endphp

    {{--
        The tail. Same `.signoff` ground, so the navy runs on from the CTA above with no
        seam — but `.signoff::before` would repeat its accent glow at the top of THIS
        element too, which would read as a second beginning right under the footer's
        hairline. The glow is the only thing in this part that reads `--color-accent`
        (the mark, the mail link and the link hover are all `--color-accent-lit`), so
        nulling that one token removes the repeat and nothing else. The navy-700 lift at
        118% stays, and now lands below the true bottom of the page, where it did before.
    --}}
    <div class="signoff signoff--tail">
        <footer class="shell signoff__foot">
            <div class="signoff__brand">
                {{--
                    The same wordmark the nav signs with, and no second logo.

                    This carried the retired symbol — a handset outline in a rounded
                    rectangle — beside «همیار» set as text, so the page opened with one
                    logo and closed with a different one. The owner retired that symbol on
                    2026-09-04 («لوگو فعلی سامانمون خوب نیست؛ همین اسم سامانه کافی است») and
                    the nav changed the same day; this did not, which is how a page ends up
                    disagreeing with itself about what it is called.

                    `$wordmark` is read once in `landing.blade.php` and inherited here —
                    this partial is included twice per request and re-reading the file for
                    a half that does not use it would be two disk reads for one logo. It
                    is outlines inheriting `currentColor`, so white on this navy ground
                    costs nothing but the inherited colour.
                --}}
                <span class="signoff__brand__name" aria-label="{{ __('landing.closing.footer.brand_label') }}">
                    {!! $wordmark !!}
                </span>

                <p>{{ __('landing.closing.footer.about') }}</p>

                <a class="signoff__mail" href="mailto:{{ $contactEmail }}" dir="ltr">{{ $contactEmail }}</a>
            </div>

            {{-- Every `<ul>` here is `list-style: none`, and Safari + VoiceOver drop list
                 semantics from exactly those — so each one restates `role="list"`. Four
                 lists, four statements; a footer that announces "list, 5 items" is how a
                 screen-reader user knows the column ended. --}}
            <nav class="signoff__cols" aria-label="{{ __('landing.closing.footer.nav_aria') }}">
                <div class="signoff__col">
                    <h3>{{ __('landing.closing.footer.product_heading') }}</h3>
                    <ul role="list">
                        @foreach ($product as [$anchor, $label])
                            <li><a href="{{ $anchor }}">{{ $label }}</a></li>
                        @endforeach
                    </ul>
                </div>

                <div class="signoff__col">
                    <h3>{{ __('landing.closing.footer.modules_heading') }}</h3>
                    <ul role="list">
                        @foreach ($modules as $module)
                            <li><span>{{ $module }}</span></li>
                        @endforeach
                    </ul>
                </div>

                <div class="signoff__col">
                    <h3>{{ __('landing.closing.footer.start_heading') }}</h3>
                    <ul role="list">
                        <li><a href="{{ route('register') }}">{{ __('landing.closing.footer.register') }}</a></li>
                        <li><a href="{{ route('login') }}">{{ __('landing.closing.footer.login') }}</a></li>
                        <li><a href="mailto:{{ $contactEmail }}">{{ __('landing.closing.footer.contact') }}</a></li>
                    </ul>
                </div>

                <div class="signoff__col">
                    <h3>{{ __('landing.closing.footer.legal_heading') }}</h3>
                    <ul role="list">
                        <li><a href="{{ route('legal.terms') }}">{{ __('landing.closing.footer.terms') }}</a></li>
                        <li><a href="{{ route('legal.privacy') }}">{{ __('landing.closing.footer.privacy') }}</a></li>
                        <li><a href="#faq">{{ __('landing.closing.footer.data') }}</a></li>
                    </ul>
                </div>
            </nav>
        </footer>

        {{-- The year is rendered, not typed: a hardcoded «۱۴۰۵» is correct for four more
             months and then quietly wrong on the one page every prospect reads. It is
             passed into the sentence rather than concatenated onto it, so the whole line
             stays one editable string in `lang/fa/landing.php`. --}}
        <div class="shell signoff__base">
            <span>{{ __('landing.closing.footer.copyright', ['year' => jalali(now(), 'Y')]) }}</span>
            <span>{{ __('landing.closing.footer.made_for') }}</span>
        </div>
    </div>

@endif
