<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function show(Request $request)
    {
        return view('marketing.contact');
    }

    public function submit(Request $request)
    {
        // Placeholder only (no DB). We just validate and show success flash.
        $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:120'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        return back()->with('success', 'Terima kasih! Tim kami akan menghubungi Anda.');
    }
}
