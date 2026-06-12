@extends('layouts.admin-sidebar')

@section('header')
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">New Table</h2>
@endsection

@section('content')
    <div class="py-6">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <form method="POST" action="{{ route('admin.tables.store') }}" class="grid gap-4">
                        @csrf

                        <div>
                            <label class="text-sm font-medium">Code (unique)</label>
                            <input name="code" class="mt-1 w-full rounded-xl border-gray-300" placeholder="T01" required />
                            @error('code')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                        </div>

                        <div>
                            <label class="text-sm font-medium">Name (optional)</label>
                            <input name="name" class="mt-1 w-full rounded-xl border-gray-300" placeholder="Meja Dekat Kasir" />
                            @error('name')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
                        </div>

                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" name="is_active" value="1" checked class="rounded border-gray-300" />
                            <span class="text-sm">Active</span>
                        </label>

                        <div class="flex items-center gap-3">
                            <button class="rounded-xl bg-gray-900 text-white px-4 py-2 text-sm font-semibold">Save</button>
                            <a href="{{ route('admin.tables.index') }}" class="text-sm text-gray-600">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
