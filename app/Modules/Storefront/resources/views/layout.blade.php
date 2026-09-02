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
    {{-- A price list is a private figure sent to one colleague. Keep it out of search. --}}
    <meta name="robots" content="@yield('robots', 'noindex, nofollow')">
    <title>@yield('title', 'فروشگاه')</title>
    <style>
        :root {
            --ink: #1d1d1f;
            --ink-soft: #6e6e73;
            --brand: #0066cc;
            --canvas: #ffffff;
            --canvas-alt: #f5f5f7;
            --line: #e5e5e7;
            --success: #0f7b3f;
            --danger: #b3261e;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Vazirmatn, system-ui, -apple-system, 'Segoe UI', sans-serif;
            font-size: 16px;
            line-height: 1.65;
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

        h1 { font-size: 1.5rem; margin: 0 0 .35rem; }
        h2 { font-size: 1.1rem; margin: 1.75rem 0 .75rem; }
        p  { margin: .35rem 0; }

        .muted { color: var(--ink-soft); font-size: .925rem; }

        .actions { display: flex; flex-wrap: wrap; gap: .5rem; margin-block-start: 1rem; }

        .btn {
            display: inline-flex; align-items: center; gap: .4rem;
            padding: .6rem 1.1rem; border-radius: 999px;
            background: var(--brand); color: #fff;
            text-decoration: none; font-size: .95rem; border: 0; cursor: pointer;
            min-height: 40px;
        }

        .btn.ghost { background: transparent; color: var(--brand); border: 1px solid var(--line); }
        .btn.wa { background: #25d366; }

        table { width: 100%; border-collapse: collapse; background: var(--canvas); }

        thead th {
            text-align: start; font-weight: 600; font-size: .85rem;
            color: var(--ink-soft); padding: .7rem .75rem;
            border-block-end: 1px solid var(--line); background: var(--canvas-alt);
        }

        tbody td { padding: .7rem .75rem; border-block-end: 1px solid var(--line); }
        tbody tr:last-child td { border-block-end: 0; }

        /* Financial figures are tabular and Latin-digit, matching the app's tables. */
        .num { font-variant-numeric: tabular-nums; white-space: nowrap; }

        .pill { font-size: .75rem; padding: .15rem .5rem; border-radius: 999px; }
        .pill.in { background: rgba(15,123,63,.1); color: var(--success); }
        .pill.out { background: var(--canvas-alt); color: var(--ink-soft); }

        .card {
            background: var(--canvas); border: 1px solid var(--line);
            border-radius: 18px; padding: 1.5rem; text-align: center;
        }

        .notice {
            background: var(--canvas); border: 1px solid var(--line);
            border-radius: 12px; padding: .85rem 1rem; margin-block-end: 1rem;
            font-size: .9rem; color: var(--ink-soft);
        }

        input[type="password"] {
            width: 100%; padding: .65rem .8rem; font: inherit;
            border: 1px solid var(--line); border-radius: 12px; margin-block: .75rem;
        }

        .err { color: var(--danger); font-size: .9rem; }

        /* A4 with a 10mm margin, matching the app's `PrintLayout.A4`. Without a
           document-level `@page` the price list printed on whatever paper the browser
           defaulted to — Letter on some machines — and the table's columns reflowed. */
        @page { size: A4; margin: 10mm; }

        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .wrap { max-width: none; padding: 0; }
            table { font-size: .85rem; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        @yield('content')
    </div>
</body>
</html>
