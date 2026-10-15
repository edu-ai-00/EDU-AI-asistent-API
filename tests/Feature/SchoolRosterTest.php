<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BR-9Z59Z9: the school dialog lists the teachers filed under the school, and
 * "Odebrat" on one of them turns them back into an ordinary user — the
 * existing DELETE /admin/team/{id}.
 *
 * That endpoint used to change the role and nothing else, so the removed
 * teacher stayed in school_teacher: still listed as the school's teacher in
 * the very dialog they were just removed from, and back in their old schools
 * the day they were invited again. These pin that leaving the team also means
 * leaving the schools, and that the dialog can tell an admin filed under a
 * school (whom it must not demote) from a teacher.
 */
class SchoolRosterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function asAdmin(): static
    {
        return $this->withHeader('Authorization', 'Bearer ' . $this->admin->createToken('a')->plainTextToken);
    }

    public function test_removing_a_teacher_from_the_team_unfiles_them_from_every_school(): void
    {
        $a = School::create(['name' => 'ZŠ Kavčí hory']);
        $b = School::create(['name' => 'ZŠ Komenského']);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $teacher->schools()->attach([$a->id, $b->id]);

        $admin = $this->asAdmin();
        $admin->deleteJson('/api/admin/team/' . $teacher->id)->assertStatus(200);

        $this->assertDatabaseHas('users', ['id' => $teacher->id, 'role' => 'student']);
        $this->assertSame(0, $teacher->schools()->count());
        $this->assertSame([], $admin->getJson('/api/admin/schools/' . $a->id)->json('data.teachers'));
    }

    public function test_the_unfiling_is_audited(): void
    {
        $school = School::create(['name' => 'ZŠ Kavčí hory']);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $teacher->schools()->attach($school->id);

        $this->asAdmin()->deleteJson('/api/admin/team/' . $teacher->id)->assertStatus(200);

        $log = AuditLog::where('action', 'user.schools_changed')
            ->where('target_id', $teacher->id)
            ->sole();

        $this->assertSame(['from' => [$school->id], 'to' => []], $log->diff['school_ids']);
    }

    public function test_a_member_without_schools_writes_no_school_change(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->asAdmin()->deleteJson('/api/admin/team/' . $teacher->id)->assertStatus(200);

        $this->assertDatabaseHas('users', ['id' => $teacher->id, 'role' => 'student']);
        $this->assertFalse(AuditLog::where('action', 'user.schools_changed')->exists());
    }

    public function test_a_re_invited_teacher_does_not_get_their_old_schools_back(): void
    {
        $school = School::create(['name' => 'ZŠ Kavčí hory']);
        $teacher = User::factory()->create(['role' => 'teacher', 'email' => 'zpet@skola.cz']);
        $teacher->schools()->attach($school->id);

        $admin = $this->asAdmin();
        $admin->deleteJson('/api/admin/team/' . $teacher->id)->assertStatus(200);

        \Illuminate\Support\Facades\Mail::fake();
        $admin->postJson('/api/admin/team', [
            'name' => 'Zpět',
            'email' => 'zpet@skola.cz',
            'role' => 'teacher',
        ])->assertStatus(201);

        $this->assertSame(0, $teacher->fresh()->schools()->count());
    }

    public function test_removing_yourself_is_refused_and_keeps_your_schools(): void
    {
        $school = School::create(['name' => 'ZŠ Kavčí hory']);
        $this->admin->schools()->attach($school->id);

        $this->asAdmin()->deleteJson('/api/admin/team/' . $this->admin->id)->assertStatus(422);

        $this->assertSame(1, $this->admin->schools()->count());
        $this->assertDatabaseHas('users', ['id' => $this->admin->id, 'role' => 'admin']);
    }

    public function test_the_school_lists_each_teachers_role(): void
    {
        $school = School::create(['name' => 'ZŠ Kavčí hory']);
        $teacher = User::factory()->create(['role' => 'teacher', 'name' => 'Marek Učitel']);
        $colleague = User::factory()->create(['role' => 'admin', 'name' => 'Michal Admin']);
        $school->teachers()->attach([$teacher->id, $colleague->id]);

        $admin = $this->asAdmin();

        $roles = collect($admin->getJson('/api/admin/schools')->json('data.0.teachers'))
            ->pluck('role', 'id');
        $this->assertSame('teacher', $roles[$teacher->id]);
        $this->assertSame('admin', $roles[$colleague->id]);

        $detail = collect($admin->getJson('/api/admin/schools/' . $school->id)->json('data.teachers'))
            ->pluck('role', 'id');
        $this->assertSame('teacher', $detail[$teacher->id]);
    }
}
