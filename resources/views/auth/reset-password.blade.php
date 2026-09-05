@extends('auth.layout')

@section('title', __('auth.reset.title'))
@section('heading', __('auth.reset.title'))

@section('subheading')
    <p class="auth__hint">{{ __('auth.reset.hint') }}</p>
@endsection

@section('form')
    <form method="POST" action="{{ route('password.update') }}" novalidate>
        @csrf

        {{--
            BOTH of these travel, and the second one is the reason this comment exists.

            `PasswordResetController::update()` validates `token`, `identifier` and
            `password`. The React page this replaced carried two of the three: a reset
            link that loses its `identifier` — an SMS truncated at the `&`, a URL
            half-copied out of a message — arrived with an empty one, rendered normally,
            accepted a new password and did nothing at all on submit.

            Straight from the controller rather than through `old()`: `update()` refuses
            with `back()->withErrors()` and no `withInput()`, so nothing is flashed, and
            the redirect returns to this page's own query string with both values still
            on it. `old()` here would be empty on exactly the failure it looks like it
            protects.

            Their refusals have no field to sit beside — that is what the layout's error
            region is for, and why golden rule 6 makes it unconditional.
        --}}
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="identifier" value="{{ $identifier }}">

        <div class="auth__fields">
            <div class="auth__field">
                <label for="password" class="auth__sr">{{ __('auth.reset.password') }}</label>
                <input id="password" name="password" type="password"
                       class="auth__input auth__input--ltr auth__input--reveal"
                       placeholder="{{ __('auth.reset.password') }}" required autofocus
                       autocomplete="new-password"
                       @error('password') aria-invalid="true" @enderror>
                @include('auth.reveal', ['field' => 'password', 'label' => __('auth.reset.password')])
            </div>

            <div class="auth__field">
                <label for="password_confirmation" class="auth__sr">{{ __('auth.reset.confirmation') }}</label>
                <input id="password_confirmation" name="password_confirmation" type="password"
                       class="auth__input auth__input--ltr auth__input--reveal"
                       placeholder="{{ __('auth.reset.confirmation') }}" required
                       autocomplete="new-password">
                {{-- Its own label, so a screen reader can tell the two eyes apart. --}}
                @include('auth.reveal', ['field' => 'password_confirmation', 'label' => __('auth.reset.confirmation')])
            </div>
        </div>

        <button type="submit" class="btn btn--primary auth__submit">{{ __('auth.reset.submit') }}</button>

        <p class="auth__alt">
            <a href="{{ route('login') }}">{{ __('auth.reset.back') }}</a>
        </p>
    </form>
@endsection
