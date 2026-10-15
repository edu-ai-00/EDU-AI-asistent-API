<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Guardian;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MinorAccountSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_be_flagged_as_minor_and_linked_to_guardian(): void
    {
        $guardian = Guardian::create(['email' => 'rodic@example.com', 'locale' => 'cs']);

        $child = User::create([
            'name' => 'Stydlivý delfín',
            'is_minor' => true,
            'guardian_id' => $guardian->id,
            'login_code_hash' => str_repeat('a', 64),
        ]);

        $this->assertTrue($child->fresh()->is_minor);
        $this->assertSame($guardian->id, $child->guardian->id);
        // The stronger guarantee — "a minor never gets a plaintext login_code" —
        // is exercised in Task 9, where the write path that issues codes exists.
        // Here we only confirm this task's column round-trips correctly.
        $this->assertSame(str_repeat('a', 64), $child->fresh()->login_code_hash);
    }

    public function test_classroom_defaults_to_school_type_and_supports_self_service(): void
    {
        $guardian = Guardian::create(['email' => 'rodic2@example.com', 'locale' => 'cs']);

        $school = Classroom::create(['name' => '5.B']);
        $self = Classroom::create([
            'name' => 'rodic2@example.com',
            'type' => 'self_service',
            'guardian_id' => $guardian->id,
        ]);

        $this->assertSame('school', $school->fresh()->type);
        $this->assertSame('self_service', $self->fresh()->type);
        $this->assertSame($guardian->id, $self->guardian->id);
    }

    public function test_guardian_deletion_is_restricted_while_children_exist(): void
    {
        $guardian = Guardian::create(['email' => 'rodic3@example.com', 'locale' => 'cs']);

        User::create([
            'name' => 'Ostýchavý medvěd',
            'is_minor' => true,
            'guardian_id' => $guardian->id,
            'login_code_hash' => str_repeat('b', 64),
        ]);

        $this->expectException(QueryException::class);
        $guardian->delete();
    }

    public function test_childless_guardian_can_be_deleted(): void
    {
        $guardian = Guardian::create(['email' => 'rodic4@example.com', 'locale' => 'cs']);

        $guardian->delete();

        $this->assertNull(Guardian::find($guardian->id));
    }
}
