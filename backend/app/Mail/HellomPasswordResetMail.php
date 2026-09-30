<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class HellomPasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $email,
        public string $token,
        public int $expiresInMinutes,
        public ?string $resetUrl = null,
        public ?string $name = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Buat kata sandi baru untuk akun Hellom kamu',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.hellom-password-reset',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
