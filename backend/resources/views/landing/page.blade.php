@extends('landing.layout')

@section('content')
    @foreach ($blocks as $b)
        @include('landing.block', ['b' => $b, 'first' => $loop->first])
    @endforeach
    @if (count($blocks) === 0)
        <section class="blk center"><div class="wrap"><h1>{{ $organization->name }}</h1><p class="muted">Halaman ini sedang disiapkan.</p></div></section>
    @endif
@endsection
