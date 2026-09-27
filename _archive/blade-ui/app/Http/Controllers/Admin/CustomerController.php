<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = User::where('role', 'member')
            ->withCount(['orders', 'reservations'])
            ->with(['orders' => function($q) {
                $q->latest()->take(1);
            }]);

        // Search functionality
        if ($request->filled('q')) {
            $search = $request->q;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Time filter
        if ($request->filled('time_range')) {
            $timeRange = $request->time_range;
            if ($timeRange === 'today') {
                $query->whereDate('created_at', today());
            } elseif ($timeRange === 'week') {
                $query->where('created_at', '>=', now()->startOfWeek());
            } elseif ($timeRange === 'month') {
                $query->where('created_at', '>=', now()->startOfMonth());
            } elseif (is_numeric($timeRange)) {
                $query->where('created_at', '>=', now()->subDays($timeRange));
            }
        }

        // Status filter (active/inactive based on recent activity)
        if ($request->filled('status')) {
            if ($request->status === 'active') {
                // Active: has orders in last 30 days or has points
                $query->where(function($q) {
                    $q->whereHas('orders', function($orderQuery) {
                        $orderQuery->where('created_at', '>=', now()->subDays(30));
                    })->orWhere('points_balance', '>', 0);
                });
            } elseif ($request->status === 'inactive') {
                // Inactive: no recent orders and no points
                $query->whereDoesntHave('orders', function($orderQuery) {
                    $orderQuery->where('created_at', '>=', now()->subDays(30));
                })->where('points_balance', 0);
            }
        }

        // Sort by priority: recent activity first
        $query->orderByRaw('
            CASE
                WHEN points_balance > 0 THEN 1
                WHEN EXISTS (SELECT 1 FROM orders WHERE orders.user_id = users.id AND orders.created_at >= ?) THEN 2
                ELSE 3
            END ASC,
            updated_at DESC
        ', [now()->subDays(30)]);

        $customers = $query->paginate(20)->withQueryString();

        return view('admin.customers.index', compact('customers'));
    }

    public function create()
    {
        return view('admin.customers.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:40|unique:users,phone',
            'password' => 'required|string|min:8',
            'points_balance' => 'nullable|integer|min:0',
        ]);

        $customer = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'password' => Hash::make($validated['password']),
            'role' => 'member',
            'points_balance' => $validated['points_balance'] ?? 0,
        ]);

        return redirect()->route('admin.customers.index')
            ->with('success', 'Customer berhasil ditambahkan.');
    }

    public function show(User $customer)
    {
        $this->authorizeCustomer($customer);

        $customer->load([
            'orders' => function($q) {
                $q->latest()->take(10);
            },
            'reservations' => function($q) {
                $q->latest()->take(10);
            },
            'promotions' => function($q) {
                $q->latest()->take(5);
            }
        ]);

        return view('admin.customers.show', compact('customer'));
    }

    public function edit(User $customer)
    {
        $this->authorizeCustomer($customer);

        return view('admin.customers.edit', compact('customer'));
    }

    public function update(Request $request, User $customer)
    {
        $this->authorizeCustomer($customer);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', Rule::unique('users')->ignore($customer->id)],
            'phone' => ['nullable', 'string', 'max:40', Rule::unique('users')->ignore($customer->id)],
            'password' => 'nullable|string|min:8',
            'points_balance' => 'nullable|integer|min:0',
        ]);

        $updateData = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'points_balance' => $validated['points_balance'] ?? $customer->points_balance,
        ];

        if (!empty($validated['password'])) {
            $updateData['password'] = Hash::make($validated['password']);
        }

        $customer->update($updateData);

        return redirect()->route('admin.customers.index')
            ->with('success', 'Customer berhasil diperbarui.');
    }

    public function destroy(User $customer)
    {
        $this->authorizeCustomer($customer);

        // Check if customer has orders or reservations
        if ($customer->orders()->count() > 0 || $customer->reservations()->count() > 0) {
            return redirect()->route('admin.customers.index')
                ->with('error', 'Customer tidak dapat dihapus karena memiliki riwayat order atau reservasi.');
        }

        $customer->delete();

        return redirect()->route('admin.customers.index')
            ->with('success', 'Customer berhasil dihapus.');
    }

    public function toggleStatus(User $customer)
    {
        $this->authorizeCustomer($customer);

        // For now, we'll use a simple active/inactive flag
        // You might want to add an 'is_active' column to users table
        $customer->update([
            'updated_at' => now(), // This will affect the sorting priority
        ]);

        $message = 'Status customer berhasil diperbarui.';

        return redirect()->back()->with('success', $message);
    }

    private function authorizeCustomer(User $customer)
    {
        if ($customer->role !== 'member') {
            abort(403, 'Unauthorized access to customer data.');
        }
    }
}