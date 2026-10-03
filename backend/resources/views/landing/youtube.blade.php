{{-- YouTube facade (Fase 7.3): thumbnail first, youtube-nocookie player only on tap (or when it
     scrolls into view with autoplay, muted). Shorts are 9:16. Params: $e (Embed::resolve), $title,
     $autoplay, $square. --}}
<button type="button" class="yt{{ $e['vertical'] ? ' vertical' : '' }}{{ !empty($square) ? ' square' : '' }}" data-yt="{{ $e['id'] }}"
        @if (!empty($e['start'])) data-start="{{ (int) $e['start'] }}" @endif @if (!empty($autoplay)) data-autoplay="1" @endif
        aria-label="Putar video{{ !empty($title) ? ' ' . $title : '' }}">
    <img src="https://i.ytimg.com/vi/{{ $e['id'] }}/hqdefault.jpg" alt="" loading="lazy" decoding="async"><span></span>
</button>
