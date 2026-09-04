{{--
    Shell for the public auth pages.

    Blade rather than Inertia, like the landing and the legal pages: these are read by
    people with no session, they must match the landing exactly, and the landing's design
    language lives in a Blade stylesheet.

    The artwork slot holds our own thermal receipt — the object this product is about,
    already built in CSS, and the thing a shopkeeper recognises.
--}}
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') — سامانه همیار</title>
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#F7F9FB">
    @vite(['resources/landing/landing.css', 'resources/landing/landing.js'])
</head>
<body>

<div class="auth">
    <div class="auth__card">
        <a href="/" class="auth__brand">
            <svg viewBox="0 0 32 32" fill="none" aria-hidden="true">
                <rect x="6.5" y="2.5" width="19" height="27" rx="4" stroke="#0E1B2C" stroke-width="2"/>
                <path d="M12 24.5h8" stroke="#0066CC" stroke-width="2" stroke-linecap="round"/>
            </svg>
            همیار
        </a>

        <div class="auth__body">
            {{-- Artwork: inline-start half, matching the reference's placement. --}}
            <div class="auth__art" aria-hidden="true">
                <div class="receipt">
                    <div class="receipt__head">
                        <div class="receipt__shop">همیار</div>
                        <div class="receipt__kind">قبض پذیرش تعمیر</div>
                    </div>
                    <dl>
                        <div class="receipt__line"><dt>شماره قبض</dt><dd>REP-۰۰۰۱۸۴</dd></div>
                        <div class="receipt__line"><dt>تاریخ</dt><dd>۱۴۰۵/۰۵/۲۹</dd></div>
                        <div class="receipt__line"><dt>دستگاه</dt><dd>اپل آیفون ۱۳</dd></div>
                        <div class="receipt__line"><dt>IMEI</dt><dd>۳۵۴۸۷۹۱۱۶۲۳۴۹۰۱</dd></div>
                        <div class="receipt__line"><dt>ایراد</dt><dd>شکستگی گلس</dd></div>
                    </dl>
                    <div class="receipt__rule"></div>
                    <p class="receipt__act">— دستگاه آمادهٔ تحویل شد —</p>
                    <p class="receipt__sms">«همیار — دستگاه شما آمادهٔ تحویل است.»</p>
                    <div class="receipt__rule"></div>
                    <div class="receipt__total"><span>تسویه</span><span>۳۲۰٬۰۰۰ تومان</span></div>
                </div>
            </div>

            <div class="auth__form">
                <h1 class="auth__title">@yield('heading')</h1>
                @yield('subheading')

                {{--
                    The home for errors that belong to no field.

                    CLAUDE.md keeps this as a rule because of a real bug: a validation
                    failure with nowhere to render beside an input makes the submit button
                    appear to do nothing, and the person presses it again and concludes the
                    software is broken. Every key of the bag is rendered, not only the ones
                    a field was placed for.
                --}}
                @if ($errors->any())
                    <div class="auth__alert" role="alert">
                        <ul style="margin:0;padding-inline-start:1.1rem;list-style:disc">
                            @foreach ($errors->all() as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @yield('form')
            </div>
        </div>
    </div>
</div>

</body>
</html>
