{{--
  The printable sheet — the same rows, the same query, the same gates.

  It is HTML rather than a generated binary so "the PDF matches the web list exactly" is
  true by construction: every browser turns this into a PDF on one tap, and there is no
  second renderer to disagree with the first.
--}}
@extends('storefront::layout')

@section('title', 'لیست قیمت — ' . $shop)

@section('content')
    <header class="shop">
        <h1>{{ $shop }}</h1>
        <p class="muted">لیست قیمت {{ $level }} · اعتبار تا {{ jalali($expires_at) }}</p>
    </header>

    <p class="no-print muted">
        برای ذخیره به‌صورت PDF، از گزینهٔ «چاپ» مرورگر استفاده کنید و مقصد را روی
        «ذخیره به‌صورت PDF» بگذارید.
    </p>

    <table>
        <thead>
            <tr>
                <th>کالا</th>
                <th>برند</th>
                <th>قیمت</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row['product'] }}{{ $row['variant'] ? ' — ' . $row['variant'] : '' }}</td>
                    <td>{{ $row['brand'] ?: '—' }}</td>
                    <td class="num">{{ $row['price_formatted'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{--
      `nonce` is load-bearing. `SecurityHeaders` emits a nonce-based `script-src`, and an
      inline script without one is refused by the browser — silently, as a console line
      nobody reads. So this page's one purpose never happened: a visitor who pressed
      «نسخهٔ چاپی» landed on a page that just sat there. Found by a browser sweep that
      reads the console; a Pest render cannot see a CSP refusal.
    --}}
    <script nonce="{{ Illuminate\Support\Facades\Vite::cspNonce() }}">
        // Opens the print dialogue on arrival, because this URL is only ever reached by
        // somebody who pressed «نسخهٔ چاپی». Guarded so it never fires during a test render.
        if (!navigator.webdriver) { window.addEventListener('load', () => window.print()); }
    </script>
@endsection
