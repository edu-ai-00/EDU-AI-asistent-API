<?php

namespace Tests\Feature;

use App\Models\PracticeCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUserPracticeCardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_user_detail_includes_practice_cards(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $admin = User::factory()->create(['role' => 'admin']);

        PracticeCard::create([
            'user_id' => $student->id,
            'course_id' => 'math101',
            'lesson_id' => 'lesson-1',
            'block_id' => 'block-a',
            'source_type' => 'lesson',
            'state' => 0,
            'due_date' => now(),
            'is_active' => true,
        ]);
        PracticeCard::create([
            'user_id' => $student->id,
            'course_id' => 'math101',
            'lesson_id' => 'lesson-2',
            'block_id' => 'block-b',
            'source_type' => 'bookmark',
            'state' => 2,
            'due_date' => now()->addDays(3),
            'is_active' => false,
        ]);
        // Another user's card must not leak into the payload.
        PracticeCard::create([
            'user_id' => $admin->id,
            'course_id' => 'math101',
            'lesson_id' => 'lesson-1',
            'block_id' => 'block-a',
            'source_type' => 'lesson',
            'state' => 0,
            'due_date' => now(),
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/admin/users/{$student->id}");

        $response->assertOk();
        $cards = $response->json('data.practice_cards');
        $this->assertCount(2, $cards);

        $byBlock = collect($cards)->keyBy('block_id');
        $this->assertTrue($byBlock['block-a']['is_active']);
        $this->assertSame('lesson', $byBlock['block-a']['source_type']);
        $this->assertSame(0, $byBlock['block-a']['state']);
        $this->assertFalse($byBlock['block-b']['is_active']);
        $this->assertSame(2, $byBlock['block-b']['state']);
    }

    public function test_admin_user_detail_practice_cards_empty_for_user_without_cards(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $admin = User::factory()->create(['role' => 'admin']);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/admin/users/{$student->id}");

        $response->assertOk();
        $this->assertSame([], $response->json('data.practice_cards'));
    }
}
