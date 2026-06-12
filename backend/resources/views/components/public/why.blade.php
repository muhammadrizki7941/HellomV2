@props(['reasons'])

<section class="section-shell mx-auto grid max-w-7xl gap-10 px-4 py-20 sm:px-6 lg:grid-cols-[0.9fr_1.1fr] lg:px-8">
    <div>
        <p class="text-sm font-bold uppercase tracking-[0.24em] text-accent">Kenapa Hellom</p>
        <h2 class="mt-4 font-display text-3xl font-extrabold tracking-[-0.05em] text-white sm:text-5xl">Bukan sekadar tampilan baru, tapi fondasi publik yang lebih sehat.</h2>
        <p class="mt-5 text-lg leading-8 text-muted">Perpindahan ini memecah public marketing dari SPA app. Hasilnya: halaman depan lebih cepat, lebih mudah dirayapi, dan lebih gampang diatur lewat backend.</p>
    </div>
    <div class="space-y-5">
        @foreach($reasons as $reason)
            <article class="surface-card rounded-[1.75rem] p-6">
                <div class="flex gap-5">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-accent-soft font-display text-sm font-extrabold text-accent">{{ $reason['number'] }}</div>
                    <div>
                        <h3 class="text-xl font-bold text-white">{{ $reason['title'] }}</h3>
                        <p class="mt-3 text-sm leading-7 text-muted">{{ $reason['copy'] }}</p>
                    </div>
                </div>
            </article>
        @endforeach
    </div>
</section>
