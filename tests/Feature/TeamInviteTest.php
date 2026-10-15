<?php

namespace Tests\Feature;

use App\Mail\TeamInviteMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * BR-38FYMX: creating a teacher account told nobody.
 *
 * POST /admin/team wrote the row and returned JSON — the person named in it
 * never heard that they could sign in, so every account had to be handed over
 * out of band. The invitation carries no token on purpose: the admin app
 * already signs people in with a one-time code mailed to this same address,
 * so a link to the login page is all the mail needs to be, and nothing in it
 * is a secret that could go stale or leak.
 */
class TeamInviteTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        return User::factory()
            ->create(['role' => 'admin'])
            ->createToken('a')
            ->plainTextToken;
    }

    public function test_creating_a_teacher_sends_an_invitation(): void
    {
        Mail::fake();

        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken())
            ->postJson('/api/admin/team', [
                'name' => 'Jana Nováková',
                'email' => 'Jana@Skola.cz',
                'role' => 'teacher',
            ])
            ->assertStatus(201);

        Mail::assertSent(TeamInviteMail::class, function (TeamInviteMail $mail) {
            return $mail->hasTo('jana@skola.cz')
                && $mail->role === 'teacher'
                && $mail->loginUrl === 'https://admin.test/login';
        });
    }

    public function test_promoting_an_existing_student_also_sends_one(): void
    {
        Mail::fake();

        User::factory()->create(['email' => 'petr@skola.cz', 'role' => 'student']);

        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken())
            ->postJson('/api/admin/team', [
                'name' => 'Petr Svoboda',
                'email' => 'petr@skola.cz',
                'role' => 'teacher',
            ])
            ->assertStatus(201);

        Mail::assertSent(TeamInviteMail::class, fn (TeamInviteMail $m) => $m->hasTo('petr@skola.cz'));
    }

    public function test_no_mail_when_the_account_already_existed_as_a_team_member(): void
    {
        Mail::fake();

        User::factory()->create(['email' => 'uz@skola.cz', 'role' => 'teacher']);

        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken())
            ->postJson('/api/admin/team', [
                'name' => 'Uz Tam',
                'email' => 'uz@skola.cz',
                'role' => 'teacher',
            ])
            ->assertStatus(422);

        Mail::assertNothingSent();
    }

    public function test_a_failing_mailer_does_not_fail_the_account_creation(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));

        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken())
            ->postJson('/api/admin/team', [
                'name' => 'Eva Dvořáková',
                'email' => 'eva@skola.cz',
                'role' => 'teacher',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('users', ['email' => 'eva@skola.cz', 'role' => 'teacher']);
    }

    /**
     * BR-23VT3U: an admin whose browser runs in English invited a teacher and
     * the teacher got the invitation in English.
     *
     * The admin proxy forwards the browser's Accept-Language, and SetLocale
     * turns it into the request locale — but that is the inviter's language,
     * not the recipient's. The admin panel the mail points at is Czech only.
     */
    public function test_the_invitation_is_czech_whatever_language_the_inviter_browses_in(): void
    {
        Mail::fake();

        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken())
            ->withHeader('Accept-Language', 'en-GB,en;q=0.9')
            ->postJson('/api/admin/team', [
                'name' => 'Ondřej Učitel',
                'email' => 'ondrej@skola.cz',
                'role' => 'teacher',
            ])
            ->assertStatus(201);

        Mail::assertSent(TeamInviteMail::class, function (TeamInviteMail $mail) {
            $rendered = $mail->render();

            return $mail->locale === 'cs'
                && str_contains($rendered, 'Vítejte v administraci EduAI')
                && str_contains($rendered, 'rolí učitel');
        });
    }

    public function test_the_invitation_renders_in_every_supported_locale(): void
    {
        foreach (['cs', 'en', 'sk'] as $locale) {
            $this->app->setLocale($locale);

            $rendered = (new TeamInviteMail('Jana', 'teacher', 'https://admin.test/login'))->render();

            $this->assertStringContainsString('https://admin.test/login', $rendered);
            $this->assertStringNotContainsString('mail.team_invite', $rendered);
        }
    }
}
