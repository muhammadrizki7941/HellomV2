@props(['stats'])

<section class="section-shell border-y border-white/10 bg-black/20">
    <div class="mx-auto grid max-w-7xl gap-px px-4 py-6 sm:px-6 lg:grid-cols-4 lg:px-8">
        @foreach($stats as $stat)
            <div class="surface-card rounded-[1.5rem] px-6 py-7">
                <p class="font-display text-4xl font-extrabold tracking-[-0.06em] text-accent">{{ $stat['value'] }}</p>
                <p class="mt-2 text-sm text-muted">{{ $stat['label'] }}</p>
            </div>
        @endforeach
    </div>
</section>
