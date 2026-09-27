@props(['brand'])

<section id="cta" class="section-shell border-t border-white/10 bg-black/20">
    <div class="mx-auto max-w-7xl px-4 py-20 text-center sm:px-6 lg:px-8">
        <div class="surface-card mx-auto max-w-5xl rounded-[2.5rem] px-6 py-14 sm:px-10">
            <p class="text-sm font-bold uppercase tracking-[0.24em] text-accent">Call to action</p>
            <h2 class="mt-4 font-display text-4xl font-extrabold tracking-[-0.05em] text-white sm:text-6xl">Mulai pakai Hellom tanpa membelah antara marketing, POS, dan growth.</h2>
            <p class="mx-auto mt-6 max-w-3xl text-lg leading-8 text-muted">Daftarkan akun, atur brand, tampilkan banner promo, dan jalankan alur publik yang lebih ramah SEO tanpa membongkar area aplikasi yang sudah digunakan tim.</p>
            <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                <a href="{{ route('register') }}" class="rounded-full bg-[var(--brand-accent)] px-6 py-4 text-base font-bold text-black transition hover:brightness-110">Daftar & aktifkan sekarang</a>
                <a href="{{ route('login') }}" class="rounded-full border border-white/10 px-6 py-4 text-base font-semibold text-white transition hover:border-white/30">Masuk ke akun</a>
            </div>
        </div>
    </div>
</section>
