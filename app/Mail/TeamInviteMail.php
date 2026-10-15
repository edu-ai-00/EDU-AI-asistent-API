<?php

namespace App\Mail;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when an admin creates a teacher or admin account (BR-38FYMX).
 *
 * Carries no token: the admin app already signs people in with a one-time
 * code sent to this same address, so the invitation only has to say the
 * account exists and point at the login page. Nothing here is a secret,
 * which is why it is safe for the mail to sit in an inbox indefinitely.
 *
 * The invite itself does expire (BR-XW6CWV) — the account refuses its first
 * sign-in after $expiresAt until an admin resends it — so the mail says by
 * when, and the same mail goes out again on a resend.
 */
class TeamInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $role,
        public string $loginUrl,
        public ?CarbonInterface $expiresAt = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.team_invite.subject'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.team-invite',
            with: [
                'name' => $this->name,
                'roleLabel' => __('mail.team_invite.role_' . $this->role),
                'loginUrl' => $this->loginUrl,
                'expiresOn' => $this->expiresAt
                    ?->copy()
                    ->timezone('Europe/Prague')
                    ->locale(app()->getLocale())
                    ->isoFormat('LL'),
            ],
        );
    }
}
