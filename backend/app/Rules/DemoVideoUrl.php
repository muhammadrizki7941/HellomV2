<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class DemoVideoUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Canonical path returned by the admin uploader; never a third-party embed.
        if (!is_string($value) || !preg_match('~^/media/showcase/[a-zA-Z0-9_-]+\.(mp4|webm)$~iD', $value)) {
            $fail('Unggah video MP4/WebM ke server melalui tombol Unggah video.');
        }
    }
}
