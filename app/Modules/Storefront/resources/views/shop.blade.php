{{-- The public shop page. Every action starts a conversation — no cart, by design. --}}
@extends('storefront::layout')

{{-- The one public page that SHOULD be indexed: it exists to be found. --}}
@section('robots', 'index, follow')
@section('title', ($settings->display_name ?: 'فروشگاه') . ' — لیست قیمت')

@section('content')
    <header class="shop">
        <h1>{{ $settings->display_name ?: 'فروشگاه موبایل' }}</h1>

        @if ($settings->about)
            <p class="muted">{{ $settings->about }}</p>
        @endif

        @if ($settings->address)
            <p class="muted">{{ $settings->address }}</p>
        @endif

        @if ($settings->working_hours)
            <p class="muted">ساعت کاری: {{ $settings->working_hours }}</p>
        @endif

        <div class="actions">
            @if ($settings->phone)
                {{-- `tel:` needs the raw digits; the label shows what a person reads. --}}
                <a class="btn" href="tel:{{ $settings->phone }}">
                    تماس تلفنی
                </a>
            @endif

            @if ($settings->whatsapp)
                {{--
                  wa.me wants the number without a leading + or zeros. `PhoneNumber` stored it
                  in canonical +98 form, so stripping the plus is the whole conversion.
                --}}
                <a class="btn wa" rel="noopener" target="_blank"
                   href="https://wa.me/{{ ltrim($settings->whatsapp, '+') }}">
                    واتس‌اپ
                </a>
            @endif
        </div>
    </header>

    <h2>محصولات</h2>

    @if (count($rows) === 0)
        <div class="card">
            <p>فعلاً محصولی برای نمایش ثبت نشده است.</p>
            <p class="muted">برای موجودی و قیمت روز تماس بگیرید.</p>
        </div>
    @else
        <table>
            <thead>
                <tr>
                    <th>کالا</th>
                    <th>برند</th>
                    <th>قیمت</th>
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
                            {{-- Coarse on purpose: never a count on a public page. --}}
                            @if ($row['in_stock'])
                                <span class="pill in">موجود</span>
                            @else
                                <span class="pill out">تماس بگیرید</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <p class="muted" style="margin-block-start:1rem">
            قیمت‌ها بدون اطلاع قبلی تغییر می‌کنند. برای ثبت سفارش تماس بگیرید.
        </p>
    @endif
@endsection
