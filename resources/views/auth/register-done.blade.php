@extends('auth.layout')

@section('title', 'فروشگاه ساخته شد')
@section('heading', 'فروشگاه شما ساخته شد')

@section('form')
    <p style="color:var(--color-navy-soft);margin-block-end:1.75rem">
        «{{ $shop }}» آمادهٔ استفاده است. برای شروع، با شمارهٔ موبایل و رمزی که همین حالا
        انتخاب کردید وارد شوید.
    </p>

    {{--
        A link, not a redirect.

        The shop is on another hostname, and no redirect out of a form POST can reach a
        different origin — `form-action 'self'` blocks it. A link navigation is not form
        submission and nothing governs it. See OnboardingController::store().
    --}}
    <a href="{{ $loginUrl }}" class="btn btn--primary auth__submit" style="text-decoration:none">
        ورود به فروشگاه
    </a>

    <p class="auth__alt" style="margin-block-start:1.5rem">
        <span style="color:var(--color-navy-mute)">نشانی فروشگاه شما:</span><br>
        <span class="nums" style="direction:ltr;display:inline-block;margin-block-start:0.375rem;word-break:break-all">{{ $loginUrl }}</span>
    </p>
@endsection
