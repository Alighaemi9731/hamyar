{{--
    Shell for the public auth pages.

    Blade rather than Inertia, like the landing and the legal pages: these are read by
    people with no session, they must match the landing exactly, and the landing's design
    language lives in a Blade stylesheet.

    ## What changed on 2026-09-04

    The whole page used to sit inside one 1400px white card floating on a tinted ground,
    with the form naked inside it and a CSS thermal receipt beside it. The owner asked for
    the shape of a reference sign-in they had picked out: **no page-level box**, the tinted
    ground bare, the brand lockup loose at the reading start, and **the form in a card of
    its own** on one side with the artwork on the other.

    So: `.auth` is the ground, `.auth__brand` sits on it, `.auth__panel` is the card that
    holds the form, and `.auth__art` is the other column with nothing drawn around it.
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
    <title>@yield('title') — همیار</title>
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#F2F5F9">
    @vite(['resources/landing/landing.css', 'resources/landing/landing.js'])
</head>
<body>

<div class="auth">
    <a href="/" class="auth__brand" aria-label="همیار — صفحهٔ نخست">
        {!! Illuminate\Support\Facades\File::get(resource_path('brand/wordmark.svg')) !!}
    </a>

    <div class="auth__grid">
        {{--
            The artwork is declared FIRST. In RTL the first grid column is the right-hand
            one, which is where the owner asked for it and where the reference puts its
            illustration; the form card takes the other side.
        --}}
        <div class="auth__art" aria-hidden="true">
            @include('auth.art')
        </div>

        <div class="auth__panel">
            <h1 class="auth__title">@yield('heading')</h1>
            @yield('subheading')

            {{--
                The home for errors that belong to no field.

                CLAUDE.md keeps this as a rule because of a real bug: a validation failure
                with nowhere to render beside an input makes the submit button appear to do
                nothing, and the person presses it again and concludes the software is
                broken. Every key of the bag is rendered, not only the ones a field was
                placed for.
            --}}
            @if ($errors->any())
                <div class="auth__alert" role="alert">
                    <ul>
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('form')

            <p class="auth__secure">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>
                </svg>
                ارتباط رمزگذاری‌شده — اطلاعات هر فروشگاه در پایگاه داده از بقیه جداست.
            </p>
        </div>

    </div>
</div>

</body>
</html>
