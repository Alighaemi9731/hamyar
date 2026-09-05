@extends('auth.layout')

@section('title', __('auth.two_factor.title'))
@section('heading', __('auth.two_factor.title'))

@section('subheading')
    {{-- `landing.js` rewrites this when the recovery field is switched on. It starts as
         the authenticator hint because that is the way through this screen that works
         for somebody who still has their phone. --}}
    <p class="auth__hint" data-two-factor-hint>{{ __('auth.two_factor.hint') }}</p>
@endsection

@section('form')
    <form method="POST" action="{{ route('two-factor.verify') }}" novalidate>
        @csrf

        {{--
            Both fields ship VISIBLE, and the script hides one.

            That is the opposite of the reveal button on the password fields, and for the
            opposite reason. The recovery field needs no script at all — it is a plain
            input on a plain POST, and `required_without:code` on the controller accepts
            whichever of the two arrives filled. So the script's job here is to tidy a
            working page down to one field, not to switch on a control that would
            otherwise be dead. With `landing.js` blocked this screen is two labelled
            fields and a submit: busier than intended, and not broken.

            The BUTTON below ships `hidden`, because it is the part that cannot work
            without the script.
        --}}
        <div class="auth__fields">
            <div class="auth__field" data-two-factor-field="code">
                <label for="code" class="auth__sr">{{ __('auth.two_factor.code') }}</label>
                {{-- Latin and LTR-isolated like every other typed identifier, and
                     normalised with `Digits::toLatin` server-side so a Persian keyboard
                     is accepted (design-system rule 4). --}}
                <input id="code" name="code" class="auth__input auth__input--ltr auth__input--code"
                       placeholder="{{ __('auth.two_factor.code') }}" autofocus inputmode="numeric"
                       autocomplete="one-time-code" maxlength="6" spellcheck="false"
                       @error('code') aria-invalid="true" @enderror>
            </div>

            <div class="auth__field" data-two-factor-field="recovery">
                <label for="recovery_code" class="auth__sr">{{ __('auth.two_factor.recovery') }}</label>
                <input id="recovery_code" name="recovery_code" class="auth__input auth__input--ltr"
                       placeholder="{{ __('auth.two_factor.recovery') }}" autocomplete="off"
                       spellcheck="false" autocapitalize="off"
                       @error('recovery_code') aria-invalid="true" @enderror>
            </div>
        </div>

        <button type="submit" class="btn btn--primary auth__submit">{{ __('auth.two_factor.submit') }}</button>

        {{--
            The switch, and the copy for both of its states.

            Persian travels on data attributes rather than living in `landing.js`, exactly
            as it does for the reveal control: the strings belong to `lang/fa/auth.php`,
            and a script that holds two of them is a second place to change the wording.
        --}}
        <p class="auth__alt">
            <button type="button" class="auth__toggle" data-two-factor-toggle
                    data-label-code="{{ __('auth.two_factor.use_app') }}"
                    data-label-recovery="{{ __('auth.two_factor.use_recovery') }}"
                    data-hint-code="{{ __('auth.two_factor.hint') }}"
                    data-hint-recovery="{{ __('auth.two_factor.recovery_hint') }}"
                    hidden>{{ __('auth.two_factor.use_recovery') }}</button>
        </p>
    </form>
@endsection
