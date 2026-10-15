<?php

namespace Tests\Feature;

use App\Mail\TeamInviteMail;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * BR-A7KEZ6: inviting an admin's e-mail as a teacher was refused with
 * "This user is already a team member."
 *
 * An account has one role, and an admin already has everything a teacher
 * has — classes of their own, any school's classes. So the invite neither
 * refuses nor demotes: the account stays an admin and is filed under the
 * schools picked in the form, which is what puts it on the Učitelé tab. The
 * reporter was inviting their own (only) admin account, so a demotion would
 * have locked them out of the panel.
 */
class AdminInvitedAsTeacherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function bearer(User $user): static
    {
        return $this->withHeader('Authorization', 'Bearer ' . $user->createToken('a')->plainTextToken);
    }

    private function inviteAsTeacher(User $actor, string $email, array $schoolIds = [])
    {
        return $this->bearer($actor)->postJson('/api/admin/team', [
            'name' => 'Jiné Jméno',
            'email' => $email,
            'role' => 'teacher',
            'school_ids' => $schoolIds,
        ]);
    }

    public function test_an_admin_invited_as_teacher_is_kept_and_filed_under_the_schools(): void
    {
        $school = School::create(['name' => 'ZŠ Kavčí hory']);
        $actor = User::factory()->create(['role' => 'admin']);
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Marek', 'email' => 'marek@skola.cz']);

        $this->inviteAsTeacher($actor, 'Marek@Skola.cz', [$school->id])
            ->assertStatus(200)
            ->assertJsonPath('data.id', $admin->id)
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.schools.0.id', $school->id);

        $admin->refresh();
        $this->assertSame('admin', $admin->role);
        $this->assertSame('Marek', $admin->name);
        $this->assertNull($admin->invite_expires_at);
        $this->assertSame([$school->id], $admin->schools()->pluck('schools.id')->all());
        $this->assertSame(1, User::where('email', 'marek@skola.cz')->count());
    }

    public function test_an_admin_can_add_their_own_account_as_a_teacher(): void
    {
        $school = School::create(['name' => 'ZŠ Kavčí hory']);
        $self = User::factory()->create(['role' => 'admin', 'email' => 'ja@skola.cz']);

        $this->inviteAsTeacher($self, 'ja@skola.cz', [$school->id])
            ->assertStatus(200)
            ->assertJsonPath('data.role', 'admin');

        $this->assertSame('admin', $self->fresh()->role);
        $this->assertSame([$school->id], $self->schools()->pluck('schools.id')->all());
    }

    public function test_schools_the_admin_already_had_are_kept(): void
    {
        $old = School::create(['name' => 'A']);
        $new = School::create(['name' => 'B']);
        $actor = User::factory()->create(['role' => 'admin']);
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'a@skola.cz']);
        $admin->schools()->attach($old->id);

        $this->inviteAsTeacher($actor, 'a@skola.cz', [$new->id])->assertStatus(200);

        $this->assertSame(
            [$old->id, $new->id],
            $admin->schools()->pluck('schools.id')->sort()->values()->all()
        );
    }

    public function test_no_invitation_is_sent_to_an_admin_who_already_has_access(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'admin', 'email' => 'a@skola.cz']);

        $this->inviteAsTeacher($actor, 'a@skola.cz')->assertStatus(200);

        Mail::assertNotSent(TeamInviteMail::class);
    }

    public function test_the_school_change_is_audited(): void
    {
        $school = School::create(['name' => 'A']);
        $actor = User::factory()->create(['role' => 'admin']);
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'a@skola.cz']);

        $this->inviteAsTeacher($actor, 'a@skola.cz', [$school->id])->assertStatus(200);

        $log = AuditLog::where('action', 'user.schools_changed')->firstOrFail();
        $this->assertSame($admin->id, (int) $log->target_id);
        $this->assertSame(['from' => [], 'to' => [$school->id]], $log->diff['school_ids']);
        $this->assertFalse(AuditLog::where('action', 'user.invited')->exists());
    }

    public function test_an_existing_admin_invited_as_admin_is_still_refused(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'admin', 'email' => 'a@skola.cz']);

        $this->bearer($actor)->postJson('/api/admin/team', [
            'name' => 'A',
            'email' => 'a@skola.cz',
            'role' => 'admin',
        ])->assertStatus(422);
    }
}
