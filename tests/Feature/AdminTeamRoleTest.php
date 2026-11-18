<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BR-YZJWEA: the role select could not retire anyone.
 *
 * Demoting a member to `student` was always what leaving the team meant —
 * `destroy()` has written exactly that row since the endpoint existed — but the
 * only way to reach it was the remove button, and the role select accepted
 * nothing but admin and teacher. An admin who stopped being an admin could
 * therefore only be turned into a teacher.
 */
class AdminTeamRoleTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        return User::factory()
            ->create(['role' => 'admin'])
            ->createToken('a')
            ->plainTextToken;
    }

    public function test_an_admin_can_be_demoted_to_student(): void
    {
        $member = User::factory()->create(['role' => 'admin']);

        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken())
            ->putJson('/api/admin/team/' . $member->id, ['role' => 'student'])
            ->assertStatus(200)
            ->assertJsonPath('data.role', 'student');

        $this->assertDatabaseHas('users', ['id' => $member->id, 'role' => 'student']);
    }

    public function test_a_teacher_can_be_demoted_to_student(): void
    {
        $member = User::factory()->create(['role' => 'teacher']);

        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken())
            ->putJson('/api/admin/team/' . $member->id, ['role' => 'student'])
            ->assertStatus(200);

        $this->assertDatabaseHas('users', ['id' => $member->id, 'role' => 'student']);
    }

    public function test_the_demoted_member_drops_off_the_roster(): void
    {
        $token = $this->adminToken();
        $member = User::factory()->create(['role' => 'admin']);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/admin/team/' . $member->id, ['role' => 'student'])
            ->assertStatus(200);

        $roster = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/team')
            ->assertStatus(200)
            ->json('data');

        $this->assertNotContains($member->id, array_column($roster, 'id'));
    }

    public function test_the_demoted_member_loses_the_admin_panel(): void
    {
        $member = User::factory()->create(['role' => 'admin']);
        $memberToken = $member->createToken('m')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken())
            ->putJson('/api/admin/team/' . $member->id, ['role' => 'student'])
            ->assertStatus(200);

        // The token stays valid — the account is still a user — but the role it
        // resolves to is read fresh on every request, so the panel is closed.
        app('auth')->forgetGuards();

        $this->withHeader('Authorization', 'Bearer ' . $memberToken)
            ->getJson('/api/admin/team')
            ->assertStatus(403);
    }

    public function test_demoting_yourself_is_still_refused(): void
    {
        $self = User::factory()->create(['role' => 'admin']);

        $this->withHeader('Authorization', 'Bearer ' . $self->createToken('s')->plainTextToken)
            ->putJson('/api/admin/team/' . $self->id, ['role' => 'student'])
            ->assertStatus(422)
            // BR-FJ8FR6: the panel prints this verbatim, so it has to be Czech.
            // Same wording as the user-detail dialog's AdminUserController.
            ->assertJsonPath('message', 'Nemůžete změnit svou vlastní roli.');

        $this->assertDatabaseHas('users', ['id' => $self->id, 'role' => 'admin']);
    }

    public function test_removing_yourself_from_the_team_is_refused_in_czech(): void
    {
        $self = User::factory()->create(['role' => 'admin']);

        $this->withHeader('Authorization', 'Bearer ' . $self->createToken('s')->plainTextToken)
            ->deleteJson('/api/admin/team/' . $self->id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nemůžete odebrat z týmu svůj vlastní účet.');

        $this->assertDatabaseHas('users', ['id' => $self->id, 'role' => 'admin']);
    }

    public function test_removing_a_member_answers_in_czech(): void
    {
        $member = User::factory()->create(['role' => 'teacher']);

        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken())
            ->deleteJson('/api/admin/team/' . $member->id)
            ->assertStatus(200)
            ->assertJsonPath('message', 'Člen týmu byl odebrán.');
    }

    /**
     * The admin panel has no translation layer — api/client.ts throws the API's
     * `message` and the dialogs print it — so an English literal reaching this
     * endpoint lands straight in a Czech UI (BR-FJ8FR6).
     */
    public function test_an_invite_into_the_same_role_is_refused_in_czech(): void
    {
        $member = User::factory()->create(['role' => 'teacher', 'email' => 'uz@skola.cz']);

        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken())
            ->postJson('/api/admin/team', [
                'name' => 'Uz Vtymu',
                'email' => 'uz@skola.cz',
                'role' => 'teacher',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tento uživatel už je členem týmu.');

        $this->assertDatabaseHas('users', ['id' => $member->id, 'role' => 'teacher']);
    }

    public function test_resending_a_live_invite_is_refused_in_czech(): void
    {
        $member = User::factory()->create(['role' => 'teacher']);

        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken())
            ->postJson('/api/admin/team/' . $member->id . '/resend', [])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Znovu poslat lze jen nepřijatou pozvánku nebo pozvánku, které vypršela platnost.'
            );
    }

    public function test_a_role_outside_the_three_is_rejected(): void
    {
        $member = User::factory()->create(['role' => 'teacher']);

        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken())
            ->putJson('/api/admin/team/' . $member->id, ['role' => 'superadmin'])
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $member->id, 'role' => 'teacher']);
    }

    public function test_a_demoted_member_can_be_invited_back(): void
    {
        $token = $this->adminToken();
        $member = User::factory()->create(['role' => 'admin', 'email' => 'zpet@skola.cz']);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/admin/team/' . $member->id, ['role' => 'student'])
            ->assertStatus(200);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/team', [
                'name' => 'Zpet Vtymu',
                'email' => 'zpet@skola.cz',
                'role' => 'teacher',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('users', ['id' => $member->id, 'role' => 'teacher']);
    }
}
