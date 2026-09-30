<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class OrganizationTeamInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $organizationName,
        public string $role,
        public string $token,
        public ?string $registerUrl = null,
        public ?Carbon $expiresAt = null,
        // Sent from "Lupa kata sandi" to a staff email that has no account yet.
        public bool $activation = false,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->activation
                ? "Aktifkan akun kamu di {$this->organizationName}"
                : "Kamu diundang bergabung dengan {$this->organizationName} di Hellom",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.organization-team-invitation',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
