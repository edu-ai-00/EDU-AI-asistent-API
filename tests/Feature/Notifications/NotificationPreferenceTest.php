<?php

namespace Tests\Feature\Notifications;

use App\Models\NotificationPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET/PUT /api/notification-preferences (NOTIF-3, NOTIF-6, spec §5.1).
 */
class NotificationPreferenceTest extends TestCase
{
    use NotificationTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();
    }

    public function test_defaults_without_a_row(): void
    {
        $student = $this->student();
        [$headers] = $this->bearer($student);

        $this->withHeaders($headers)->getJson('/api/notification-preferences')
            ->assertOk()
            ->assertExactJson(['data' => [
                'push_enabled' => true,
                'quiet_hours_start' => '20:00',
                'quiet_hours_end' => '07:00',
                'reminder_time' => null,
                'timezone' => 'Europe/Prague',
                'defaults' => ['quiet_hours_start' => '20:00', 'quiet_hours_end' => '07:00', 'timezone' => 'Europe/Prague'],
                'mandatory_types' => [
                    ['key' => 'maintenance_notice', 'name' => 'Plánovaná údržba nebo výpadek'],
                    ['key' => 'app_update_notice', 'name' => 'Důležitá aktualizace aplikace nebo podmínek'],
                ],
            ]]);

        $this->assertSame(0, NotificationPreference::count(), 'GET never creates the row');
    }

    public function test_put_saves_any_subset_and_null_resets_to_default(): void
    {
        $student = $this->student();
        [$headers] = $this->bearer($student);

        $this->withHeaders($headers)->putJson('/api/notification-preferences', [
            'push_enabled' => false,
            'quiet_hours_start' => '21:30',
            'quiet_hours_end' => '06:15',
            'reminder_time' => '17:00',
            'timezone' => 'Europe/Bratislava',
        ])->assertOk()
            ->assertJsonPath('data.push_enabled', false)
            ->assertJsonPath('data.quiet_hours_start', '21:30')
            ->assertJsonPath('data.quiet_hours_end', '06:15')
            ->assertJsonPath('data.reminder_time', '17:00')
            ->assertJsonPath('data.timezone', 'Europe/Bratislava');

        $this->withHeaders($headers)->putJson('/api/notification-preferences', ['push_enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.push_enabled', true)
            ->assertJsonPath('data.reminder_time', '17:00');

        $this->withHeaders($headers)->putJson('/api/notification-preferences', [
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
            'reminder_time' => null,
            'timezone' => null,
        ])->assertOk()
            ->assertJsonPath('data.quiet_hours_start', '20:00')
            ->assertJsonPath('data.reminder_time', null)
            ->assertJsonPath('data.timezone', 'Europe/Prague');

        $row = NotificationPreference::find($student->id);
        $this->assertNull($row->quiet_hours_start);
        $this->assertNull($row->timezone);
    }

    public function test_invalid_values_are_rejected(): void
    {
        $student = $this->student();
        [$headers] = $this->bearer($student);

        foreach ([
            ['quiet_hours_start' => '7:00', 'quiet_hours_end' => '08:00'],
            ['quiet_hours_start' => '24:00', 'quiet_hours_end' => '08:00'],
            ['reminder_time' => '17:60'],
            ['timezone' => 'Mars/Olympus'],
            ['timezone' => '+02:00'],
            ['push_enabled' => 'maybe'],
            // Both ends of the window together, or neither.
            ['quiet_hours_start' => '21:00'],
            ['quiet_hours_start' => '21:00', 'quiet_hours_end' => null],
        ] as $payload) {
            $this->withHeaders($headers)->putJson('/api/notification-preferences', $payload)
                ->assertStatus(422);
        }

        $this->assertSame(0, NotificationPreference::count());
    }

    public function test_mandatory_types_follow_the_users_role_and_the_type_switch(): void
    {
        $this->type('app_update_notice')->update(['is_active' => false]);
        $teacher = $this->teacher();
        [$headers] = $this->bearer($teacher);

        $this->withHeaders($headers)->getJson('/api/notification-preferences')
            ->assertOk()
            ->assertJsonPath('data.mandatory_types', [['key' => 'maintenance_notice', 'name' => 'Plánovaná údržba nebo výpadek']]);
    }
}
