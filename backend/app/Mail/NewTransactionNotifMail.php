<?php

namespace App\Mail;

use App\Models\OrganizationWalletTransaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewTransactionNotifMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public OrganizationWalletTransaction $transaction
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $status = $this->transaction->direction === 'credit' ? 'Paid' : 'Pending';
        return new Envelope(
            subject: 'Transaksi Masuk - ' . $status . ' Rp ' . number_format($this->transaction->amount),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.new-transaction-notification',
            with: [
                'transaction' => $this->transaction,
                'status' => $this->transaction->direction === 'credit' ? 'paid' : 'pending',
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
