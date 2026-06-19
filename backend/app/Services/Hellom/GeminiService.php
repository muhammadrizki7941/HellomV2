<?php

namespace App\Services\Hellom;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class GeminiService
{
    public function isReady(): bool
    {
        return (string) config('services.gemini.key', '') !== '';
    }

    /**
     * Send a prompt to Google Gemini and return the generated plain text.
     */
    public function generate(string $prompt, float $temperature = 0.7, int $maxTokens = 2048): string
    {
        $key = (string) config('services.gemini.key', '');
        if ($key === '') {
            throw new \RuntimeException('GEMINI_API_KEY belum dikonfigurasi di server.');
        }

        $model = (string) config('services.gemini.model', 'gemini-2.0-flash');
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

        try {
            $response = Http::withQueryParameters(['key' => $key])
                ->acceptJson()
                ->asJson()
                ->timeout(60)
                ->post($endpoint, [
                    'contents' => [
                        ['parts' => [['text' => $prompt]]],
                    ],
                    'generationConfig' => [
                        'temperature' => $temperature,
                        'maxOutputTokens' => $maxTokens,
                    ],
                ])
                ->throw();
        } catch (RequestException $exception) {
            $message = data_get($exception->response?->json(), 'error.message')
                ?: $exception->getMessage();

            throw new \RuntimeException('Gemini API error: ' . $message, previous: $exception);
        }

        $text = (string) data_get($response->json(), 'candidates.0.content.parts.0.text', '');
        if ($text === '') {
            $blockReason = (string) data_get($response->json(), 'promptFeedback.blockReason', '');
            if ($blockReason !== '') {
                throw new \RuntimeException('Permintaan diblokir oleh Gemini: ' . $blockReason);
            }

            throw new \RuntimeException('Gemini tidak mengembalikan teks. Coba lagi.');
        }

        return trim($text);
    }
}
