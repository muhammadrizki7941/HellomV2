<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MemberPromotion;
use App\Models\User;
use Illuminate\Http\Request;

class MemberPromotionController extends Controller
{
    public function index()
    {
        $promos = MemberPromotion::query()
            ->with('user')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return view('admin.member_promotions.index', [
            'promos' => $promos,
        ]);
    }

    public function create()
    {
        $users = User::query()
            ->where('role', '!=', 'admin')
            ->orderBy('name')
            ->limit(500)
            ->get();

        return view('admin.member_promotions.create', [
            'users' => $users,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:4000'],
            'expires_at' => ['nullable', 'date'],
        ]);

        MemberPromotion::query()->create([
            'user_id' => (int) $validated['user_id'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?: null,
            'expires_at' => isset($validated['expires_at']) ? $validated['expires_at'] : null,
            'is_redeemed' => false,
        ]);

        return redirect()->route('admin.member-promotions.index')->with('success', 'Promo member dibuat.');
    }

    public function redeem($id)
    {
        $promotion = MemberPromotion::findOrFail($id);

        if (!$promotion->is_redeemed) {
            $promotion->is_redeemed = true;
            $promotion->redeemed_at = now();
            $promotion->save();
        }

        return back()->with('success', 'Promo ditandai sudah dipakai.');
    }

    public function destroy($id)
    {
        $promotion = MemberPromotion::findOrFail($id);

        $promotion->delete();

        return back()->with('success', 'Promo dihapus.');
    }
}
