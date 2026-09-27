@php
    /** @var \App\Models\DiningTable $table */
@endphp

@extends('layouts.admin-sidebar')

@section('header')
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">Edit Table</h2>
@endsection

@section('content')
    <div class="py-6">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <form method="POST" action="{{ route('admin.tables.update', $table) }}" class="grid gap-4">
                        @csrf
                        @method('PUT')

                        <div>
                            <label class="text-sm font-medium">Code</label>
                            <input name="code" value="{{ old('code', $table->code) }}" class="mt-1 w-full rounded-xl border-gray-300" required />
                            @error('code')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                        </div>

                        <div>
                            <label class="text-sm font-medium">Name</label>
                            <input name="name" value="{{ old('name', $table->name) }}" class="mt-1 w-full rounded-xl border-gray-300" />
                            @error('name')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                        </div>

                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" name="is_active" value="1" {{ $table->is_active ? 'checked' : '' }} class="rounded border-gray-300" />
                            <span class="text-sm">Active</span>
                        </label>

                        <div class="flex items-center gap-3">
                            <button class="rounded-xl bg-gray-900 text-white px-4 py-2 text-sm font-semibold">Save</button>
                            <a href="{{ route('admin.tables.show', $table) }}" class="text-sm text-gray-600">Back</a>
                        </div>
                    </form>

                    <form method="POST" action="{{ route('admin.tables.destroy', $table) }}" class="mt-6" onsubmit="return confirm('Delete table?')">
                        @csrf
                        @method('DELETE')
                        <button class="text-sm font-semibold text-red-600">Delete</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
