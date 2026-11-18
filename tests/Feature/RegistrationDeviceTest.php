<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\StudentCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The device of an account's first sign-in to the app (BR-CMA88Y), shown in
 * the admin student detail for a pupil who registered and did nothing else.
 */
class RegistrationDeviceTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE = ['X-Device-Platform' => 'web', 'X-Device-Type' => 'desktop'];

    public function test_signing_up_records_the_device(): void
    {
        $this->withHeaders(self::DEVICE)
            ->postJson('/api/guest/register', ['device_id' => 'device-1', 'name' => 'Host'])
            ->assertSuccessful();

        $user = User::where('device_id', 'device-1')->firstOrFail();
        $this->assertSame('web', $user->registration_platform);
        $this->assertSame('desktop', $user->registration_device_type);
        $this->assertNotNull($user->registration_device_at);
    }

    public function test_a_provisioned_pupils_first_code_login_records_the_device(): void
    {
        $code = StudentCodes::generate();
        $user = User::factory()->notOnboarded()->create([
            'role' => 'student',
            'login_code_hash' => StudentCodes::hash($code),
        ]);

        $this->withHeaders(['X-Device-Platform' => 'android', 'X-Device-Type' => 'tablet'])
            ->postJson('/api/resolve-code', ['code' => $code])
            ->assertOk();

        $user->refresh();
        $this->assertSame('android', $user->registration_platform);
        $this->assertSame('tablet', $user->registration_device_type);
    }

    public function test_a_later_sign_in_keeps_the_first_device(): void
    {
        $code = StudentCodes::generate();
        $user = User::factory()->notOnboarded()->create([
            'role' => 'student',
            'login_code_hash' => StudentCodes::hash($code),
        ]);

        $this->withHeaders(['X-Device-Platform' => 'ios', 'X-Device-Type' => 'mobile'])
            ->postJson('/api/resolve-code', ['code' => $code])
            ->assertOk();
        $firstAt = $user->fresh()->registration_device_at;

        $this->travel(5)->minutes();
        $this->withHeaders(self::DEVICE)
            ->postJson('/api/resolve-code', ['code' => $code])
            ->assertOk();

        $user->refresh();
        $this->assertSame('ios', $user->registration_platform);
        $this->assertSame('mobile', $user->registration_device_type);
        $this->assertTrue($user->registration_device_at->equalTo($firstAt));
    }

    public function test_an_established_accounts_login_is_not_taken_for_its_registration(): void
    {
        $code = StudentCodes::generate();
        $user = User::factory()->create([
            'role' => 'student',
            'login_code_hash' => StudentCodes::hash($code),
        ]);

        $this->withHeaders(self::DEVICE)
            ->postJson('/api/resolve-code', ['code' => $code])
            ->assertOk();

        $this->assertNull($user->fresh()->registration_device_at);
    }

    public function test_an_older_app_build_records_nothing(): void
    {
        $this->postJson('/api/guest/register', ['device_id' => 'device-2', 'name' => 'Host'])
            ->assertSuccessful();

        $user = User::where('device_id', 'device-2')->firstOrFail();
        $this->assertNull($user->registration_platform);
        $this->assertNull($user->registration_device_type);
        $this->assertNull($user->registration_device_at);
    }

    public function test_an_unexpected_value_does_not_fail_the_sign_in(): void
    {
        $this->withHeaders(['X-Device-Platform' => 'fuchsia', 'X-Device-Type' => str_repeat('x', 40)])
            ->postJson('/api/guest/register', ['device_id' => 'device-3', 'name' => 'Host'])
            ->assertSuccessful();

        $user = User::where('device_id', 'device-3')->firstOrFail();
        $this->assertSame('fuchsia', $user->registration_platform);
        $this->assertNull($user->registration_device_type);
    }

    public function test_admin_student_detail_shows_the_registration_device(): void
    {
        $student = User::factory()->notOnboarded()->create(['role' => 'student']);
        $student->forceFill([
            'registration_platform' => 'web',
            'registration_device_type' => 'mobile',
            'registration_device_at' => '2026-11-05 12:37:00',
        ])->save();
        $silent = User::factory()->create(['role' => 'student']);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->getJson("/api/admin/users/{$student->id}")
            ->assertOk()
            ->assertJsonPath('data.registration_device.platform', 'web')
            ->assertJsonPath('data.registration_device.type', 'mobile');
        $this->assertNotNull(
            $this->getJson("/api/admin/users/{$student->id}")->json('data.registration_device.at'),
        );

        $this->getJson("/api/admin/users/{$silent->id}")
            ->assertOk()
            ->assertJsonPath('data.registration_device', null);
    }
}
