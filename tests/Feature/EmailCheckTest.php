<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * POST /api/email/check — asked by the app before any code is sent, so an
 * unknown e-mail meets the age gate first and an existing one goes straight
 * to its sign-in code (BR-J2WJEQ).
 */
class EmailCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unknown_email_does_not_exist(): void
    {
        $this->postJson('/api/email/check', ['email' => 'novy@example.test'])
            ->assertOk()
            ->assertExactJson(['exists' => false]);
    }

    public function test_an_existing_account_exists(): void
    {
        User::factory()->create(['email' => 'zpet@example.test']);

        $this->postJson('/api/email/check', ['email' => 'zpet@example.test'])
            ->assertOk()
            ->assertExactJson(['exists' => true]);
    }

    /**
     * verify() finds the account case-insensitively, so the check must too:
     * otherwise an existing user who typed a capital letter is asked for their
     * age as if registering, and a "no" sends them to the consent flow.
     */
    public function test_the_lookup_ignores_case_like_verify_does(): void
    {
        User::factory()->create(['email' => 'zpet@example.test']);

        $this->postJson('/api/email/check', ['email' => 'Zpet@Example.TEST'])
            ->assertOk()
            ->assertExactJson(['exists' => true]);
    }

    public function test_checking_sends_nothing(): void
    {
        Mail::fake();

        $this->postJson('/api/email/check', ['email' => 'novy@example.test'])
            ->assertOk();

        Mail::assertNothingOutgoing();
    }
}
