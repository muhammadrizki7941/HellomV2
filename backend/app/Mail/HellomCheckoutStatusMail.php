<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class HellomCheckoutStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(
        public string $subjectLine,
        public array $payload
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.hellom-checkout-status',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
