{{--
    Root document for the Inertia app.

    `dir="rtl"` lives here, on the html element, so every child inherits it and
    logical utilities (ms-/me-/ps-/pe-) resolve correctly. Radix portals escape the
    React tree, so they take an explicit `dir` of their own — see
    resources/js/components/ui/* (design-system rule 2).
--}}
<!DOCTYPE html>
{{--
    No theme class is rendered here. Nothing in this application has ever written a
    `theme` cookie, so the `@if(request()->cookie('theme') === 'dark')` that used to sit
    on this tag could never be true — it was dead code that read like the mechanism, next
    to the script below that actually is it. The theme is decided client-side, from
    localStorage, before first paint.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title inertia>{{ config('app.name', 'سامانه همیار') }}</title>

    {{--
        The browser chrome, painted to match the page ground.

        These were `#F7F9FB` and `#0A1420`, which are not colours this design system has:
        they are leftovers from a palette that predates ADR 0008. `--color-canvas` is
        `#ffffff` and the dark `--background` is `#000000`, so on a phone the address bar
        sat a visible step away from the page it was framing — a near-white bar above a
        white page, and a navy bar above a black one.

        Kept as literals deliberately: `<meta>` takes no `var()`, and the alternative is
        inlining the stylesheet's values from PHP, which is a second source of truth for
        the same two colours. If the ground changes, these change with it — the comment
        beside the tokens in `app.css` says so.
    --}}
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#ffffff">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#000000">

    {{--
        Applies the stored theme before first paint. Without this the page renders
        light and then repaints dark, which is the flash every dark-mode user hates.
    --}}
    <script nonce="{{ Illuminate\Support\Facades\Vite::cspNonce() }}">
        (function () {
            try {
                var stored = localStorage.getItem('hamyar.theme');
                var dark = stored === 'dark' || (stored === null && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.classList.toggle('dark', dark);
            } catch (e) { /* private mode: fall back to the light default */ }
        })();
    </script>

    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="min-h-dvh bg-background font-sans text-foreground antialiased">
    @inertia
</body>
</html>
