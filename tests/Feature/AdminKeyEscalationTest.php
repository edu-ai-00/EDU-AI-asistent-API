<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BR-4U7XUW: a teacher saw classes from schools that are not theirs.
 *
 * X-Admin-Key stands in for an operator on server-to-server calls, but the
 * admin panel proxies it alongside the user's bearer token on every single
 * request (eduai-admin lib/server/auth.ts). Admin/teacher tokens expire after
 * 8 hours while the panel's session cookie lives for 7 days and nothing
 * refreshes it — so once the token died, the key answered for it and the
 * teacher's own screens came back rendered from an admin's data: every
 * school's classes in the list, `is_own` true on all of them.
 *
 * These tests pin the rule that closes it: the key may stand in for a caller,
 * never upgrade one. A request that carries a user token is that user's
 * request, and a token that no longer resolves is a dead session.
 */
class AdminKeyEscalationTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-admin-key';

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.api_key' => self::KEY]);
    }

    /** A teacher with one class of their own, and a foreign class next to it. */
    private function teacherWithForeignClassroom(): User
    {
        $school = School::create(['name' => 'ZŠ Komenského']);

        $teacher = User::factory()->create(['role' => 'teacher']);
        $school->teachers()->attach($teacher->id);

        $mine = Classroom::create(['name' => '6.A', 'school_id' => $school->id]);
        $mine->teachers()->attach($teacher->id);

        Classroom::create(['name' => 'Cizí třída']);

        return $teacher;
    }

    public function test_expired_token_is_not_upgraded_to_admin_by_the_key(): void
    {
        $teacher = $this->teacherWithForeignClassroom();
        $expired = $teacher->createToken('admin-token', ['admin'], now()->subMinute())
            ->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$expired}",
            'X-Admin-Key' => self::KEY,
        ])->getJson('/api/admin/classrooms');

        // A dead session, said out loud — that is what sends the panel back to
        // the login screen instead of quietly browsing as an admin.
        $response->assertStatus(401);
        $response->assertDontSee('Cizí třída');
    }

    public function test_teacher_token_keeps_teacher_scope_when_the_key_is_present(): void
    {
        $teacher = $this->teacherWithForeignClassroom();
        $token = $teacher->createToken('admin-token', ['admin'])->plainTextToken;

        $rows = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Admin-Key' => self::KEY,
        ])->getJson('/api/admin/classrooms')
            ->assertStatus(200)
            ->json('data');

        $this->assertSame(['6.A'], array_column($rows, 'name'));
    }

    public function test_teacher_stays_out_of_admin_only_routes_when_the_key_is_present(): void
    {
        $teacher = $this->teacherWithForeignClassroom();
        $token = $teacher->createToken('admin-token', ['admin'])->plainTextToken;

        // /admin/schools is admin-only; the key must not hand it to a teacher.
        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Admin-Key' => self::KEY,
        ])->getJson('/api/admin/schools')->assertStatus(403);
    }

    public function test_key_alone_still_grants_admin_access(): void
    {
        $this->teacherWithForeignClassroom();

        $rows = $this->withHeader('X-Admin-Key', self::KEY)
            ->getJson('/api/admin/classrooms')
            ->assertStatus(200)
            ->json('data');

        $this->assertEqualsCanonicalizing(
            ['6.A', 'Cizí třída'],
            array_column($rows, 'name'),
            'Server-to-server callers that carry no user token keep full access.'
        );
    }

    public function test_wrong_key_with_no_token_is_denied(): void
    {
        $this->teacherWithForeignClassroom();

        $this->withHeader('X-Admin-Key', 'nope')
            ->getJson('/api/admin/classrooms')
            ->assertStatus(403);
    }
}
