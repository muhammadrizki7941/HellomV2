<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent after a guest checkout is paid: sign-in link straight to the product, plus
 * login credentials when the account was created by that checkout.
 */
class DigitalProductAccessMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $productName,
        public string $transactionCode,
        public int $amount,
        public string $email,
        public string $accessUrl,
        public int $linkValidDays,
        public ?string $password,
        public string $loginUrl,
        public string $accessPeriod,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Akses produk: {$this->productName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.digital-product-access',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
