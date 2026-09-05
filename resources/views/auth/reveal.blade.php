{{--
    The show/hide control on a password field. `@include('auth.reveal', ['field' => 'password'])`

    ## Why it is a partial

    Three copies of it existed — login once, register twice — and the register pair shared
    one `aria-label`, so a screen reader read the same «نمایش رمز عبور» for the password and
    for its repeat with nothing to tell them apart. One file, one `$label`.

    ## Why it starts hidden

    `hidden` in the markup; `landing.js` clears it once it has bound the click.

    The owner's report on 2026-09-05 was that this button does not work, and it half did:
    the click flipped the input's `type`, but the icon never changed, there was no pressed
    state, and — the reason it reads as dead — pressing it over an EMPTY field changed
    nothing on the screen at all. A control that cannot report its own state is a control
    that did nothing, and the first thing anybody does on a form they are reviewing is press
    the button before typing.

    So: two icons, one per state, swapped by CSS off `aria-pressed`; and the whole control is
    absent rather than inert if the script never arrives. `type="button"` stays — with the
    script gone AND the attribute wrong, the eye would submit a half-typed password.
--}}
@php($label = $label ?? 'رمز عبور')
<button type="button" class="auth__reveal" data-reveal="{{ $field }}" aria-controls="{{ $field }}"
        aria-pressed="false" aria-label="نمایش {{ $label }}"
        data-label-show="نمایش {{ $label }}" data-label-hide="پنهان کردن {{ $label }}" hidden>
    {{-- Hidden state: the eye is struck through, as it is on the sign-in the owner picked. --}}
    <svg class="auth__reveal-icon auth__reveal-icon--hidden" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"
         aria-hidden="true">
        <path d="M9.9 5.2A9.9 9.9 0 0 1 12 5c6.5 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.2"/>
        <path d="M6.3 6.4A17.3 17.3 0 0 0 2 12s3.5 7 10 7a9.9 9.9 0 0 0 4.2-.9"/>
        <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>
        <path d="M3 3l18 18"/>
    </svg>
    {{-- Shown state: the plain eye, and the label becomes «پنهان کردن …». --}}
    <svg class="auth__reveal-icon auth__reveal-icon--shown" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"
         aria-hidden="true">
        <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/>
        <circle cx="12" cy="12" r="3"/>
    </svg>
</button>
