{{--
    Section 1 — the fold.

    ## What this replaces, and why

    The previous hero was a navy "counter after hours" panel with four rendered paper
    slips that squared up into ledger rows as the first half-screen scrolled. It was the
    third composition the owner rejected («معلومه کلاد کد زده»), and the 16.0 baseline
    had already flagged its hairline `.mesh` overlay as a generated-UI signature.

    ADR 0021 takes the shape of the reference the owner named — a POS product in our own
    category — and mirrors it for RTL: the argument on the reading-start side, and **the
    product itself** on the other. Three cards float over the frame naming moments the
    software actually produces.

    ## The claim, and the one number that is not here

    The headline is candidate (a) from `docs/brand/positioning.md`, chosen at the gate:
    it names the category in the largest type on the page rather than leaving it to a
    14px eyebrow, which is what the baseline found a first-time visitor reading past.

    The reference's eyebrow reads «Used by 500+ Phone Shops Worldwide». Ours states what
    the product is, because we have no such number and `docs/brand/voice.md` rule 3
    forbids inventing one. It becomes a pilot-shop line the day the owner supplies names
    and written consent.

    ## The capture is real, and stays real

    `bin/shots` takes it from a seeded shop, the manifest records the commit it was taken
    at, `LandingShotsTest` fails if the manifest and the files disagree, and the weekly
    workflow re-takes it. The page's previous screenshots were from 22 August and the
    page claimed «تصویرها از خود نرم‌افزار گرفته شده‌اند» while showing a product that had
    since changed twice.

    The three cards carry sample figures from one shop's day. Persian digits in prose;
    the IMEI stays Latin and `dir="ltr"`, because a serial that reorders under bidi is a
    serial nobody can read back to a customer.

    ## The copy

    In `lang/fa/landing.php` under `hero`. The two reasons above that are about a
    sentence rather than about the composition — the eyebrow that states no customer
    count, and the alt text `bin/shots` keeps true — are restated beside their keys.
--}}
<section class="sec fold" id="hero">
    <div class="shell shell--wide fold__grid">
        <div class="fold__say">
            <p class="fold__eyebrow">{{ __('landing.hero.eyebrow') }}</p>

            {{-- One of the seven `_html` keys on the page: the headline holds
                 «فروشگاه موبایل» together in a `.nowrap` span and lights its last phrase
                 with `<em>`, so the string carries markup and is rendered unescaped. --}}
            <h1 class="fold__title">{!! __('landing.hero.title_html') !!}</h1>

            <p class="fold__lede">{{ __('landing.hero.lede') }}</p>

            <div class="fold__actions">
                <a href="{{ route('register') }}" class="btn btn--primary btn--lg">{{ __('landing.hero.cta_primary') }}</a>
                {{-- `quiet`, not `ghost`: ghost is white-on-transparent for the navy
                     band, and on this white ground it was an invisible button. --}}
                <a href="#tour" class="btn btn--quiet btn--lg">{{ __('landing.hero.cta_secondary') }}</a>
            </div>

            <ul class="fold__ticks">
                @foreach (__('landing.hero.ticks') as $tick)
                    <li>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M20 6 9 17l-5-5"/>
                        </svg>
                        {{ $tick }}
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="fold__stage">
            <div class="frame">
                <div class="frame__bar" aria-hidden="true"><span></span><span></span><span></span></div>
                <picture>
                    <source
                        srcset="{{ Illuminate\Support\Facades\Vite::asset('resources/landing/shots/dashboard.webp') }} 1x,
                                {{ Illuminate\Support\Facades\Vite::asset('resources/landing/shots/dashboard@2x.webp') }} 2x"
                        type="image/webp">
                    {{-- `fetchpriority="high"`: it is the largest paint on the fold, and the
                         browser otherwise discovers it after the stylesheet. --}}
                    <img src="{{ Illuminate\Support\Facades\Vite::asset('resources/landing/shots/dashboard.webp') }}"
                         width="1440" height="900" fetchpriority="high" decoding="async"
                         alt="{{ __('landing.hero.shot_alt') }}">
                </picture>
            </div>

            <div class="fold__card fold__card--imei">
                <span class="fold__card-dot" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round">
                        <rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/>
                    </svg>
                </span>
                <span>
                    <b>{{ __('landing.hero.cards.imei.title') }}</b>
                    <span class="nums" dir="ltr">{{ __('landing.hero.cards.imei.value') }}</span>
                </span>
            </div>

            <div class="fold__card fold__card--repair">
                <span class="fold__card-dot" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14.7 6.3a4 4 0 0 1-5 5L4 17v3h3l5.7-5.7a4 4 0 0 0 5-5z"/>
                    </svg>
                </span>
                <span>
                    <b>{{ __('landing.hero.cards.repair.title') }}</b>
                    <span>{{ __('landing.hero.cards.repair.value') }}</span>
                </span>
            </div>

            <div class="fold__card fold__card--instalment">
                <span class="fold__card-dot" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 6 9 17l-5-5"/>
                    </svg>
                </span>
                <span>
                    <b>{{ __('landing.hero.cards.instalment.title') }}</b>
                    <span class="nums">{{ __('landing.hero.cards.instalment.value') }}</span>
                </span>
            </div>
        </div>
    </div>
</section>
