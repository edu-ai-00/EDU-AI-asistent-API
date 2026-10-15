<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The verification e-mail must come back in the language the app is
 * currently displaying.
 *
 * These assertions deliberately go through the `array` mail transport
 * rather than `Mail::fake()`: the fake stores the Mailable object and
 * `envelope()` resolves its `__()` calls lazily, so by the time an
 * assertion closure runs, the request's locale is long gone and every
 * subject would read back in the default language. The array transport
 * captures the message as it was actually rendered.
 *
 * The absent-header case is covered in `Tests\Unit\SetLocaleTest` instead:
 * Symfony's test request always injects `Accept-Language: en-us,en;q=0.5`,
 * so it cannot be exercised over HTTP here.
 */
class VerificationMailLocaleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function localeProvider(): array
    {
        return [
            'czech' => ['cs', 'Váš ověřovací kód pro EduAI'],
            'slovak' => ['sk', 'Váš overovací kód pre EduAI'],
            'english' => ['en', 'Your EduAI verification code'],
            // Browser-style header with region and quality values.
            'slovak with region' => ['sk-SK,sk;q=0.9,en;q=0.8', 'Váš overovací kód pre EduAI'],
            // A language we ship no translations for falls back to Czech.
            'unsupported' => ['de', 'Váš ověřovací kód pro EduAI'],
        ];
    }

    #[DataProvider('localeProvider')]
    public function test_verification_mail_subject_follows_accept_language(string $header, string $expectedSubject): void
    {
        $this->withHeaders(['Accept-Language' => $header])
            ->postJson('/api/email/send-code', ['email' => 'student@example.com'])
            ->assertOk()
            ->assertJson(['sent' => true]);

        $this->assertSame($expectedSubject, $this->sentMessage()->getSubject());
    }

    public function test_slovak_body_is_rendered_from_the_slovak_translations(): void
    {
        $this->withHeaders(['Accept-Language' => 'sk'])
            ->postJson('/api/email/send-code', ['email' => 'student@example.com'])
            ->assertOk();

        $body = $this->sentMessage()->getHtmlBody();

        $this->assertStringContainsString('Váš overovací kód', $body);
        $this->assertStringContainsString('Dobrý deň,', $body);
        $this->assertStringContainsString('Kód je platný 10 minút.', $body);
        $this->assertStringContainsString('<html lang="sk">', $body);
        // Guard against the Czech source wording leaking into the Slovak mail.
        $this->assertStringNotContainsString('Dobrý den,', $body);
    }

    public function test_czech_body_is_rendered_from_the_czech_translations(): void
    {
        $this->withHeaders(['Accept-Language' => 'cs'])
            ->postJson('/api/email/send-code', ['email' => 'student@example.com'])
            ->assertOk();

        $body = $this->sentMessage()->getHtmlBody();

        $this->assertStringContainsString('Dobrý den,', $body);
        $this->assertStringContainsString('Kód je platný 10 minut.', $body);
        $this->assertStringContainsString('<html lang="cs">', $body);
    }

    /**
     * The single message captured by the array transport.
     */
    private function sentMessage(): \Symfony\Component\Mime\Email
    {
        $messages = app('mailer')->getSymfonyTransport()->messages();

        $this->assertCount(1, $messages);

        $email = $messages[0]->getOriginalMessage();
        $this->assertInstanceOf(\Symfony\Component\Mime\Email::class, $email);

        return $email;
    }
}
