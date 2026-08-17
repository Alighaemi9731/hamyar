{{--
  The password gate. No prices are in this response — that is the point of rendering a
  separate view rather than hiding a table.
--}}
@extends('storefront::layout')

@section('title', 'لیست قیمت — ' . $shop)

@section('content')
    <div class="card" style="max-width:26rem;margin-inline:auto;margin-block-start:3rem">
        <h1>{{ $shop }}</h1>
        <p class="muted">این لیست با رمز محافظت شده است.</p>

        <form method="POST" action="{{ route('storefront.price-list.unlock', ['token' => $token]) }}">
            @csrf
            <input type="password" name="password" placeholder="رمز" autocomplete="off" autofocus required>

            @if ($error)
                <p class="err">{{ $error }}</p>
            @endif

            <button class="btn" type="submit" style="width:100%;justify-content:center">
                نمایش لیست
            </button>
        </form>

        <p class="muted" style="margin-block-start:1rem;font-size:.85rem">
            رمز را از همان فروشگاهی بگیرید که لینک را برایتان فرستاده است.
        </p>
    </div>
@endsection
