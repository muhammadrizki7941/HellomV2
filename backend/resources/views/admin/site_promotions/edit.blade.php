@php
    /** @var \App\Models\SitePromotion $promo */
@endphp

@extends('layouts.admin-sidebar')

@section('header')
    <div class="flex items-center justify-between">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Edit Promo/Event</h2>
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
                <form method="POST" action="{{ route('admin.site-promotions.update', $promo) }}" enctype="multipart/form-data" class="grid gap-5">
                    @csrf
                    @method('PUT')

                    @include('admin.site_promotions._form', ['promo' => $promo])

                    <div class="flex items-center justify-end">
                        <button type="submit" class="rounded-xl bg-gray-900 text-white px-5 py-3 font-semibold">Simpan Perubahan</button>
                    </div>
                </form>

                <hr class="my-5">

                <form method="POST" action="{{ route('admin.site-promotions.destroy', $promo) }}" onsubmit="return confirm('Hapus promo ini?')">
                    @csrf
                    @method('DELETE')
                    <div class="flex items-center justify-start">
                        <button type="submit" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-700">Hapus Promo/Event</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
