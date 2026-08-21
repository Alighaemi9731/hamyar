{{--
    Landing icon set.

    Inline SVG rather than an icon font or a sprite request: there are nine glyphs on
    this page, they are on the critical path, and a <use> sprite would be a second
    round trip for ~1KB of paths. Lucide geometry, drawn at 24 and scaled by `size`.

    `stroke="currentColor"` throughout, so one glyph serves the accent tiles, the navy
    plan card and the white-on-navy CTA without a second copy.

    @param string $name  one of the keys below
    @param int    $size  rendered box in px (default 20)
--}}
@php
    $size ??= 20;

    /**
     * The forward arrow points to the visual left because this document is `dir="rtl"`
     * and forward in Persian is leftward. It is not a mirrored LTR asset — there is no
     * LTR rendering of this page to mirror it back for.
     */
    $paths = [
        'scan' => '<path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M7 8v8M10.5 8v8M14 8v8M17 8v8"/>',
        'wrench' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        'calendar' => '<path d="M8 2v4M16 2v4M3 10h18"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M12 13v3l2 1"/>',
        'calendar-fa' => '<path d="M8 2v4M16 2v4M3 10h18"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/>',
        'message' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'trend' => '<path d="M16 7h6v6"/><path d="m22 7-8.5 8.5-5-5L2 17"/>',
        'store' => '<path d="M4 9.5V21h16V9.5"/><path d="M2 9.5 4 3h16l2 6.5a3 3 0 0 1-5 2.2 3 3 0 0 1-5 0 3 3 0 0 1-5 0 3 3 0 0 1-5-2.2Z"/><path d="M10 21v-5h4v5"/>',
        'check' => '<path d="m4 12.5 5 5L20 6.5"/>',
        'arrow' => '<path d="M19 12H5"/><path d="m12 19-7-7 7-7"/>',
    ];
@endphp
<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none"
     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
     aria-hidden="true" focusable="false">{!! $paths[$name] !!}</svg>
