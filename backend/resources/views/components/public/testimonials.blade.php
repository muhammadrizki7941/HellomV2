@props(['testimonials'])

<section class="section-shell border-y border-white/10 bg-black/20">
    <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-3xl text-center">
            <p class="text-sm font-bold uppercase tracking-[0.24em] text-accent">Testimoni</p>
            <h2 class="mt-4 font-display text-3xl font-extrabold tracking-[-0.05em] text-white sm:text-5xl">Tim Anda tetap nyaman, customer tetap paham, dan halaman tetap terbaca mesin pencari.</h2>
        </div>
        <div class="mt-12" x-data="{ current: 0, total: {{ count($testimonials) }} }">
            <div class="grid gap-6 lg:grid-cols-3">
                @foreach($testimonials as $index => $testimonial)
                    <article x-show="current === {{ $index }}" x-transition class="surface-card rounded-[2rem] p-7 lg:col-span-3">
                        <p class="text-sm font-bold uppercase tracking-[0.22em] text-accent">5/5 rating</p>
                        <blockquote class="mt-5 max-w-4xl font-display text-2xl font-bold leading-relaxed text-white">“{{ $testimonial['quote'] }}”</blockquote>
                        <div class="mt-6">
                            <p class="text-lg font-semibold text-white">{{ $testimonial['name'] }}</p>
                            <p class="text-sm text-muted">{{ $testimonial['role'] }}</p>
                        </div>
                    </article>
                @endforeach
            </div>
            <div class="mt-6 flex items-center justify-center gap-2">
                @foreach($testimonials as $index => $testimonial)
                    <button type="button" @click="current = {{ $index }}" class="h-2 w-10 rounded-full transition" :class="current === {{ $index }} ? 'bg-[var(--brand-accent)]' : 'bg-white/10'" aria-label="Testimoni {{ $index + 1 }}"></button>
                @endforeach
            </div>
        </div>
    </div>
</section>
