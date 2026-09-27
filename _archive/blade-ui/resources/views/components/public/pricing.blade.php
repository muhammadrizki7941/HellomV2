@props(['plans', 'faqs'])

<section id="harga" class="section-shell mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8" x-data="{ yearly: false }">
    <div class="mx-auto max-w-3xl text-center">
        <p class="text-sm font-bold uppercase tracking-[0.24em] text-accent">Pricing</p>
        <h2 class="mt-4 font-display text-3xl font-extrabold tracking-[-0.05em] text-white sm:text-5xl">Harga yang bisa dijelaskan, bukan sekadar dipasang.</h2>
        <p class="mt-5 text-lg leading-8 text-muted">Toggle bulanan dan tahunan ditangani Alpine. Konten harga tetap ada di HTML sejak awal supaya aman untuk crawler dan pengguna non-JS.</p>
        <div class="mt-8 inline-flex items-center gap-4 rounded-full border border-white/10 bg-white/5 px-4 py-3">
            <span :class="yearly ? 'text-muted' : 'text-white'" class="text-sm font-semibold">Bulanan</span>
            <button type="button" @click="yearly = !yearly" class="relative h-7 w-14 rounded-full bg-white/10">
                <span class="absolute top-1 h-5 w-5 rounded-full bg-[var(--brand-accent)] transition" :class="yearly ? 'left-8' : 'left-1'"></span>
            </button>
            <span :class="yearly ? 'text-white' : 'text-muted'" class="text-sm font-semibold">Tahunan</span>
            <span class="rounded-full bg-accent-soft px-3 py-1 text-xs font-bold text-accent">Hemat</span>

            <div class="hidden">
                @foreach($plans as $plan)
                    <span>{{ $plan['monthly'] }} {{ $plan['yearly'] }}</span>
                @endforeach
            </div>
        </div>
    </div>

    <div class="mt-12 grid gap-6 lg:grid-cols-3">
        @foreach($plans as $plan)
            <article class="surface-card rounded-[2rem] p-7 {{ $plan['featured'] ? 'accent-ring' : '' }}">
                @if($plan['featured'])
                    <span class="inline-flex rounded-full bg-[var(--brand-accent)] px-3 py-1 text-xs font-bold uppercase tracking-[0.18em] text-black">Paling cocok</span>
                @endif
                <h3 class="mt-4 font-display text-2xl font-extrabold text-white">{{ $plan['name'] }}</h3>
                <p class="mt-2 text-sm leading-7 text-muted">{{ $plan['note'] }}</p>
                <p class="mt-6 font-display text-4xl font-extrabold tracking-[-0.06em] text-white">
                    <span x-show="!yearly">{{ $plan['monthly'] }}</span>
                    <span x-show="yearly">{{ $plan['yearly'] }}</span>
                </p>
                <ul class="mt-6 space-y-3 text-sm text-muted">
                    @foreach($plan['features'] as $feature)
                        <li class="flex gap-3">
                            <span class="text-accent">•</span>
                            <span>{{ $feature }}</span>
                        </li>
                    @endforeach
                </ul>
                <a href="{{ route('register') }}" class="mt-8 inline-flex rounded-full {{ $plan['featured'] ? 'bg-[var(--brand-accent)] text-black' : 'border border-white/10 text-white' }} px-5 py-3 text-sm font-bold">
                    Mulai sekarang
                </a>
            </article>
        @endforeach
    </div>

    <div id="faq" class="mx-auto mt-20 max-w-4xl" x-data="{ open: 0 }">
        <div class="text-center">
            <p class="text-sm font-bold uppercase tracking-[0.24em] text-accent">FAQ</p>
            <h2 class="mt-4 font-display text-3xl font-extrabold tracking-[-0.05em] text-white sm:text-4xl">Pertanyaan yang paling sering muncul.</h2>
        </div>
        <div class="mt-8 space-y-4">
            @foreach($faqs as $index => $faq)
                <article class="surface-card rounded-[1.5rem] p-5">
                    <button type="button" class="flex w-full items-center justify-between gap-4 text-left" @click="open = open === {{ $index }} ? null : {{ $index }}">
                        <h3 class="text-lg font-bold text-white">{{ $faq['question'] }}</h3>
                        <span class="text-accent" x-text="open === {{ $index }} ? '−' : '+'"></span>
                    </button>
                    <p x-show="open === {{ $index }}" x-transition class="mt-4 text-sm leading-7 text-muted">{{ $faq['answer'] }}</p>
                </article>
            @endforeach
        </div>
    </div>
</section>
