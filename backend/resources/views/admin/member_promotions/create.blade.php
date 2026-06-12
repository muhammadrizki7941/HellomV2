@php
    /** @var \Illuminate\Support\Collection<int,\App\Models\User> $users */
@endphp

@extends('layouts.admin-sidebar')

@section('header')
    <div class="flex items-center justify-between">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Tambah Promo Member</h2>
        <a href="{{ route('admin.member-promotions.index') }}" class="text-sm font-semibold text-gray-600">Kembali</a>
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
                <form class="grid gap-4" method="POST" action="{{ route('admin.member-promotions.store') }}">
                    @csrf

                    <div>
                        <label class="text-sm font-semibold">Member</label>
                        <select name="user_id" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" required>
                            <option value="">Pilih member</option>
                            @foreach($users as $u)
                                <option value="{{ $u->id }}" {{ (string)old('user_id') === (string)$u->id ? 'selected' : '' }}>
                                    {{ $u->name }} ({{ $u->email }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="text-sm font-semibold">Judul Promo</label>
                        <input name="title" value="{{ old('title') }}" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" required />
                    </div>

                    <div>
                        <label class="text-sm font-semibold">Deskripsi (opsional)</label>
                        <textarea name="description" rows="4" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3">{{ old('description') }}</textarea>
                    </div>

                    <div>
                        <label class="text-sm font-semibold">Expired (opsional)</label>
                        <input type="datetime-local" name="expires_at" value="{{ old('expires_at') }}" class="mt-1 w-full rounded-xl border border-gray-300 px-4 py-3" />
                    </div>

                    <div class="flex items-center justify-end gap-2">
                        <button type="submit" class="rounded-xl bg-gray-900 text-white px-5 py-3 font-semibold">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

