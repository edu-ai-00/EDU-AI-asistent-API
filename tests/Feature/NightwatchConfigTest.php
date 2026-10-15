<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Nightwatch\Core;
use Tests\TestCase;

/**
 * What leaves the app for Nightwatch. Request headers are recorded on every
 * request, and the user attached to a record defaults to name + email, so
 * both are pinned here rather than trusted to a Railway variable.
 */
class NightwatchConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_admin_key_header_is_redacted(): void
    {
        $headers = array_map('strtolower', config('nightwatch.redact_headers'));

        foreach (['authorization', 'cookie', 'x-admin-key'] as $header) {
            $this->assertContains($header, $headers);
        }
    }

    public function test_credentials_in_request_bodies_are_redacted(): void
    {
        $fields = config('nightwatch.redact_payload_fields');

        foreach (['password', 'code', 'id_token', 'identity_token'] as $field) {
            $this->assertContains($field, $fields);
        }
    }

    public function test_a_student_is_sent_without_name_or_email(): void
    {
        $student = User::factory()->create(['role' => 'student', 'is_minor' => true]);

        $details = $this->resolve($student);

        $this->assertSame('', $details['username']);
        $this->assertSame('student (minor)', $details['name']);
        $this->assertStringNotContainsString($student->email, json_encode($details));
        $this->assertStringNotContainsString($student->name, json_encode($details));
    }

    public function test_a_guest_is_sent_as_a_guest(): void
    {
        $guest = User::factory()->create(['role' => 'student', 'is_guest' => true]);

        $this->assertSame(['name' => 'guest', 'username' => ''], $this->resolve($guest));
    }

    public function test_a_teacher_keeps_name_and_email(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->assertSame(
            ['name' => $teacher->name, 'username' => $teacher->email],
            $this->resolve($teacher),
        );
    }

    /** @return array{name: string, username: string} */
    private function resolve(User $user): array
    {
        $resolver = app(Core::class)->userDetailsResolver;

        $this->assertNotNull($resolver, 'AppServiceProvider must register Nightwatch::user()');

        return $resolver($user);
    }
}
