@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\DiningTable> $tables */
@endphp

@extends('layouts.admin-sidebar')

@section('header')
    <div class="flex items-center justify-between">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Tables</h2>
        <a href="{{ route('admin.tables.create') }}" class="inline-flex items-center rounded-xl bg-gray-900 text-white px-4 py-2 text-sm font-semibold">New Table</a>
    </div>
@endsection

@section('content')
    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="grid gap-3">
                        @foreach($tables as $t)
                            <a href="{{ route('admin.tables.show', $t) }}" class="rounded-2xl border border-gray-200 p-4 flex items-center justify-between">
                                <div>
                                    <div class="font-semibold">{{ $t->name ?: $t->code }}</div>
                                    <div class="text-xs text-gray-500">Code: {{ $t->code }} · Token: {{ $t->public_id }}</div>
                                </div>
                                <span class="text-xs font-semibold px-3 py-1 rounded-full {{ $t->is_active ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-gray-100 text-gray-700 border border-gray-200' }}">{{ $t->is_active ? 'Active' : 'Disabled' }}</span>
                            </a>
                        @endforeach

                        @if($tables->count() === 0)
                            <div class="text-sm text-gray-500">Belum ada meja.</div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
