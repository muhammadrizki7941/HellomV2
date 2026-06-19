<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\LandingAboutSetting;
use App\Models\LandingArticle;
use App\Models\LandingService;
use App\Services\Hellom\GeminiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LandingContentController extends BaseApiController
{
    public function publicContent(): JsonResponse
    {
        $about = LandingAboutSetting::query()->where('is_active', true)->latest('id')->first();
        $services = LandingService::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->limit(12)
            ->get();
        $articles = LandingArticle::query()
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('published_at')->orWhere('published_at', '<=', now());
            })
            ->orderByDesc('is_featured')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        return $this->ok([
            'about' => $about,
            'products' => $this->productSection($about),
            'services' => $services,
            'articles' => $articles,
        ], 'Landing content');
    }

    /**
     * Public listing for the Insights/blog page.
     */
    public function publicArticles(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 12), 1), 48);
        $search = trim((string) $request->query('q', ''));
        $category = trim((string) $request->query('category', ''));

        $query = LandingArticle::query()
            ->where('is_active', true)
            ->where(function ($builder) {
                $builder->whereNull('published_at')->orWhere('published_at', '<=', now());
            })
            ->when($search !== '', function ($builder) use ($search) {
                $builder->where(function ($inner) use ($search) {
                    $inner->where('title', 'like', "%{$search}%")
                        ->orWhere('excerpt', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%");
                });
            })
            ->when($category !== '', fn ($builder) => $builder->where('category', $category))
            ->orderByDesc('is_featured')
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        $paginated = $query->paginate($perPage)->withQueryString();

        $items = collect($paginated->items())->map(fn (LandingArticle $article) => $this->articleTeaser($article));

        $categories = LandingArticle::query()
            ->where('is_active', true)
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->values();

        return $this->ok([
            'items' => $items,
            'categories' => $categories,
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ], 'Insights');
    }

    /**
     * Public single article with full content + SEO metadata.
     */
    public function publicArticle(string $slug): JsonResponse
    {
        $article = LandingArticle::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->where(function ($builder) {
                $builder->whereNull('published_at')->orWhere('published_at', '<=', now());
            })
            ->first();

        if (!$article) {
            return $this->fail('Artikel tidak ditemukan', ['code' => 'NOT_FOUND'], 404);
        }

        $related = LandingArticle::query()
            ->where('is_active', true)
            ->where('id', '!=', $article->id)
            ->where(function ($builder) {
                $builder->whereNull('published_at')->orWhere('published_at', '<=', now());
            })
            ->when($article->category, fn ($builder) => $builder->where('category', $article->category))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(3)
            ->get()
            ->map(fn (LandingArticle $item) => $this->articleTeaser($item));

        // Fall back to latest articles when no same-category related found.
        if ($related->isEmpty()) {
            $related = LandingArticle::query()
                ->where('is_active', true)
                ->where('id', '!=', $article->id)
                ->where(function ($builder) {
                    $builder->whereNull('published_at')->orWhere('published_at', '<=', now());
                })
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->limit(3)
                ->get()
                ->map(fn (LandingArticle $item) => $this->articleTeaser($item));
        }

        return $this->ok([
            'article' => [
                'id' => $article->id,
                'title' => $article->title,
                'slug' => $article->slug,
                'meta_title' => $article->meta_title,
                'meta_description' => $article->meta_description,
                'meta_keywords' => $article->meta_keywords,
                'og_image' => $article->og_image ?: $article->thumbnail,
                'author' => $article->author,
                'thumbnail' => $article->thumbnail,
                'content' => $article->content,
                'excerpt' => $article->excerpt,
                'category' => $article->category,
                'published_at' => $article->published_at,
                'updated_at' => $article->updated_at,
                'read_time' => $article->read_time,
            ],
            'related' => $related,
        ], 'Insight detail');
    }

    /**
     * @return array<string,mixed>
     */
    private function articleTeaser(LandingArticle $article): array
    {
        return [
            'id' => $article->id,
            'title' => $article->title,
            'slug' => $article->slug,
            'thumbnail' => $article->thumbnail,
            'excerpt' => $article->excerpt,
            'category' => $article->category,
            'author' => $article->author,
            'published_at' => $article->published_at,
            'read_time' => $article->read_time,
            'is_featured' => $article->is_featured,
        ];
    }

    public function adminContent(): JsonResponse
    {
        $about = LandingAboutSetting::query()->latest('id')->first();

        return $this->ok([
            'about' => $about,
            'products' => $this->productSection($about),
            'services' => LandingService::query()->orderBy('sort_order')->orderByDesc('id')->get(),
            'articles' => LandingArticle::query()->orderByDesc('published_at')->orderByDesc('id')->get(),
        ], 'Landing content');
    }

    public function updateAbout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'years_experience' => ['nullable', 'integer', 'min:0'],
            'projects_completed' => ['nullable', 'integer', 'min:0'],
            'happy_clients' => ['nullable', 'integer', 'min:0'],
            'support_label' => ['nullable', 'string', 'max:50'],
            'products_label' => ['nullable', 'string', 'max:80'],
            'products_heading' => ['nullable', 'string', 'max:255'],
            'products_description' => ['nullable', 'string'],
            'products_cta_label' => ['nullable', 'string', 'max:80'],
            'is_active' => ['boolean'],
        ]);

        $about = LandingAboutSetting::query()->latest('id')->first();
        if (!$about) {
            $about = new LandingAboutSetting();
        }
        $about->fill($validated)->save();

        return $this->ok($about->fresh(), 'About updated');
    }

    public function storeService(Request $request): JsonResponse
    {
        $validated = $this->validateService($request);
        $validated['slug'] = $validated['slug'] ?: Str::slug($validated['title']);

        return $this->ok(LandingService::query()->create($validated), 'Service created', 201);
    }

    public function updateService(Request $request, int $id): JsonResponse
    {
        $service = LandingService::query()->find($id);
        if (!$service) {
            return $this->fail('Service not found', ['code' => 'NOT_FOUND'], 404);
        }

        $validated = $this->validateService($request, $id);
        if (array_key_exists('slug', $validated) && !$validated['slug']) {
            $validated['slug'] = Str::slug((string) ($validated['title'] ?? $service->title));
        }
        $service->update($validated);

        return $this->ok($service->fresh(), 'Service updated');
    }

    public function destroyService(int $id): JsonResponse
    {
        LandingService::query()->whereKey($id)->delete();
        return $this->ok(null, 'Service deleted');
    }

    public function storeArticle(Request $request): JsonResponse
    {
        $validated = $this->validateArticle($request);
        $validated['slug'] = $validated['slug'] ?: Str::slug($validated['title']);

        return $this->ok(LandingArticle::query()->create($validated), 'Article created', 201);
    }

    public function updateArticle(Request $request, int $id): JsonResponse
    {
        $article = LandingArticle::query()->find($id);
        if (!$article) {
            return $this->fail('Article not found', ['code' => 'NOT_FOUND'], 404);
        }

        $validated = $this->validateArticle($request, $id);
        if (array_key_exists('slug', $validated) && !$validated['slug']) {
            $validated['slug'] = Str::slug((string) ($validated['title'] ?? $article->title));
        }
        $article->update($validated);

        return $this->ok($article->fresh(), 'Article updated');
    }

    public function destroyArticle(int $id): JsonResponse
    {
        LandingArticle::query()->whereKey($id)->delete();
        return $this->ok(null, 'Article deleted');
    }

    /**
     * AI assistant for writing & optimising article content (Google Gemini).
     */
    public function aiAssist(Request $request, GeminiService $gemini): JsonResponse
    {
        if (!$gemini->isReady()) {
            return $this->fail(
                'Fitur AI belum aktif. Minta admin server mengisi GEMINI_API_KEY (gratis dari Google AI Studio).',
                ['code' => 'AI_NOT_CONFIGURED'],
                422
            );
        }

        $validated = $request->validate([
            'mode' => ['required', 'in:draft,improve,seo,excerpt,ideas'],
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'keywords' => ['nullable', 'string', 'max:255'],
            'tone' => ['nullable', 'string', 'max:60'],
            'category' => ['nullable', 'string', 'max:100'],
        ]);

        $mode = (string) $validated['mode'];
        $title = trim((string) ($validated['title'] ?? ''));
        $content = trim((string) ($validated['content'] ?? ''));
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($content)) ?? '');
        $keywords = trim((string) ($validated['keywords'] ?? ''));
        $tone = trim((string) ($validated['tone'] ?? '')) ?: 'profesional, ramah, dan mudah dipahami';
        $category = trim((string) ($validated['category'] ?? ''));

        $needsTitle = in_array($mode, ['draft', 'seo', 'ideas'], true);
        if ($needsTitle && $title === '' && $plain === '') {
            return $this->fail('Isi judul artikel dulu sebelum memakai AI.', ['code' => 'AI_MISSING_INPUT'], 422);
        }
        if (in_array($mode, ['improve', 'excerpt'], true) && $plain === '' && $title === '') {
            return $this->fail('Tulis konten atau judul dulu.', ['code' => 'AI_MISSING_INPUT'], 422);
        }

        try {
            if ($mode === 'seo') {
                $prompt = "Kamu pakar SEO. Buat metadata SEO untuk artikel berbahasa Indonesia.\n"
                    . "Judul: {$title}\n"
                    . ($category !== '' ? "Kategori: {$category}\n" : '')
                    . ($keywords !== '' ? "Kata kunci target: {$keywords}\n" : '')
                    . "Ringkasan isi: " . Str::limit($plain, 1500) . "\n\n"
                    . "Balas HANYA JSON valid tanpa penjelasan, dengan struktur persis:\n"
                    . '{"meta_title":"...","meta_description":"...","meta_keywords":"kata1, kata2, kata3","slug":"...","excerpt":"..."}' . "\n"
                    . "Aturan: meta_title maks 60 karakter dan mengandung kata kunci utama; meta_description 140-160 karakter, menarik untuk diklik; meta_keywords 5-8 kata dipisah koma; slug huruf kecil dengan tanda hubung; excerpt 1-2 kalimat ringkas.";
                $raw = $gemini->generate($prompt, 0.4, 700);

                return $this->ok(['fields' => $this->extractJson($raw)], 'SEO metadata dibuat');
            }

            if ($mode === 'ideas') {
                $prompt = "Berikan 7 ide judul artikel blog berbahasa Indonesia yang SEO-friendly dan menarik "
                    . "seputar topik: \"{$title}\"" . ($category !== '' ? " (kategori {$category})" : '') . ".\n"
                    . "Balas sebagai daftar bernomor 1-7 saja, tanpa pembuka/penutup.";
                $raw = $gemini->generate($prompt, 0.9, 700);

                return $this->ok(['result' => $raw], 'Ide judul dibuat');
            }

            if ($mode === 'excerpt') {
                $source = $plain !== '' ? Str::limit($plain, 2000) : $title;
                $prompt = "Buat ringkasan (excerpt) 1-2 kalimat (maks 160 karakter), berbahasa Indonesia, "
                    . "menarik dan informatif untuk artikel berikut. Balas hanya teks ringkasannya saja:\n\n{$source}";
                $raw = $gemini->generate($prompt, 0.5, 200);

                return $this->ok(['result' => trim(strip_tags($raw))], 'Ringkasan dibuat');
            }

            if ($mode === 'improve') {
                $prompt = "Perbaiki dan rapikan artikel berikut agar lebih enak dibaca, terstruktur, dan SEO-friendly, "
                    . "tetap berbahasa Indonesia dengan gaya {$tone}. Pertahankan maksud asli.\n"
                    . "Kembalikan HANYA konten HTML bersih untuk badan artikel memakai tag <h2>, <h3>, <p>, <ul>, <ol>, <li>, <blockquote>, <strong>, <a>. "
                    . "Jangan sertakan <html>, <head>, <body>, atau blok kode markdown.\n\n"
                    . "Judul: {$title}\n\nKonten:\n{$content}";
                $raw = $gemini->generate($prompt, 0.6, 4000);

                return $this->ok(['result' => $this->cleanHtml($raw)], 'Konten dirapikan');
            }

            // draft
            $prompt = "Tulis artikel blog berbahasa Indonesia yang original, SEO-friendly, dan enak dibaca seperti artikel berita/media profesional.\n"
                . "Judul: {$title}\n"
                . ($category !== '' ? "Kategori: {$category}\n" : '')
                . ($keywords !== '' ? "Kata kunci yang harus muncul natural: {$keywords}\n" : '')
                . "Gaya: {$tone}. Panjang 500-800 kata, gunakan beberapa sub-judul.\n"
                . "Kembalikan HANYA konten HTML badan artikel memakai tag <h2>, <h3>, <p>, <ul>, <ol>, <li>, <blockquote>, <strong>, <a>. "
                . "Jangan sertakan judul utama (h1), <html>, <head>, <body>, atau blok kode markdown.";
            $raw = $gemini->generate($prompt, 0.8, 4000);

            return $this->ok(['result' => $this->cleanHtml($raw)], 'Draft artikel dibuat');
        } catch (\Throwable $exception) {
            return $this->fail($exception->getMessage(), ['code' => 'AI_FAILED'], 422);
        }
    }

    private function cleanHtml(string $raw): string
    {
        $text = trim($raw);
        // Strip ```html ... ``` or ``` ... ``` fences if the model added them.
        $text = preg_replace('/^```[a-zA-Z]*\s*/', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * @return array<string,string>
     */
    private function extractJson(string $raw): array
    {
        $text = $this->cleanHtml($raw);
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $text = substr($text, $start, $end - $start + 1);
        }

        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach (['meta_title', 'meta_description', 'meta_keywords', 'slug', 'excerpt'] as $field) {
            if (isset($decoded[$field]) && is_string($decoded[$field])) {
                $out[$field] = trim($decoded[$field]);
            }
        }

        return $out;
    }

    private function validateService(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:landing_services,slug,' . ($id ?? 'NULL') . ',id'],
            'icon' => ['nullable', 'string', 'max:100'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'long_description' => ['nullable', 'string'],
            'featured_image' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);
    }

    /**
     * @return array{label:string,heading:string,description:string,cta_label:string}
     */
    private function productSection(?LandingAboutSetting $about): array
    {
        return [
            'label' => (string) ($about?->products_label ?: 'Products'),
            'heading' => (string) ($about?->products_heading ?: 'Produk digital premium untuk hasil maksimal.'),
            'description' => (string) ($about?->products_description ?: 'Pilih produk digital yang bisa langsung dipakai atau dibeli. POS, Landing Page Builder, template, ekstensi, dan produk lain akan tampil otomatis dari katalog backend.'),
            'cta_label' => (string) ($about?->products_cta_label ?: 'Lihat semua produk'),
        ];
    }

    private function validateArticle(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:landing_articles,slug,' . ($id ?? 'NULL') . ',id'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'meta_keywords' => ['nullable', 'string', 'max:255'],
            'og_image' => ['nullable', 'string', 'max:500'],
            'author' => ['nullable', 'string', 'max:120'],
            'thumbnail' => ['nullable', 'string', 'max:500'],
            'content' => ['nullable', 'string'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'category' => ['nullable', 'string', 'max:100'],
            'published_at' => ['nullable', 'date'],
            'read_time' => ['nullable', 'integer', 'min:1'],
            'is_featured' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
    }
}
