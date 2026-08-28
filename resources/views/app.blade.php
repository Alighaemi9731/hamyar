{{--
    Root document for the Inertia app.

    `dir="rtl"` lives here, on the html element, so every child inherits it and
    logical utilities (ms-/me-/ps-/pe-) resolve correctly. Radix portals escape the
    React tree, so they take an explicit `dir` of their own — see
    resources/js/components/ui/* (design-system rule 2).
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl" class="@if(request()->cookie('theme') === 'dark') dark @endif">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title inertia>{{ config('app.name', 'سامانه همیار') }}</title>

    {{-- Matches the light/dark ground so a theme switch does not flash white. --}}
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#F7F9FB">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0A1420">

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
