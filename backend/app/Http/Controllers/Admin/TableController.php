<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DiningTable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TableController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $tables = DiningTable::query()->orderBy('code')->get();

        return view('admin.tables.index', [
            'tables' => $tables,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('admin.tables.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:16', 'unique:dining_tables,code'],
            'name' => ['nullable', 'string', 'max:80'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $table = DiningTable::query()->create([
            'public_id' => Str::lower(Str::random(12)),
            'code' => $validated['code'],
            'name' => $validated['name'] ?: null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        return redirect()->route('admin.tables.index')->with('success', 'Table berhasil dibuat.');
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $table = DiningTable::query()->findOrFail($id);

        $orderUrl = url('/order?table='.$table->public_id);

        return view('admin.tables.show', [
            'table' => $table,
            'orderUrl' => $orderUrl,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $table = DiningTable::query()->findOrFail($id);

        return view('admin.tables.edit', [
            'table' => $table,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $table = DiningTable::query()->findOrFail($id);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:16', 'unique:dining_tables,code,'.$table->id],
            'name' => ['nullable', 'string', 'max:80'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $table->update([
            'code' => $validated['code'],
            'name' => $validated['name'] ?: null,
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);

        return redirect()->route('admin.tables.show', $table);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $table = DiningTable::query()->findOrFail($id);
        $table->delete();

        return redirect()->route('admin.tables.index');
    }
}
