@extends('layouts.admin-sidebar')

@section('header')
    <div class="flex items-center justify-between">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Tambah Promo/Event</h2>
        <a href="{{ route('admin.site-promotions.index') }}" class="text-sm text-gray-600 underline">Kembali</a>
    </div>
@endsection

@section('content')
    <div class="py-6">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @if($errors->any())
                <div class="mb-4 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-rose-800">
                    <div class="font-semibold">Periksa input</div>
                    <ul class="list-disc pl-5 mt-2">
                        @foreach($errors->all() as $err)
                            <li>{{ $err }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="bg-white shadow-sm rounded-2xl border border-gray-200 p-5">
                <form method="POST" action="{{ route('admin.site-promotions.store') }}" enctype="multipart/form-data" class="grid gap-5">
                    @csrf
                    @include('admin.site_promotions._form', ['promo' => null])

                    <div class="flex items-center justify-end gap-2">
                        <button type="submit" class="rounded-xl bg-gray-900 text-white px-5 py-3 font-semibold">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
