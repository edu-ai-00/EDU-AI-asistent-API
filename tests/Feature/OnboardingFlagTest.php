<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\StudentCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The sign-up onboarding — nickname, colour profile, avatar vibe — runs on an
 * account's very first entry into the app and never again (BR-YXVMKY).
 *
 * Every login path used to answer that question for itself and two answered
 * it wrongly in opposite directions: SSO re-onboarded the same account on
 * every single login, and a student signing in with their login code was
 * never onboarded at all. These pin the one column all of them now read.
 */
class OnboardingFlagTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(User $user): self
    {
        $token = $user->createToken('test')->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    /** @return array{0: User, 1: string} */
    private function studentWithCode(bool $onboarded): array
    {
        $code = StudentCodes::generate();
        $student = User::create([
            'name' => 'Jana Nováková',
            'login_code_hash' => StudentCodes::hash($code),
            'onboarded_at' => $onboarded ? now() : null,
        ]);

        return [$student, $code];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Student login codes
    // ═══════════════════════════════════════════════════════════════════════

    public function test_a_students_first_code_login_asks_for_the_onboarding(): void
    {
        // The teacher created the account, so it has a name and a history on
        // the server — nothing about it looks "new". Only onboarded_at can
        // say the student has never actually opened the app.
        [, $code] = $this->studentWithCode(onboarded: false);

        $response = $this->postJson('/api/resolve-code', ['code' => $code]);

        $response->assertOk();
        $response->assertJsonPath('type', 'user');
        $response->assertJsonPath('profile_setup_required', true);
    }

    public function test_a_student_who_has_onboarded_signs_straight_in(): void
    {
        [, $code] = $this->studentWithCode(onboarded: true);

        $response = $this->postJson('/api/resolve-code', ['code' => $code]);

        $response->assertOk();
        $response->assertJsonPath('profile_setup_required', false);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // The e-mail-code path reads it off /api/user
    // ═══════════════════════════════════════════════════════════════════════

    public function test_the_user_endpoint_reports_the_onboarding_state(): void
    {
        // Signing in with an e-mail code fetches /api/user to learn who it
        // just signed in as; that response is where this path gets its
        // verdict, so the column has to be on it.
        $user = User::factory()->notOnboarded()->create();

        $this->actingAsUser($user)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('onboarded_at', null);

        $user->markOnboarded();

        $this->assertNotNull(
            $this->actingAsUser($user)->getJson('/api/user')->json('onboarded_at'),
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Completing it
    // ═══════════════════════════════════════════════════════════════════════

    public function test_finishing_the_onboarding_stamps_the_account(): void
    {
        [$student, $code] = $this->studentWithCode(onboarded: false);

        $this->actingAsUser($student)
            ->putJson('/api/user/profile', [
                'name' => 'Jana N.',
                'onboarding_completed' => true,
            ])
            ->assertOk();

        $student->refresh();
        $this->assertNotNull($student->onboarded_at);

        // And the next login no longer asks.
        $this->postJson('/api/resolve-code', ['code' => $code])
            ->assertJsonPath('profile_setup_required', false);
    }

    public function test_a_replayed_completion_does_not_move_the_timestamp(): void
    {
        // The client queues this write like any other profile change so it
        // survives being offline, and a queue can replay an entry. The
        // timestamp records when the student joined; a retry must not
        // rewrite that.
        // The column has second precision, so compare formatted values.
        $joined = now()->subMonths(3)->startOfSecond();
        $user = User::factory()->create(['onboarded_at' => $joined]);

        $this->actingAsUser($user)
            ->putJson('/api/user/profile', ['onboarding_completed' => true])
            ->assertOk();

        $user->refresh();
        $this->assertSame(
            $joined->format('Y-m-d H:i:s'),
            $user->onboarded_at->format('Y-m-d H:i:s'),
        );
    }

    public function test_a_profile_write_without_the_flag_leaves_it_alone(): void
    {
        $user = User::factory()->notOnboarded()->create();

        $this->actingAsUser($user)
            ->putJson('/api/user/profile', ['name' => 'Jen jméno'])
            ->assertOk();

        $this->assertNull($user->refresh()->onboarded_at);
    }

    public function test_a_minor_can_finish_the_onboarding(): void
    {
        // A minor's name is server-assigned and locked, so their completion
        // carries no name at all — the 403 that guards the nickname must not
        // catch it, or a child could never get out of the onboarding
        // (BR-8FDF37 locks the name, BR-YXVMKY needs the flag through).
        $minor = User::factory()->notOnboarded()->create([
            'email' => null,
            'is_minor' => true,
        ]);

        $this->actingAsUser($minor)
            ->putJson('/api/user/profile', ['onboarding_completed' => true])
            ->assertOk();

        $this->assertNotNull($minor->refresh()->onboarded_at);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // The backfill
    // ═══════════════════════════════════════════════════════════════════════

    public function test_the_backfill_counts_every_existing_account_as_joined(): void
    {
        // The whole point of the report: an account that is already using the
        // app must not be sent through the onboarding when this ships.
        // RefreshDatabase runs the migration against an empty table, so the
        // backfill is invoked here against rows that predate it, the way
        // production will actually exercise it.
        $existing = DB::table('users')->insertGetId([
            'name' => 'Dávný uživatel',
            'email' => 'davny@example.com',
            'is_guest' => false,
            'onboarded_at' => null,
            'created_at' => now()->subYear(),
            'updated_at' => now(),
        ]);

        $guest = DB::table('users')->insertGetId([
            'name' => 'Chytry Tucnak',
            'email' => null,
            'is_guest' => true,
            'onboarded_at' => null,
            'created_at' => now()->subDay(),
            'updated_at' => now(),
        ]);

        $migration = require database_path(
            'migrations/2026_09_11_000000_add_onboarded_at_to_users_table.php',
        );
        $migration->up();

        $this->assertNotNull(
            DB::table('users')->where('id', $existing)->value('onboarded_at'),
            'an account that predates the column has already joined',
        );
        $this->assertNull(
            DB::table('users')->where('id', $guest)->value('onboarded_at'),
            'a guest has not joined yet — the onboarding is what runs when they do',
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Guests
    // ═══════════════════════════════════════════════════════════════════════

    public function test_a_guest_counts_as_onboarded(): void
    {
        // A guest arrived on a course PIN and is owed a course, not a profile
        // form. Joining for real is what triggers the onboarding — and an
        // upgraded guest carries a null onboarded_at into its new account.
        $guest = User::factory()->notOnboarded()->create([
            'email' => null,
            'is_guest' => true,
        ]);

        $this->assertTrue($guest->hasOnboarded());
    }
}
