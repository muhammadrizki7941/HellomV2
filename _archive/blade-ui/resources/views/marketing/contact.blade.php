<x-layouts.marketing>
    <div class="card">
        <h1 style="margin:0 0 8px; font-size:24px;">Contact</h1>
        <p class="muted" style="margin:0 0 14px;">Form ini placeholder (tanpa DB).</p>

        @if (session('success'))
            <div class="success" style="margin-bottom:10px;">{{ session('success') }}</div>
        @endif

        @if($errors->any())
            <div class="error" style="margin-bottom:10px;">Periksa input.</div>
        @endif

        <form method="POST" action="{{ route('marketing.contact.submit') }}">
            @csrf

            <label>Nama</label>
            <input name="name" value="{{ old('name') }}" />
            @error('name')<div class="error">{{ $message }}</div>@enderror

            <label>Email</label>
            <input name="email" value="{{ old('email') }}" />
            @error('email')<div class="error">{{ $message }}</div>@enderror

            <label>Pesan</label>
            <textarea name="message" rows="5">{{ old('message') }}</textarea>
            @error('message')<div class="error">{{ $message }}</div>@enderror

            <div style="margin-top:12px;">
                <button class="btn" type="submit">Kirim</button>
            </div>
        </form>
    </div>
</x-layouts.marketing>
