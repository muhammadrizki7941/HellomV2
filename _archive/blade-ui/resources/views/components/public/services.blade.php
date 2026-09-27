@props(['services'])

<section id="layanan" class="section-shell border-y border-white/10 bg-black/20">
    <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8">
        <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
            <div class="max-w-3xl">
                <p class="text-sm font-bold uppercase tracking-[0.24em] text-accent">Layanan</p>
                <h2 class="mt-4 font-display text-3xl font-extrabold tracking-[-0.05em] text-white sm:text-5xl">Migrasi public page tanpa memutus dashboard yang sudah dipakai.</h2>
            </div>
            <p class="max-w-xl text-base leading-8 text-muted">Semua admin, owner, cashier, dan customer flow tetap dibiarkan hidup. Yang diganti hanya lapisan publik supaya lebih ramah pencarian dan lebih ringan dimuat.</p>
        </div>
        <div class="mt-12 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            @foreach($services as $index => $service)
                <article class="surface-card rounded-[1.75rem] p-6">
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-accent-soft font-display text-lg font-extrabold text-accent">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</div>
                    <h3 class="mt-5 text-xl font-bold text-white">{{ $service['title'] }}</h3>
                    <p class="mt-3 text-sm leading-7 text-muted">{{ $service['copy'] }}</p>
                </article>
            @endforeach
        </div>
    </div>
</section>
