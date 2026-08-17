{{--
  410 Gone: the link was real and is now closed. Distinct from a 404, because «منقضی شده»
  tells the colleague to ask for a new one instead of wondering whether they mistyped it.
--}}
@extends('storefront::layout')

@section('title', 'لیست قیمت — ' . $shop)

@section('content')
    <div class="card" style="max-width:26rem;margin-inline:auto;margin-block-start:3rem">
        <h1>{{ $shop }}</h1>

        @if ($revoked)
            <p>این لینک توسط فروشگاه باطل شده است.</p>
        @else
            <p>مدت اعتبار این لینک تمام شده است.</p>
        @endif

        <p class="muted">برای دریافت لیست جدید با فروشگاه تماس بگیرید.</p>
    </div>
@endsection
