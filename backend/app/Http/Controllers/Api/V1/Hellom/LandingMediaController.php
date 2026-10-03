<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Http\Controllers\Api\V1\Hellom\Concerns\ResolvesSellerOrganization;
use App\Support\Landing\Embed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Editor helper (Fase 7.3): what a pasted video link will show — provider, vertical or not, start
 * time, and for YouTube the title + thumbnail from YouTube's oEmbed. Only the video id goes to
 * YouTube (the URL is rebuilt), so the endpoint cannot be used to fetch arbitrary addresses.
 */
class LandingMediaController extends BaseApiController
{
    use ResolvesSellerOrganization;

    public function videoPreview(Request $request): JsonResponse
    {
        [, $error] = $this->sellerOrganization($request);
        if ($error) {
            return $error;
        }
        $url = trim((string) $request->query('url', ''));
        $embed = mb_strlen($url) <= 500 ? Embed::resolve($url) : null;
        if (!$embed || !in_array($embed['provider'], ['youtube', 'tiktok', 'instagram'], true)) {
            return $this->fail('Link video belum dikenali. Tempel link YouTube (termasuk Shorts), TikTok, atau Instagram Reels.', ['code' => 'VIDEO_URL_UNKNOWN'], 422);
        }

        $data = [
            'provider' => $embed['provider'],
            'label' => $embed['label'],
            'id' => $embed['id'],
            'vertical' => $embed['vertical'],
            'start' => (int) ($embed['start'] ?? 0),
            'title' => null,
            'author' => null,
            'thumbnail' => $embed['provider'] === 'youtube' ? "https://i.ytimg.com/vi/{$embed['id']}/hqdefault.jpg" : null,
        ];
        if ($embed['provider'] === 'youtube') {
            $meta = Cache::remember('landing:yt-oembed:' . $embed['id'], now()->addDay(), function () use ($embed) {
                try {
                    $response = Http::timeout(4)->acceptJson()->get((string) config('services.youtube.oembed_url', 'https://www.youtube.com/oembed'), [
                        'url' => 'https://www.youtube.com/watch?v=' . $embed['id'], 'format' => 'json',
                    ]);
                } catch (Throwable) {
                    return null;
                }
                if ($response->status() === 404 || $response->status() === 400) {
                    return ['missing' => true];
                }

                return $response->ok() ? ['title' => mb_substr((string) $response->json('title'), 0, 160), 'author' => mb_substr((string) $response->json('author_name'), 0, 80)] : null;
            });
            if (!empty($meta['missing'])) {
                return $this->fail('Video YouTube ini tidak ditemukan atau privat. Cek lagi linknya.', ['code' => 'VIDEO_NOT_FOUND'], 422);
            }
            $data['title'] = $meta['title'] ?? null;
            $data['author'] = $meta['author'] ?? null;
        }

        return $this->ok($data, 'Video dikenali');
    }
}
