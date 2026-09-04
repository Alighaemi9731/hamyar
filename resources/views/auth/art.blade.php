{{--
    The artwork column.

    Until the commissioned drawing lands at `resources/brand/auth-art.svg` (ordered with
    the brief in `docs/brand/auth-art-brief.md`), this shows the product itself: the same
    real dashboard capture the landing's fold uses, in the same frame. It is honest, it is
    already on brand, and it cannot look like a placeholder — which a grey box would.

    Swapping in the drawing is dropping the file in; nothing here changes.
--}}
@if (file_exists(resource_path('brand/auth-art.svg')))
    {!! Illuminate\Support\Facades\File::get(resource_path('brand/auth-art.svg')) !!}
@else
    <div class="frame">
        <div class="frame__bar"><span></span><span></span><span></span></div>
        <picture>
            <source srcset="{{ Illuminate\Support\Facades\Vite::asset('resources/landing/shots/dashboard.webp') }} 1x,
                            {{ Illuminate\Support\Facades\Vite::asset('resources/landing/shots/dashboard@2x.webp') }} 2x"
                    type="image/webp">
            <img src="{{ Illuminate\Support\Facades\Vite::asset('resources/landing/shots/dashboard.webp') }}"
                 width="1440" height="900" loading="lazy" decoding="async" alt="">
        </picture>
    </div>
@endif
