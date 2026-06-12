@extends('emails.partials.hellom-layout')

@section('content')
    <h1 style="margin:0 0 12px;font-size:26px;line-height:1.2;color:#111827;">{{ $heading }}</h1>
    <p style="margin:0 0 18px;font-size:15px;line-height:1.75;color:#374151;white-space:pre-line;">{{ $body }}</p>
    @if($ctaLabel && $ctaUrl)
        <a href="{{ $ctaUrl }}" style="display:inline-block;padding:12px 18px;background:#111827;color:#ffffff;text-decoration:none;border-radius:12px;font-weight:700;">
            {{ $ctaLabel }}
        </a>
    @endif
@endsection
