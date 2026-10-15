<?php

namespace Tests\Feature;

use App\Mail\ConsentGrantedMail;
use App\Mail\ConsentRequestMail;
use App\Mail\ConsentRevokedMail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConsentMailTest extends TestCase
{
    public function test_request_mail_renders_code_and_link_in_czech(): void
    {
        $mail = new ConsentRequestMail(
            code: 'AB12CD',
            confirmUrl: 'https://app.edu-ai.eu/consent/confirm?id=abc',
            textVersion: '2026-08-13',
            ttlHours: 72,
            locale: 'cs',
        );

        $rendered = $mail->render();

        $this->assertStringContainsString('AB12CD', $rendered);
        $this->assertStringContainsString('https://app.edu-ai.eu/consent/confirm?id=abc', $rendered);
        $this->assertStringContainsString('72', $rendered);
        $this->assertStringContainsString('Souhlas', $rendered);
        $this->assertSame('EDU-AI: potvrzení souhlasu rodiče', $mail->envelope()->subject);
    }

    public function test_request_mail_renders_in_slovak(): void
    {
        $mail = new ConsentRequestMail(
            code: 'AB12CD',
            confirmUrl: 'https://app.edu-ai.eu/consent/confirm?id=abc',
            textVersion: '2026-08-13',
            ttlHours: 72,
            locale: 'sk',
        );

        $this->assertSame('EDU-AI: potvrdenie súhlasu rodiča', $mail->envelope()->subject);
        $this->assertStringContainsString('Súhlas', $mail->render());
    }

    /**
     * The privacy-policy link must render *above* the "Potvrdit souhlas" CTA
     * (BR-F9CR44). Consent is only valid if it is informed, so the full
     * description of the processing has to be reachable before the parent
     * presses the button — not in the fine print below it, where it used to
     * sit between the code TTL and the "ignore this email" line.
     *
     * Asserted per locale because the copy lives in lang/{cs,sk}/consent.php:
     * a key added to one file and forgotten in the other renders the literal
     * "consent.request_privacy_intro" into a legally load-bearing email.
     */
    #[DataProvider('requestMailLocales')]
    public function test_request_mail_puts_the_privacy_link_above_the_confirm_button(
        string $locale,
        string $privacyIntro,
        string $privacyLabel,
        string $confirmQuestion,
    ): void {
        config(['consent.web_base_url' => 'https://app.edu-ai.eu']);

        $confirmUrl = 'https://app.edu-ai.eu/consent/confirm?id=abc';
        $rendered = (new ConsentRequestMail(
            code: 'AB12CD',
            confirmUrl: $confirmUrl,
            textVersion: '2026-08-13',
            ttlHours: 72,
            locale: $locale,
        ))->render();

        // No un-translated key leaked through.
        $this->assertStringNotContainsString('consent.request_', $rendered);

        $this->assertStringContainsString($privacyIntro, $rendered);
        $this->assertStringContainsString($confirmQuestion, $rendered);

        $privacyAt = strpos($rendered, 'https://app.edu-ai.eu/privacy');
        $ctaAt = strpos($rendered, $confirmUrl);
        $codeAt = strpos($rendered, 'AB12CD');

        $this->assertIsInt($privacyAt, "privacy URL missing from the {$locale} request mail");
        $this->assertIsInt($ctaAt, "confirm URL missing from the {$locale} request mail");
        $this->assertIsInt($codeAt, "confirmation code missing from the {$locale} request mail");

        $this->assertLessThan(
            $ctaAt,
            $privacyAt,
            "\"{$privacyLabel}\" must precede the confirm button in the {$locale} request mail",
        );
        $this->assertLessThan(
            $ctaAt,
            (int) strpos($rendered, $confirmQuestion),
            "\"{$confirmQuestion}\" must precede the confirm button in the {$locale} request mail",
        );
        $this->assertLessThan(
            $codeAt,
            $ctaAt,
            "the confirm button must precede the fallback code in the {$locale} request mail",
        );
    }

    public static function requestMailLocales(): array
    {
        return [
            'czech' => [
                'cs',
                'Kompletní popis zpracování:',
                'Zásady zpracování osobních údajů',
                'Souhlasíte se zpracováním údajů?',
            ],
            'slovak' => [
                'sk',
                'Kompletný popis spracovania:',
                'Zásady spracovania osobných údajov',
                'Súhlasíte so spracovaním údajov?',
            ],
        ];
    }

    public function test_granted_mail_lists_every_child_code(): void
    {
        $mail = new ConsentGrantedMail(
            children: [
                ['name' => 'Stydlivý delfín', 'code' => 'AAA111'],
                ['name' => 'Odvážný ježek', 'code' => 'BBB222'],
            ],
            manageUrl: 'https://app.edu-ai.eu/consent/manage/tok',
            locale: 'cs',
        );

        $rendered = $mail->render();

        $this->assertStringContainsString('AAA111', $rendered);
        $this->assertStringContainsString('BBB222', $rendered);
        $this->assertStringContainsString('Stydlivý delfín', $rendered);
        $this->assertStringContainsString('https://app.edu-ai.eu/consent/manage/tok', $rendered);
    }

    /**
     * A top-up confirmation (see ConsentService::provisionChildren()) mails
     * ConsentGrantedMail with an empty $children array — the guardian
     * already has every child this consent provisions, so there is nothing
     * new to send. Without a guard in the template this rendered an empty
     * "here are your codes" block above the manage link. Assert the codes
     * markup is absent, the alternate copy pointing at the manage link is
     * present instead, and the manage URL — the only actually useful thing
     * in this version of the email — still renders correctly.
     */
    public function test_granted_mail_with_no_new_codes_hides_the_codes_block_and_leads_with_the_manage_link(): void
    {
        $mail = new ConsentGrantedMail(
            children: [],
            manageUrl: 'https://app.edu-ai.eu/consent/manage/tok',
            locale: 'cs',
        );

        $rendered = $mail->render();

        // Checking the actual content markup, not the CSS class name — the
        // stylesheet in the shared layout defines a ".child-code" rule
        // unconditionally, so a plain substring check on "child-code" would
        // match that rule even when the content block never renders.
        $this->assertStringNotContainsString('<div class="child">', $rendered, 'no codes block when there are no new codes');
        $this->assertStringNotContainsString('<div class="child-code">', $rendered);
        $this->assertStringContainsString(__('consent.granted_no_new_codes_intro', [], 'cs'), $rendered);
        $this->assertStringNotContainsString(__('consent.granted_intro', [], 'cs'), $rendered, 'the "here are your codes" copy must not appear when there are none');
        $this->assertStringContainsString('https://app.edu-ai.eu/consent/manage/tok', $rendered);
    }

    /** Same as above, in Slovak — the translation key must exist in both. */
    public function test_granted_mail_with_no_new_codes_renders_in_slovak(): void
    {
        $mail = new ConsentGrantedMail(
            children: [],
            manageUrl: 'https://app.edu-ai.eu/consent/manage/tok',
            locale: 'sk',
        );

        $rendered = $mail->render();

        $this->assertStringNotContainsString('<div class="child">', $rendered);
        $this->assertStringContainsString(__('consent.granted_no_new_codes_intro', [], 'sk'), $rendered);
        $this->assertStringContainsString('https://app.edu-ai.eu/consent/manage/tok', $rendered);
    }

    /**
     * BR-2JW929: both no-new-codes paths (a top-up confirmation and a repeat
     * request from an already-consented email) name the accounts that do
     * exist, so the parent knows which child to regenerate a code for on the
     * manage page. Names only — codes are hashed and cannot be re-sent, and
     * rendering the code markup here would be a lie about what is in the
     * mail.
     */
    public function test_granted_mail_with_no_new_codes_names_the_existing_accounts_without_codes(): void
    {
        $mail = new ConsentGrantedMail(
            children: [],
            manageUrl: 'https://app.edu-ai.eu/consent/manage/tok',
            locale: 'cs',
            existingChildren: ['Stydlivý delfín', 'Odvážný ježek'],
        );

        $rendered = $mail->render();

        $this->assertStringContainsString('Stydlivý delfín', $rendered);
        $this->assertStringContainsString('Odvážný ježek', $rendered);
        $this->assertStringContainsString(__('consent.granted_existing_children', [], 'cs'), $rendered);
        $this->assertStringNotContainsString('<div class="child-code">', $rendered, 'there are no codes to show');
        $this->assertStringContainsString('https://app.edu-ai.eu/consent/manage/tok', $rendered);
    }

    /** Same as above, in Slovak — the translation key must exist in both. */
    public function test_granted_mail_names_the_existing_accounts_in_slovak(): void
    {
        $mail = new ConsentGrantedMail(
            children: [],
            manageUrl: 'https://app.edu-ai.eu/consent/manage/tok',
            locale: 'sk',
            existingChildren: ['Plachý delfín'],
        );

        $rendered = $mail->render();

        $this->assertStringContainsString('Plachý delfín', $rendered);
        $this->assertStringContainsString(__('consent.granted_existing_children', [], 'sk'), $rendered);
    }

    public function test_revoked_mail_states_the_erasure_deadline(): void
    {
        $mail = new ConsentRevokedMail(purgeAfterDays: 30, locale: 'cs');

        $this->assertStringContainsString('30', $mail->render());
    }
}
