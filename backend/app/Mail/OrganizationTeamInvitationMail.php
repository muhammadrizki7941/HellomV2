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
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Undangan bergabung tim organisasi',
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
