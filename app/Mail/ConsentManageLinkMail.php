<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A resent magic link for an already-confirmed consent, triggered from
 * request-link. Deliberately not ConsentGrantedMail: that mail's
 * empty-children branch (Task 7's top-up case) says "Souhlas potvrzen" /
 * "souhlas jsme znovu potvrdili" — statements that are all false for a plain
 * link resend. Anyone who knows the guardian's email can trigger this mail
 * (request-link needs no live token, by design — see ConsentManageController
 * ::requestLink()), so it must never claim a consent event that did not
 * happen.
 */
class ConsentManageLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $manageUrl,
        string $locale = 'cs',
    ) {
        $this->locale($locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('consent.manage_link_subject', [], $this->locale));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.consent.manage_link',
            with: [
                'manageUrl' => $this->manageUrl,
                'locale' => $this->locale,
            ],
        );
    }
}
