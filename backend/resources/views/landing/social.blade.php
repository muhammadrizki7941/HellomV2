{{-- Social media panel (Fase 4): one row of icons near the profile. URLs come from SocialLinks::normalize. --}}
@php
    $platforms = \App\Support\Landing\SocialLinks::PLATFORMS;
    $icons = \App\Support\Landing\SocialLinks::ICONS;
    $mode = $social['color'] ?? 'mono';
    $custom = $mode === 'custom' && !empty($social['customColor']) ? $social['customColor'] : null;
@endphp
<nav class="social-row {{ $social['size'] ?? 'md' }} {{ $mode }}" aria-label="Sosial media" @if($custom) style="--soc:{{ $custom }}" @endif>
    @foreach ($social['items'] as $item)
        @php [$label, $brand] = $platforms[$item['platform']]; @endphp
        <a href="{{ $item['url'] }}" @if(!str_starts_with($item['url'], 'mailto:')) target="_blank" rel="noopener me" @endif aria-label="{{ $label }}" title="{{ $label }}"
           data-track="click" data-label="{{ $label }}" @if($mode === 'brand') style="--soc:{{ $brand }};--soc-fg:{{ $item['platform'] === 'snapchat' ? '#000' : '#fff' }}" @endif>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icons[$item['platform']] !!}</svg>
        </a>
    @endforeach
</nav>
