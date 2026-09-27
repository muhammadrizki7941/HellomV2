@props(['products'])

<section id="produk" class="section-shell mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8">
    <div class="mx-auto max-w-3xl text-center">
        <p class="text-sm font-bold uppercase tracking-[0.24em] text-accent">Produk SaaS</p>
        <h2 class="mt-4 font-display text-3xl font-extrabold tracking-[-0.05em] text-white sm:text-5xl">Satu ekosistem untuk landing, promo, dan operasional resto.</h2>
        <p class="mt-5 text-lg leading-8 text-muted">Struktur section mengikuti referensi dark SaaS, tapi tetap ditambatkan ke data dan workflow Hellom yang sudah ada.</p>
    </div>
    <div class="mt-12 grid gap-6 lg:grid-cols-3">
        @foreach($products as $product)
            <article class="surface-card rounded-[2rem] p-6">
                <span class="inline-flex rounded-full border border-accent-soft bg-accent-soft px-3 py-1 text-xs font-bold uppercase tracking-[0.18em] text-accent">{{ $product['badge'] }}</span>
                <h3 class="mt-5 font-display text-2xl font-bold text-white">{{ $product['title'] }}</h3>
                <p class="mt-4 text-sm leading-7 text-muted">{{ $product['description'] }}</p>
                <div class="mt-8 flex items-center justify-between gap-4">
                    <span class="font-display text-2xl font-extrabold text-white">{{ $product['price'] }}</span>
                    <a href="{{ route('register') }}" class="rounded-full bg-white px-4 py-2 text-sm font-bold text-black">{{ $product['cta'] }}</a>
                </div>
            </article>
        @endforeach
    </div>
</section>
