<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ConsentGrantedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * $children are the accounts created by this call, with their one-time
     * plaintext codes; it is empty on both no-new-codes paths (a top-up
     * confirmation and a repeat request from an already-consented email —
     * see the template). $existingChildren then names the accounts that
     * already exist, so the mail still tells the parent something. Names,
     * never codes: child codes are stored as hashes and cannot be re-sent,
     * and the manage link is the only route back to a working one.
     *
     * @param  array<int, array{name: string, code: string}>  $children
     * @param  array<int, string>  $existingChildren
     */
    public function __construct(
        public array $children,
        public string $manageUrl,
        string $locale = 'cs',
        public array $existingChildren = [],
    ) {
        $this->locale($locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('consent.granted_subject', [], $this->locale));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.consent.granted',
            with: [
                'children' => $this->children,
                'existingChildren' => $this->existingChildren,
                'manageUrl' => $this->manageUrl,
                'locale' => $this->locale,
            ],
        );
    }
}
