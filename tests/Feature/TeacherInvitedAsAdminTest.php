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
 * BR-98XQ58: adding an admin whose e-mail already belonged to a teacher was
 * refused with "This user is already a team member." The reporter expected
 * the account to be promoted, the way a student's already is.
 *
 * The teacher keeps the account — its id, name, classes and schools — and
 * only the role moves up. An admin has every right a teacher has, so nothing
 * is lost, and the schools keep them on the Učitelé tab (BR-A7KEZ6). Someone
 * who already signs in gets no invite (re-opening one would start a 7-day
 * clock that locks an active account out); a teacher who never accepted
 * theirs gets a fresh one, now as an admin.
 */
class TeacherInvitedAsAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->actor = User::factory()->create(['role' => 'admin']);
    }

    private function inviteAsAdmin(string $email)
    {
        return $this
            ->withHeader('Authorization', 'Bearer ' . $this->actor->createToken('a')->plainTextToken)
            ->postJson('/api/admin/team', [
                'name' => 'Jiné Jméno',
                'email' => $email,
                'role' => 'admin',
            ]);
    }

    public function test_an_active_teacher_invited_as_admin_is_promoted_in_place(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'name' => 'Anna', 'email' => 'akce@skola.cz']);

        $this->inviteAsAdmin('Akce@Skola.cz')
            ->assertStatus(200)
            ->assertJsonPath('data.id', $teacher->id)
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.status', 'active');

        $teacher->refresh();
        $this->assertSame('admin', $teacher->role);
        $this->assertSame('Anna', $teacher->name);
        $this->assertNull($teacher->invite_expires_at);
        $this->assertSame(1, User::where('email', 'akce@skola.cz')->count());
    }

    public function test_the_teachers_schools_are_kept(): void
    {
        $school = School::create(['name' => 'ZŠ Kavčí hory']);
        $teacher = User::factory()->create(['role' => 'teacher', 'email' => 'a@skola.cz']);
        $teacher->schools()->attach($school->id);

        $this->inviteAsAdmin('a@skola.cz')
            ->assertStatus(200)
            ->assertJsonPath('data.schools.0.id', $school->id);

        $this->assertSame([$school->id], $teacher->schools()->pluck('schools.id')->all());
    }

    public function test_an_active_teacher_gets_no_invite_and_the_role_change_is_audited(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'email' => 'a@skola.cz']);

        $this->inviteAsAdmin('a@skola.cz')->assertStatus(200);

        Mail::assertNotSent(TeamInviteMail::class);

        $log = AuditLog::where('action', 'user.role_changed')->firstOrFail();
        $this->assertSame($teacher->id, (int) $log->target_id);
        $this->assertSame(['from' => 'teacher', 'to' => 'admin'], $log->diff['role']);
        $this->assertFalse(AuditLog::where('action', 'user.invited')->exists());
    }

    public function test_a_teacher_with_a_pending_invite_gets_a_fresh_one_as_admin(): void
    {
        $this->freezeSecond();
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'email' => 'a@skola.cz',
            'invited_at' => now()->subDays(3),
            'invite_expires_at' => now()->addDays(4),
        ]);

        $this->inviteAsAdmin('a@skola.cz')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'invited');

        $this->assertTrue($teacher->fresh()->invite_expires_at->equalTo(now()->addDays(7)));
        Mail::assertSent(TeamInviteMail::class, fn (TeamInviteMail $m) => $m->hasTo('a@skola.cz') && $m->role === 'admin');
        $this->assertTrue(AuditLog::where('action', 'user.invited')->firstOrFail()->meta['existing_account']);
    }

    public function test_a_teacher_whose_invite_expired_gets_it_reopened(): void
    {
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'email' => 'a@skola.cz',
            'invited_at' => now()->subDays(10),
            'invite_expires_at' => now()->subDays(3),
        ]);

        $this->inviteAsAdmin('a@skola.cz')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'invited');

        $this->assertSame('admin', $teacher->fresh()->role);
        Mail::assertSent(TeamInviteMail::class);
    }

    public function test_an_existing_teacher_invited_as_teacher_is_still_refused(): void
    {
        User::factory()->create(['role' => 'teacher', 'email' => 'a@skola.cz']);

        $this
            ->withHeader('Authorization', 'Bearer ' . $this->actor->createToken('a')->plainTextToken)
            ->postJson('/api/admin/team', [
                'name' => 'A',
                'email' => 'a@skola.cz',
                'role' => 'teacher',
            ])
            ->assertStatus(422);
    }
}
