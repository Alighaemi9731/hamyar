{{--
  The public shell. Blade and inline CSS, no framework, no build step.

  These pages open on an Iranian mobile connection, often on an old phone, and often over a
  link forwarded through WhatsApp. The design brief's budget for public pages assumes no
  React here, and a price list that takes four seconds to paint is one a colleague closes.

  RTL is set on <html>, and every rule below uses logical properties (`margin-inline`,
  `text-align: start`) so the sheet reads correctly in both directions — the same rule the
  app's Tailwind side enforces (golden rule 9).
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
    {{-- A price list is a private figure sent to one colleague. Keep it out of search. --}}
    <meta name="robots" content="@yield('robots', 'noindex, nofollow')">
    <title>@yield('title', 'فروشگاه')</title>
    <style>
        /*
          The brand, restated — because this sheet is standalone by design (see the note
          below) and no `@theme` reaches it.

          It was still on the RETIRED neutral palette: `#1d1d1f` ink and `#f5f5f7` tint,
          the shadcn defaults ADR 0008 inherited and ADR 0020 replaced with navy across 76
          pages on 2026-09-04. That ADR says outright that anything still hardcoding those
          two "would now be visibly out of family" — and this file did, on the one surface
          a shop's *customer* sees. It was missed for the same reason its typeface was:
          the sweep followed the token layer, and this page is not on it.

          Hairlines are alpha of the ink rather than a neutral grey, per the same ADR: a
          grey rule under navy text is exactly where a mixed palette shows, and on a price
          list it shows on every row.

          **These values are a copy and must be kept in step with `resources/css/brand.css`.**
          There is no guard for it. If you change ink, tint or hairline there, change them
          here in the same commit.
        */
        :root {
            --ink: #0e1b2c;        /* 17.3:1 on white */
            --ink-soft: #46586d;   /* 7.3:1 on white */
            --brand: #0066cc;
            --canvas: #ffffff;
            --canvas-alt: #f2f5f9;
            --line: rgb(14 27 44 / 0.1);
            --success: #0f7b3f;
            --danger: #b3261e;

            /*
              The type ramp, also a copy of `brand.css` and also to be kept in step.
              Ten literal sizes lived below — 16px, 1.1rem, .925rem, .95rem, .9rem, .85rem,
              .75rem — a private scale with seven steps, none of which is a step this
              product uses anywhere else.

              The 14px floor is the one that matters here. A forwarded price list is read
              on a phone, in a message thread, by somebody who did not ask for it; .75rem
              Persian in that setting is not small type, it is unread type.
            */
            --t-fine: 0.875rem;    /* 14 */
            --t-sm: 0.9375rem;     /* 15 */
            --t-base: 1.0625rem;   /* 17 — body */
            --t-lg: 1.3125rem;     /* 21 */
            --t-xl: 1.75rem;       /* 28 */
        }

        * { box-sizing: border-box; }

        /*
          Both faces of the pairing, declared here because this sheet is standalone by
          design (see the note at the top) and therefore has no access to `fonts.css`.

          It named `Vazirmatn` in its font stack and never loaded it, so every price list
          a shop has ever forwarded rendered in the system font — Arial on Windows — while
          claiming otherwise. **Estedad was missing outright**, so the headings on this page
          were set in the body face while every other heading in the product is in the
          display one (ADR 0020, amended 2026-09-05: nothing but Estedad renders a heading).

          These are the same hashed files the landing and the app serve, so a colleague who
          has opened either already has them cached; on a cold visit `swap` paints the
          system font first and exchanges it, which is the right trade on the connection
          this page is written for. Estedad is loaded for the Arabic range only — the
          headings here are Persian, and a Latin display face nothing renders is bytes.
        */
        @font-face {
            font-family: 'Vazirmatn';
            font-style: normal;
            font-weight: 400 600;
            font-display: swap;
            src: url('{{ Illuminate\Support\Facades\Vite::asset('resources/fonts/vazirmatn-arabic-wght-normal.woff2') }}') format('woff2');
            unicode-range: U+0600-06FF, U+0750-077F, U+08A0-08FF, U+200C-200F, U+FB50-FDFF, U+FE70-FEFF;
        }

        @font-face {
            font-family: 'Vazirmatn';
            font-style: normal;
            font-weight: 400 600;
            font-display: swap;
            src: url('{{ Illuminate\Support\Facades\Vite::asset('resources/fonts/vazirmatn-latin-wght-normal.woff2') }}') format('woff2');
            unicode-range: U+0000-00FF, U+2000-206F, U+20AC;
        }

        @font-face {
            font-family: 'Estedad';
            font-style: normal;
            font-weight: 700 800;
            font-display: swap;
            src: url('{{ Illuminate\Support\Facades\Vite::asset('resources/fonts/estedad-arabic-wght-normal.woff2') }}') format('woff2');
            unicode-range: U+0600-06FF, U+0750-077F, U+08A0-08FF, U+200C-200F, U+FB50-FDFF, U+FE70-FEFF;
        }

        body {
            margin: 0;
            font-family: 'Vazirmatn', system-ui, -apple-system, 'Segoe UI', sans-serif;
            font-size: var(--t-base);
            line-height: 1.7;
            color: var(--ink);
            background: var(--canvas-alt);
        }

        .wrap { max-width: 60rem; margin-inline: auto; padding: 1.5rem 1rem 4rem; }

        header.shop {
            background: var(--canvas);
            border-radius: 18px;
            padding: 1.5rem;
            margin-block-end: 1.25rem;
            border: 1px solid var(--line);
        }

        /* Headings take the display face, like every other heading in this product. */
        h1, h2 { font-family: 'Estedad', 'Vazirmatn', system-ui, sans-serif; letter-spacing: -0.02em; }
        h1 { font-size: var(--t-xl); font-weight: 800; line-height: 1.2; margin: 0 0 .35rem; }
        h2 { font-size: var(--t-lg); font-weight: 700; margin: 1.75rem 0 .75rem; }
        p  { margin: .35rem 0; }

        .muted { color: var(--ink-soft); font-size: var(--t-sm); }

        .actions { display: flex; flex-wrap: wrap; gap: .5rem; margin-block-start: 1rem; }

        .btn {
            display: inline-flex; align-items: center; gap: .4rem;
            padding: .6rem 1.1rem; border-radius: 999px;
            background: var(--brand); color: #fff;
            text-decoration: none; font-size: var(--t-sm); border: 0; cursor: pointer;
            min-height: 40px;
        }

        .btn.ghost { background: transparent; color: var(--brand); border: 1px solid var(--line); }
        .btn.wa { background: #25d366; }

        table { width: 100%; border-collapse: collapse; background: var(--canvas); }

        thead th {
            text-align: start; font-weight: 600; font-size: var(--t-fine);
            color: var(--ink-soft); padding: .7rem .75rem;
            border-block-end: 1px solid var(--line); background: var(--canvas-alt);
        }

        tbody td { padding: .7rem .75rem; border-block-end: 1px solid var(--line); }
        tbody tr:last-child td { border-block-end: 0; }

        /* Financial figures are tabular and Latin-digit, matching the app's tables. */
        .num { font-variant-numeric: tabular-nums; white-space: nowrap; }

        .pill { font-size: var(--t-fine); padding: .15rem .5rem; border-radius: 999px; }
        .pill.in { background: rgba(15,123,63,.1); color: var(--success); }
        .pill.out { background: var(--canvas-alt); color: var(--ink-soft); }

        .card {
            background: var(--canvas); border: 1px solid var(--line);
            border-radius: 18px; padding: 1.5rem; text-align: center;
        }

        .notice {
            background: var(--canvas); border: 1px solid var(--line);
            border-radius: 12px; padding: .85rem 1rem; margin-block-end: 1rem;
            font-size: var(--t-sm); color: var(--ink-soft);
        }

        input[type="password"] {
            width: 100%; padding: .65rem .8rem; font: inherit;
            border: 1px solid var(--line); border-radius: 12px; margin-block: .75rem;
        }

        .err { color: var(--danger); font-size: var(--t-sm); }

        /* A4 with a 10mm margin, matching the app's `PrintLayout.A4`. Without a
           document-level `@page` the price list printed on whatever paper the browser
           defaulted to — Letter on some machines — and the table's columns reflowed. */
        @page { size: A4; margin: 10mm; }

        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .wrap { max-width: none; padding: 0; }
            table { font-size: var(--t-fine); }
        }
    </style>
</head>
<body>
    <div class="wrap">
        @yield('content')
    </div>
</body>
</html>
