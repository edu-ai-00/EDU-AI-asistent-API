<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ConsentRevokedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public int $purgeAfterDays,
        string $locale = 'cs',
    ) {
        $this->locale($locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('consent.revoked_subject', [], $this->locale));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.consent.revoked',
            with: [
                'purgeAfterDays' => $this->purgeAfterDays,
                'locale' => $this->locale,
            ],
        );
    }
}
