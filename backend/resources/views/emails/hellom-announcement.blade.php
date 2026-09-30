@extends('emails.partials.hellom-layout')

@section('content')
    <h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;font-weight:800;color:#0a0a0a;">{{ $heading }}</h1>
    <p style="margin:0;white-space:pre-line;">{{ $body }}</p>
    @if($ctaLabel && $ctaUrl)
        @include('emails.partials.button', ['url' => $ctaUrl, 'label' => $ctaLabel])
    @endif
@endsection
