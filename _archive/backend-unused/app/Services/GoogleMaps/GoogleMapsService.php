<?php

namespace App\Services\GoogleMaps;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GoogleMapsService
{
    public function getPlaceRating(string $input): ?array
    {
        if (!$input) {
            return null;
        }

        // Extract Place ID from input (could be URL or direct Place ID)
        $placeId = $this->extractPlaceId($input);
        if (!$placeId) {
            return null;
        }

        $apiKey = config('services.google_maps.api_key');
        if (!$apiKey) {
            return null;
        }

        $cacheKey = "google_maps_rating_{$placeId}";
        $cacheDuration = 3600; // 1 hour

        return Cache::remember($cacheKey, $cacheDuration, function () use ($placeId, $apiKey) {
            try {
                $response = Http::get('https://maps.googleapis.com/maps/api/place/details/json', [
                    'place_id' => $placeId,
                    'fields' => 'rating,user_ratings_total',
                    'key' => $apiKey,
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    if (isset($data['result'])) {
                        return [
                            'rating' => $data['result']['rating'] ?? null,
                            'user_ratings_total' => $data['result']['user_ratings_total'] ?? null,
                        ];
                    }
                }
            } catch (\Exception $e) {
                // Log error if needed
            }

            return null;
        });
    }

    private function extractPlaceId(string $input): ?string
    {
        // If it's already a Place ID (starts with ChIJ or 0x)
        if (str_starts_with($input, 'ChIJ') || str_starts_with($input, '0x')) {
            return $input;
        }

        // Try to extract from URL
        if (str_contains($input, 'place_id:')) {
            preg_match('/place_id:([^&\?]+)/', $input, $matches);
            if (isset($matches[1])) {
                return $matches[1];
            }
        }

        // Try to resolve short Google Maps URLs
        if (str_contains($input, 'goo.gl') || str_contains($input, 'maps.app.goo.gl')) {
            try {
                $response = \Illuminate\Support\Facades\Http::withOptions(['allow_redirects' => false])->get($input);
                $location = $response->header('Location');
                if ($location) {
                    return $this->extractPlaceId($location);
                }
            } catch (\Exception $e) {
                // Ignore errors
            }
        }

        // Try to find Place ID in URL parameters (for the format !1sPLACE_ID)
        if (str_contains($input, '!1s')) {
            preg_match('/!1s([^!]+)/', $input, $matches);
            if (isset($matches[1])) {
                return $matches[1];
            }
        }

        // Try to find Place ID in URL parameters (for the format 1sPLACE_ID)
        if (str_contains($input, '1s')) {
            preg_match('/1s([^!&\?]+)/', $input, $matches);
            if (isset($matches[1])) {
                return $matches[1];
            }
        }

        // Try to find Place ID in q parameter
        if (str_contains($input, 'q=')) {
            preg_match('/q=([^&\?]+)/', $input, $matches);
            if (isset($matches[1]) && (str_starts_with($matches[1], 'ChIJ') || str_starts_with($matches[1], '0x'))) {
                return $matches[1];
            }
        }

        return null;
    }
}