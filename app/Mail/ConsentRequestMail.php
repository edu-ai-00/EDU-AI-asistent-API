<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ConsentRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public string $confirmUrl,
        public string $textVersion,
        public int $ttlHours,
        string $locale = 'cs',
        public int $ageThreshold = 15,
        public string $privacyUrl = '',
    ) {
        $this->locale($locale);

        // One public /privacy route serves both locales — the page picks its
        // own language (see eduai-app lib/pages/legal/legal_page.dart), so
        // there is nothing locale-specific to build into the URL here.
        if ($this->privacyUrl === '') {
            $this->privacyUrl = rtrim((string) config('consent.web_base_url'), '/') . '/privacy';
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('consent.request_subject', [], $this->locale));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.consent.request',
            with: [
                'code' => $this->code,
                'confirmUrl' => $this->confirmUrl,
                'textVersion' => $this->textVersion,
                'ttlHours' => $this->ttlHours,
                'ageThreshold' => $this->ageThreshold,
                'privacyUrl' => $this->privacyUrl,
                'locale' => $this->locale,
            ],
        );
    }
}
