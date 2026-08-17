{{-- The reseller list. Reached only through a live token that cleared its password. --}}
@extends('storefront::layout')

@section('title', 'لیست قیمت همکار — ' . $shop)

@section('content')
    <header class="shop">
        <h1>{{ $shop }}</h1>
        <p class="muted">لیست قیمت {{ $level }}</p>
        <p class="muted">
            اعتبار این لیست تا {{ jalali($expires_at) }}
        </p>

        <div class="actions no-print">
            <a class="btn ghost" href="{{ route('storefront.price-list.print', ['token' => $token]) }}">
                نسخهٔ چاپی / PDF
            </a>
        </div>
    </header>

    <div class="notice no-print">
        این لیست مخصوص همکاران است. لطفاً آن را بازنشر نکنید — هر بار باز شدن این لینک برای
        فروشگاه ثبت می‌شود.
    </div>

    @if (count($rows) === 0)
        <div class="card">
            <p>در این لیست فعلاً کالایی ثبت نشده است.</p>
        </div>
    @else
        <table>
            <thead>
                <tr>
                    <th>کالا</th>
                    <th>برند</th>
                    <th>قیمت {{ $level }}</th>
                    <th>وضعیت</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>
                            {{ $row['product'] }}
                            @if ($row['variant'])
                                <span class="muted">— {{ $row['variant'] }}</span>
                            @endif
                        </td>
                        <td>{{ $row['brand'] ?: '—' }}</td>
                        <td class="num">{{ $row['price_formatted'] }}</td>
                        <td>
                            @if ($row['in_stock'])
                                <span class="pill in">موجود</span>
                            @else
                                <span class="pill out">ناموجود</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
