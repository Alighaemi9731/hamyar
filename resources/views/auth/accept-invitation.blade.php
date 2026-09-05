@extends('auth.layout')

@section('title', __('auth.invitation.title', ['name' => $name]))
@section('heading', __('auth.invitation.title', ['name' => $name]))

@section('subheading')
    <p class="auth__hint">{{ __('auth.invitation.hint') }}</p>

    {{--
        The number the shop invited, shown back so the person can tell before choosing a
        password that this link is for them.

        `<bdi dir="ltr">` and not a bare `{{ }}`: a phone number is Latin, LTR-isolated
        and ungrouped (design-system rule 4). Dropped into RTL prose without the isolate,
        a leading zero migrates to the wrong end of the run and the number reads back
        wrong to the one person on earth who would notice — its owner.
    --}}
    <p class="auth__hint">
        {{ __('auth.invitation.mobile') }}:
        <bdi dir="ltr">{{ $mobile }}</bdi>
    </p>
@endsection

@section('form')
    {{--
        The token is in the ACTION, and deliberately nowhere in the body.

        It is a path parameter because `tenant.public:invitation` reads it as one to pin
        the shop that issued the invitation before any controller runs (ADR 0017) — a
        token in the body could not pin anything.

        It is kept OUT of the posted fields because a failed password validation flashes
        the request body into `sessions.payload` in clear (see `App\Support\SensitiveInput`),
        and this token is a live bearer credential: a working invitation link. The React
        page this replaces had to strip it in a `transform()` for the same reason; here
        there is simply nothing to strip.

        Its refusal — "this invitation is invalid or expired" — comes back under the
        `token` key with no field of its own anywhere on the page. The layout's error
        region is what renders it, which is golden rule 6 doing the job it exists for.
    --}}
    <form method="POST" action="{{ route('invitations.store', ['token' => $token]) }}" novalidate>
        @csrf

        <div class="auth__fields">
            <div class="auth__field">
                <label for="password" class="auth__sr">{{ __('auth.invitation.password') }}</label>
                <input id="password" name="password" type="password"
                       class="auth__input auth__input--ltr auth__input--reveal"
                       placeholder="{{ __('auth.invitation.password') }}" required autofocus
                       autocomplete="new-password"
                       @error('password') aria-invalid="true" @enderror>
                @include('auth.reveal', ['field' => 'password', 'label' => __('auth.invitation.password')])
            </div>

            <div class="auth__field">
                <label for="password_confirmation" class="auth__sr">{{ __('auth.invitation.confirmation') }}</label>
                <input id="password_confirmation" name="password_confirmation" type="password"
                       class="auth__input auth__input--ltr auth__input--reveal"
                       placeholder="{{ __('auth.invitation.confirmation') }}" required
                       autocomplete="new-password">
                {{-- Its own label, so a screen reader can tell the two eyes apart. --}}
                @include('auth.reveal', ['field' => 'password_confirmation', 'label' => __('auth.invitation.confirmation')])
            </div>
        </div>

        <button type="submit" class="btn btn--primary auth__submit">{{ __('auth.invitation.submit') }}</button>
    </form>
@endsection
