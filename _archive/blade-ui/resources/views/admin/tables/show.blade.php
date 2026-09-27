@php
    /** @var \App\Models\DiningTable $table */
@endphp


@extends('layouts.admin-sidebar')

@section('header')
    <div class="flex items-center justify-between">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Table: {{ $table->name ?: $table->code }}</h2>
        <a href="{{ route('admin.tables.edit', $table) }}" class="inline-flex items-center rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold">Edit</a>
    </div>
@endsection

@section('content')
    <div class="py-6">

    <div class="py-6">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 grid gap-4">
                    <div class="rounded-2xl border border-gray-200 p-4">
                        <div class="text-xs text-gray-500">Order URL</div>
                        <div class="mt-1 font-mono text-sm break-all">{{ $orderUrl }}</div>
                        <div class="mt-3 flex items-center gap-3">
                            <a class="rounded-xl bg-gray-900 text-white px-4 py-2 text-sm font-semibold" href="{{ $orderUrl }}">Open</a>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 p-4">
                        <div class="font-semibold">QR</div>
                        <div class="mt-3 flex flex-col sm:flex-row items-start gap-6">
                            <div class="rounded-2xl border border-gray-200 bg-white p-4">
                                {!! \SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')->size(220)->margin(1)->generate($orderUrl) !!}
                            </div>
                            <div class="text-sm text-gray-600">
                                <div class="font-semibold text-gray-900">Print-ready</div>
                                <div class="mt-1">Scan QR ini untuk buka menu di meja.</div>
                                <div class="mt-2 text-xs text-gray-500">Jika dipakai Local Mode, pastikan <span class="font-mono">APP_URL</span> memakai IP LAN server.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
