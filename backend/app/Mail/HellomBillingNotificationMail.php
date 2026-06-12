<?php

namespace App\Mail;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class HellomBillingNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $organizationName,
        public string $appName,
        public string $planName,
        public string $statusLabel,
        public int $amount,
        public ?CarbonInterface $startsAt = null,
        public ?CarbonInterface $endsAt = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Notifikasi billing Hellom',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.hellom-billing-notification',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
