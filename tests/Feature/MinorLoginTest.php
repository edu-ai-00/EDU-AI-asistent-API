<?php

namespace Tests\Feature;

use App\Models\Consent;
use App\Models\Guardian;
use App\Models\User;
use App\Support\StudentCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MinorLoginTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: string, 2: Consent} */
    private function minorWithCode(string $status = 'confirmed'): array
    {
        $guardian = Guardian::create(['email' => 'rodic@example.com', 'locale' => 'cs']);
        $consent = Consent::startFor($guardian, 'cs', 'CZ');
        $consent->forceFill(['status' => $status, 'confirmed_at' => now()])->save();

        $code = StudentCodes::generate();
        $child = User::create([
            'name' => 'Stydlivý delfín',
            'is_minor' => true,
            'guardian_id' => $guardian->id,
            'login_code_hash' => StudentCodes::hash($code),
        ]);

        return [$child, $code, $consent];
    }

    public function test_minor_logs_in_with_a_hashed_code(): void
    {
        [$child, $code] = $this->minorWithCode();

        $response = $this->postJson('/api/resolve-code', ['code' => $code]);

        $response->assertOk();
        $response->assertJsonPath('type', 'user');
        $response->assertJsonPath('user.id', $child->id);
        $response->assertJsonPath('minor', true);
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_code_matching_is_case_insensitive_and_folds_confusables(): void
    {
        [, $code] = $this->minorWithCode();

        $typed = str_replace(['1', '0'], ['I', 'O'], strtolower($code));

        $this->postJson('/api/resolve-code', ['code' => $typed])->assertOk();
    }

    public function test_revoked_consent_blocks_the_login(): void
    {
        [, $code] = $this->minorWithCode('revoked');

        $this->postJson('/api/resolve-code', ['code' => $code])->assertStatus(403);
    }

    /**
     * C1 regression test. CodeController used to ask "does the guardian
     * have ANY confirmed consent", which a guardian holding two
     * simultaneously CONFIRMED rows could satisfy even after one of them
     * was revoked. This forces that exact pre-fix state directly — bypassing
     * ConsentService entirely, since its supersede-on-confirm fix (C1 part
     * 1) now prevents reaching it through the normal request/confirm flow —
     * and revokes only the more recent of the two rows, exactly as
     * ConsentManageController::revoke() resolves and (pre C1 part 2) used to
     * revoke only the token's own consent. The older row is left CONFIRMED
     * on purpose: this test exists to prove the CodeController check itself
     * is fail-closed, independent of whether ConsentService::revoke()'s
     * belt-and-braces fix also happens to catch this case.
     */
    public function test_login_is_blocked_when_the_most_recent_of_two_confirmed_consents_is_revoked(): void
    {
        $guardian = Guardian::create(['email' => 'rodic@example.com', 'locale' => 'cs']);

        $older = Consent::startFor($guardian, 'cs', 'CZ');
        $older->forceFill(['status' => Consent::STATUS_CONFIRMED, 'confirmed_at' => now()->subDay()])->save();

        $current = Consent::startFor($guardian, 'cs', 'CZ');
        $current->forceFill(['status' => Consent::STATUS_CONFIRMED, 'confirmed_at' => now()])->save();
        $current->forceFill(['status' => Consent::STATUS_REVOKED, 'revoked_at' => now()])->save();

        $code = StudentCodes::generate();
        User::create([
            'name' => 'Stydlivý delfín',
            'is_minor' => true,
            'guardian_id' => $guardian->id,
            'login_code_hash' => StudentCodes::hash($code),
        ]);

        $this->assertSame('confirmed', $older->fresh()->status, 'sanity check: the older row must still say confirmed');

        $this->postJson('/api/resolve-code', ['code' => $code])->assertStatus(403);
    }

    public function test_guest_data_is_never_absorbed_into_a_minor_account(): void
    {
        [$child, $code] = $this->minorWithCode();

        $guest = User::create([
            'name' => 'Host',
            'is_guest' => true,
            'device_id' => 'dev-1',
        ]);
        $guestToken = $guest->createToken('guest')->plainTextToken;

        $this->withHeaders(['Authorization' => "Bearer {$guestToken}"])
            ->postJson('/api/resolve-code', ['code' => $code])
            ->assertOk();

        $this->assertDatabaseMissing('user_merges', ['target_user_id' => $child->id]);
    }

    public function test_user_endpoint_exposes_the_minor_flag(): void
    {
        [$child] = $this->minorWithCode();
        $token = $child->createToken('t')->plainTextToken;

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('is_minor', true);
    }

    public function test_minor_cannot_change_their_display_name(): void
    {
        [$child] = $this->minorWithCode();
        $token = $child->createToken('t')->plainTextToken;

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->putJson('/api/user/profile', ['name' => 'Jan Novák'])
            ->assertStatus(403);

        $this->assertSame('Stydlivý delfín', $child->fresh()->name);
    }

    public function test_minor_can_still_change_their_avatar(): void
    {
        [$child] = $this->minorWithCode();
        $token = $child->createToken('t')->plainTextToken;

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->putJson('/api/user/profile', ['avatar_index' => 3])
            ->assertOk();

        $this->assertSame(3, $child->fresh()->avatar_index);
    }

    /**
     * BR-8FDF37 follow-up. updateProfile's 403 only guards PUT /api/user/profile
     * — /register-verified is a second, public write path to a real name that
     * had no is_minor check at all. It is reachable: ProfilePage shows a
     * "Log In" button whenever the local email is empty (true for a minor,
     * same as a guest), which opens AuthPage's sign-up form; verifying a new
     * email there routes to ProfileSetupPage, whose name field was — until
     * this fix — unguarded, and Continue posts here. This proves the server
     * refuses a minor-authenticated caller regardless of the client lock, and
     * that no new account is created as a side effect of the attempt.
     */
    public function test_minor_cannot_register_a_real_name_via_register_verified(): void
    {
        [$child] = $this->minorWithCode();
        $token = $child->createToken('t')->plainTextToken;

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/register-verified', [
                'email' => 'skutecne.jmeno@example.com',
                'name' => 'Jan Novák',
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'skutecne.jmeno@example.com']);
        $this->assertSame('Stydlivý delfín', $child->fresh()->name);
    }

    /**
     * I7 regression test. login_code_hash is a minor's SOLE credential;
     * /api/user is called on every session bootstrap and the Flutter client
     * may cache or log its response. ConsentManageController::show() is
     * already held to this standard for guardians (ConsentManageTest); this
     * proves AuthController::user() is too.
     */
    public function test_user_endpoint_never_exposes_the_login_code_hash(): void
    {
        [$child] = $this->minorWithCode();
        $token = $child->createToken('t')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/user')
            ->assertOk();

        $this->assertArrayNotHasKey('login_code_hash', $response->json());
        $this->assertArrayNotHasKey('login_code', $response->json());
    }
}
