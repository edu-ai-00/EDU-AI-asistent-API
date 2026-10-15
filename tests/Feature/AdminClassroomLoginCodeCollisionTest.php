<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\StudentCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * I6 regression test. Validation ('distinct', 'unique:users,login_code')
 * only catches an exact plaintext duplicate, not two DIFFERENT plaintext
 * codes that hash the same after Crockford normalisation (I/L→1, O→0) —
 * e.g. ABC012 and ABCO12. Before this fix that case reached the database's
 * unique index on login_code_hash and threw a raw
 * UniqueConstraintViolationException: a 500 with no indication to the
 * teacher which of up to 50 submitted codes was the problem (BR-8FDF37 I6).
 */
class AdminClassroomLoginCodeCollisionTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@example.test']);

        return $admin->createToken('a')->plainTextToken;
    }

    public function test_store_rejects_a_batch_with_a_hash_colliding_pair_with_422_not_500(): void
    {
        $token = $this->adminToken();

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/admin/classrooms', [
                'name' => '5.B',
                'students' => [
                    ['name' => 'Student A', 'login_code' => 'ABC012'],
                    ['name' => 'Student B', 'login_code' => 'ABCO12'],
                ],
            ]);

        $response->assertStatus(422);
        $this->assertSame(0, User::where('name', 'Student A')->count(), 'nothing must be created on a rejected batch');
    }

    public function test_store_rejects_a_code_that_collides_with_an_existing_minors_hash(): void
    {
        $token = $this->adminToken();

        User::create([
            'name' => 'Stydlivý delfín',
            'is_minor' => true,
            'login_code_hash' => StudentCodes::hash('ZZ1234'),
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/admin/classrooms', [
                'name' => '5.B',
                'students' => [
                    ['name' => 'Student A', 'login_code' => 'ZZ1234'],
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_store_still_succeeds_for_non_colliding_codes(): void
    {
        $token = $this->adminToken();

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/admin/classrooms', [
                'name' => '5.B',
                'students' => [
                    ['name' => 'Student A', 'login_code' => 'AA1111'],
                    ['name' => 'Student B', 'login_code' => 'BB2222'],
                ],
            ]);

        $response->assertStatus(201);
        $this->assertSame(StudentCodes::hash('AA1111'), User::where('name', 'Student A')->first()->login_code_hash);
    }

    public function test_add_students_rejects_a_batch_with_a_hash_colliding_pair_with_422_not_500(): void
    {
        $token = $this->adminToken();

        $classroomId = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/admin/classrooms', [
                'name' => '5.B',
                'students' => [['name' => 'Existing', 'login_code' => 'QQ1111']],
            ])->json('data.id');

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/admin/classrooms/{$classroomId}/students", [
                'students' => [
                    ['name' => 'Student A', 'login_code' => 'ABC012'],
                    ['name' => 'Student B', 'login_code' => 'ABCO12'],
                ],
            ]);

        $response->assertStatus(422);
        $this->assertSame(0, User::where('name', 'Student A')->count());
    }
}
