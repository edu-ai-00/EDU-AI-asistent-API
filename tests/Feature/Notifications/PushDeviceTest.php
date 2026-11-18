<?php

namespace Tests\Feature\Notifications;

use App\Models\PushDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * POST/DELETE /api/push-devices and the session binding (NOTIF-3, spec §1.5):
 * a device lives exactly as long as the session that registered it, follows
 * a token rotation, and moves when another user registers it.
 */
class PushDeviceTest extends TestCase
{
    use NotificationTestHelpers;
    use RefreshDatabase;

    private function forgetAuth(): void
    {
        $this->flushHeaders();
        app('auth')->forgetGuards();
    }

    public function test_register_ties_the_device_to_the_current_session(): void
    {
        $student = $this->student();
        [$headers, $tokenId] = $this->bearer($student);

        $this->withHeaders($headers)->postJson('/api/push-devices', [
            'token' => 'fcm-abc', 'platform' => 'android', 'app_version' => '1.0.26',
        ])->assertCreated()->assertJsonPath('data.platform', 'android');

        $device = PushDevice::where('token', 'fcm-abc')->sole();
        $this->assertSame($student->id, $device->user_id);
        $this->assertSame($tokenId, $device->personal_access_token_id);
        $this->assertSame('1.0.26', $device->app_version);

        // Registering again updates in place (200), re-enabling a dead token.
        $device->disable('UNREGISTERED');
        $this->withHeaders($headers)->postJson('/api/push-devices', ['token' => 'fcm-abc', 'platform' => 'ios'])
            ->assertOk()
            ->assertJsonPath('data.id', $device->id)
            ->assertJsonPath('data.platform', 'ios');
        $this->assertNull($device->fresh()->disabled_at);
        $this->assertSame(1, PushDevice::count());
    }

    public function test_registering_a_known_token_moves_it_to_the_new_user(): void
    {
        $first = $this->student();
        $second = $this->student();
        [$firstHeaders] = $this->bearer($first);
        [$secondHeaders, $secondToken] = $this->bearer($second);

        $this->withHeaders($firstHeaders)->postJson('/api/push-devices', ['token' => 'shared-tablet', 'platform' => 'android'])->assertCreated();
        $this->forgetAuth();
        $this->withHeaders($secondHeaders)->postJson('/api/push-devices', ['token' => 'shared-tablet', 'platform' => 'android'])->assertOk();

        $device = PushDevice::where('token', 'shared-tablet')->sole();
        $this->assertSame($second->id, $device->user_id);
        $this->assertSame($secondToken, $device->personal_access_token_id);
    }

    public function test_validation(): void
    {
        [$headers] = $this->bearer($this->student());

        $this->withHeaders($headers)->postJson('/api/push-devices', ['token' => 'x', 'platform' => 'windows'])->assertStatus(422);
        $this->withHeaders($headers)->postJson('/api/push-devices', ['platform' => 'ios'])->assertStatus(422);
        $this->withHeaders($headers)->postJson('/api/push-devices', ['token' => str_repeat('a', 513), 'platform' => 'ios'])->assertStatus(422);
    }

    public function test_delete_is_idempotent_and_only_touches_own_devices(): void
    {
        $student = $this->student();
        $other = $this->student();
        $this->device($student, ['token' => 'mine']);
        $this->device($other, ['token' => 'theirs']);
        [$headers] = $this->bearer($student);

        $this->withHeaders($headers)->deleteJson('/api/push-devices', ['token' => 'mine'])->assertOk()->assertExactJson(['data' => true]);
        $this->withHeaders($headers)->deleteJson('/api/push-devices', ['token' => 'mine'])->assertOk()->assertExactJson(['data' => true]);
        $this->withHeaders($headers)->deleteJson('/api/push-devices', ['token' => 'theirs'])->assertOk();

        $this->assertFalse(PushDevice::where('token', 'mine')->exists());
        $this->assertTrue(PushDevice::where('token', 'theirs')->exists());
    }

    public function test_logout_deletes_the_sessions_devices(): void
    {
        $student = $this->student();
        [$headers, $tokenId] = $this->bearer($student);
        $this->device($student, ['token' => 'phone', 'personal_access_token_id' => $tokenId]);
        $this->device($student, ['token' => 'other-session']);

        $this->withHeaders($headers)->postJson('/api/logout')->assertOk();

        $this->assertFalse(PersonalAccessToken::whereKey($tokenId)->exists());
        $this->assertFalse(PushDevice::where('token', 'phone')->exists());
        $this->assertTrue(PushDevice::where('token', 'other-session')->exists());
    }

    public function test_an_expired_session_pruned_takes_its_device(): void
    {
        $student = $this->student();
        [, $tokenId] = $this->bearer($student);
        $this->device($student, ['token' => 'phone', 'personal_access_token_id' => $tokenId]);

        PersonalAccessToken::whereKey($tokenId)->update(['expires_at' => now()->subDays(2)]);
        $this->artisan('sanctum:prune-expired', ['--hours' => 24])->assertSuccessful();

        $this->assertFalse(PushDevice::where('token', 'phone')->exists());
    }

    public function test_token_refresh_moves_the_device_to_the_new_session(): void
    {
        $student = $this->student();
        [$headers, $tokenId] = $this->bearer($student, 'auth_token');
        $this->withHeaders($headers)->postJson('/api/push-devices', ['token' => 'phone', 'platform' => 'android'])->assertCreated();

        $response = $this->withHeaders($headers)->postJson('/api/auth/refresh')->assertOk();

        $newTokenId = PersonalAccessToken::findToken($response->json('token'))->id;
        $this->assertNotSame($tokenId, $newTokenId);
        $this->assertFalse(PersonalAccessToken::whereKey($tokenId)->exists());
        $this->assertSame($newTokenId, PushDevice::where('token', 'phone')->value('personal_access_token_id'));
    }

    public function test_admin_token_refresh_moves_the_device_too(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $token = $admin->createToken('admin-token', ['admin'], now()->addHours(8));
        $this->device($admin, ['token' => 'admin-phone', 'personal_access_token_id' => $token->accessToken->id]);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token->plainTextToken])
            ->postJson('/api/admin/auth/refresh')
            ->assertOk();

        $newTokenId = PersonalAccessToken::findToken($response->json('token'))->id;
        $this->assertSame($newTokenId, PushDevice::where('token', 'admin-phone')->value('personal_access_token_id'));
    }

    public function test_deactivation_revokes_sessions_and_their_devices(): void
    {
        $student = $this->student();
        [, $tokenId] = $this->bearer($student);
        $this->device($student, ['token' => 'phone', 'personal_access_token_id' => $tokenId]);

        $student->deactivate(null);

        $this->assertFalse(PushDevice::where('token', 'phone')->exists());
    }
}
