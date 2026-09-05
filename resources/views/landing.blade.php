<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- Every sentence on this page is in `lang/fa/landing.php`, nested by section
         (CLAUDE.md, «Persian UI strings in lang/fa/**»). The two exceptions are
         deliberate and both are below: anything computed — prices, the contact
         address, the year — and the structured-data blocks, which are written for a
         machine and stay beside the markup they describe. --}}
    <title>{{ __('landing.meta.title') }}</title>
    <meta name="description" content="{{ __('landing.meta.description') }}">
    <meta name="theme-color" content="#FFFFFF">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <link rel="canonical" href="{{ url('/') }}">

    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ __('landing.meta.og_title') }}">
    <meta property="og:description" content="{{ __('landing.meta.og_description') }}">
    <meta property="og:locale" content="fa_IR">
    <meta property="og:url" content="{{ url('/') }}">
    {{-- Rendered from the brand layer and the dashboard capture by `bin/shots og`; hashed by
         Vite so a re-capture invalidates the URL every unfurler has cached. --}}
    <meta property="og:image" content="{{ Vite::asset('resources/landing/og/og.png') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ __('landing.meta.twitter_title') }}">
    <meta name="twitter:description" content="{{ __('landing.meta.twitter_description') }}">
    <meta name="twitter:image" content="{{ Vite::asset('resources/landing/og/og.png') }}">

    {{-- The two faces the fold paints with: Estedad for the headline and Vazirmatn for
         everything under it. Both are variable and weight-pinned, so two files cover
         every weight the page uses. `crossorigin` is mandatory even same-origin or the
         preload is discarded and the file fetched twice. --}}
    <link rel="preload" as="font" type="font/woff2" crossorigin
          href="{{ Illuminate\Support\Facades\Vite::asset('resources/fonts/estedad-arabic-wght-normal.woff2') }}">
    <link rel="preload" as="font" type="font/woff2" crossorigin
          href="{{ Illuminate\Support\Facades\Vite::asset('resources/fonts/vazirmatn-arabic-wght-normal.woff2') }}">

    {{--
        Structured data — what a search result is built from, and the one thing on this
        page written for a machine.

        ## It is a data block, not a script

        `type="application/ld+json"` is inert: the browser never executes it, so CSP's
        `script-src` does not apply and this needs no nonce. That is the only reason
        structured data can live on a page whose policy refuses inline script outright.

        ## The prices come from the database, like every other price here

        `$plans` is the same collection the pricing section renders, so a panel edit
        changes the rich result and the section together. A price typed here would be a
        second source that drifts silently and is *only* visible in a search result —
        the one surface nobody on the team looks at.

        Rial, not toman. Schema.org wants a currency code and an amount in that currency;
        the page renders toman for a human because that is what a shopkeeper says, and
        `IRR` is what the machine is told. Both read the same integer.

        ## Its Persian stays here, unlike the rest of the page

        Every sentence a visitor reads moved to `lang/fa/landing.php`. These strings did
        not, and the difference is the audience: this block is read by a crawler, its
        wording is bound to the schema.org vocabulary around it, and a copy editor
        opening the lang file to reword a heading has no business silently changing what
        a search engine is told the product is. Two of them read alike on purpose.

        ## No claim we cannot stand behind

        No `aggregateRating`, no `review`, no `interactionStatistic`. Every one of those
        is a rich-result magnet and every one would be invented — this product has no
        published reviews, and a fabricated rating in structured data is the kind of
        thing that gets a domain penalised as well as being a lie.

        ## Built in a php block, not by the json directive

        That directive hands its argument to Blade's own expression parser, which counts
        brackets and loses track of an arrow function inside a nested array — the failure
        is `Unclosed '[' does not match ')'`, pointing at the directive rather than at the
        thing it could not read. A php block is parsed by PHP itself.

        **Never write a Blade directive name inside a Blade comment** — which is why this
        whole comment spells them without their sigil. Blade extracts raw php blocks
        *before* it strips comments, so a directive name written here with its sigil opens
        a block that closes on the real one below. The comment's own terminator is then
        inside that extracted region, invisible to the stripper, which runs on to the next
        terminator it can find — and `</head>` and `<body>` are deleted from the compiled
        template. The page still returns 200 with no error anywhere. It happened here.

        Note that a *closing* directive name is the same hazard in reverse: written in
        prose above a real block, it is the token that block's opener would otherwise have
        paired with. `tests/Feature/LandingSeoTest.php` asserts the document is whole.

        `JSON_HEX_TAG` is the load-bearing flag: without it a plan named with a `<` could
        close this element and everything after it would be markup. Nothing in the panel
        stops somebody typing one.
    --}}
    @php
        $structuredData = [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'Organization',
                    '@id' => url('/').'#organization',
                    'name' => 'سامانه همیار',
                    'url' => url('/'),
                    'logo' => Vite::asset('resources/landing/og/og.png'),
                    'email' => 'info@'.config()->string('app.domain'),
                    'areaServed' => ['@type' => 'Country', 'name' => 'ایران'],
                ],
                [
                    '@type' => 'SoftwareApplication',
                    'name' => 'سامانه همیار',
                    'applicationCategory' => 'BusinessApplication',
                    'applicationSubCategory' => 'نرم‌افزار فروشگاه موبایل',
                    'operatingSystem' => 'Web',
                    'inLanguage' => 'fa-IR',
                    'url' => url('/'),
                    'publisher' => ['@id' => url('/').'#organization'],
                    'description' => 'فروش سریال‌دار با IMEI، تعمیرات، اقساط و چک، پیامک خودکار و گزارش سود، برای فروشگاه‌های موبایل.',
                    'offers' => $plans
                        ->map(fn ($plan): array => [
                            '@type' => 'Offer',
                            'name' => $plan->name_fa,
                            'price' => (string) $plan->price,
                            'priceCurrency' => 'IRR',
                            'category' => $plan->price === 0 ? 'free' : 'subscription',
                            'url' => route('register'),
                        ])
                        ->values()
                        ->all(),
                ],
            ],
        ];
    @endphp
    <script type="application/ld+json">
        {!! json_encode($structuredData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}
    </script>

    @vite(['resources/landing/landing.css', 'resources/landing/landing.js'])
</head>
<body>

{{-- CSS-only. It carried `onfocus`/`onblur` handlers that the nonce-only CSP refused
     outright, so the one affordance a keyboard visitor needs was never visible and the
     console carried two errors on every visit (16.0 baseline, finding 7). --}}
<a href="#main" class="skip">{{ __('landing.skip') }}</a>

{{-- ================================================================= nav === --}}
@php
    // One source for the logo: the same file `components/brand-mark.tsx` imports. It is
    // outlines, so it inherits `currentColor` and needs no webfont to have loaded.
    $wordmark = Illuminate\Support\Facades\File::get(resource_path('brand/wordmark.svg'));
    // The labels are in `lang/fa/landing.php` under `nav.links`; the anchors stay here,
    // because each one has to match a section id this page actually carries.
    $links = [
        '#problems' => __('landing.nav.links.problems'),
        '#imei' => __('landing.nav.links.imei'),
        '#pricing' => __('landing.nav.links.pricing'),
        '#faq' => __('landing.nav.links.faq'),
    ];
@endphp
<header class="nav">
    <div class="shell nav__inner">
        <a href="/" class="nav__brand" aria-label="{{ __('landing.nav.brand_label') }}">
            <span class="nav__wordmark" aria-hidden="true">{!! $wordmark !!}</span>
        </a>

        <nav class="nav__links" aria-label="{{ __('landing.nav.aria') }}">
            @foreach ($links as $href => $label)
                <a href="{{ $href }}">{{ $label }}</a>
            @endforeach
        </nav>

        {{-- Both go to the app host, which is now one address for every shop (ADR 0017). --}}
        <div class="nav__cta">
            <a href="{{ route('login') }}" class="btn btn--quiet">{{ __('landing.nav.login') }}</a>
            <a href="{{ route('register') }}" class="btn btn--primary">{{ __('landing.nav.register') }}</a>
        </div>

        <details class="nav__menu">
            <summary class="nav__toggle" aria-label="{{ __('landing.nav.menu') }}">
                <svg class="nav__toggle-open" viewBox="0 0 24 24" width="22" height="22" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="M4 7h16M4 12h16M4 17h16"/>
                </svg>
                <svg class="nav__toggle-close" viewBox="0 0 24 24" width="22" height="22" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="M6 6l12 12M18 6L6 18"/>
                </svg>
            </summary>

            <div class="nav__panel">
                @foreach ($links as $href => $label)
                    <a href="{{ $href }}">{{ $label }}</a>
                @endforeach

                <div class="nav__panel-actions">
                    <a href="{{ route('login') }}" class="btn btn--quiet">{{ __('landing.nav.login') }}</a>
                    <a href="{{ route('register') }}" class="btn btn--primary">{{ __('landing.nav.register') }}</a>
                </div>
            </div>
        </details>
    </div>
</header>

<main id="main">

@include('landing.sections.hero')
@include('landing.sections.trust')
@include('landing.sections.problems')
@include('landing.sections.imei')
@include('landing.sections.tour')
@include('landing.sections.pricing')
@include('landing.sections.faq')

{{-- The closing renders in two parts and the `</main>` boundary runs between them.

     The CTA is the page's primary conversion target, so it is content and belongs inside
     the main landmark — and the skip link at the top of this file targets `#main`, so a
     CTA outside it is a CTA a keyboard user is invited to skip. The site `<footer>` has
     the opposite requirement: inside `<main>` it stops being `contentinfo` at all.

     One `<div>` cannot straddle `</main>`, so the partial takes a `part` and both halves
     carry `.signoff` — one navy ground, no seam. Nothing may be inserted between these
     two includes. See landing/sections/closing.blade.php. --}}
@include('landing.sections.closing', ['part' => 'call'])

</main>

@include('landing.sections.closing', ['part' => 'tail'])

</body>
</html>
