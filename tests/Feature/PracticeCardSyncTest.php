<?php

namespace Tests\Feature;

use App\Models\PracticeCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Bulk practice-card upsert (POST /api/user/practice-cards).
 *
 * The endpoint is the only way a device's FSRS scheduling reaches the account,
 * so it has to be safe for the multi-device case: two devices holding the same
 * card, only one of which has the newest review (BR-ZBJ99Z).
 */
class PracticeCardSyncTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'course_id' => 'math101',
            'lesson_id' => 'lesson-1',
            'block_id' => 'block-a',
            'source_type' => 'bookmark',
            'state' => 0,
            'due_date' => now()->toIso8601String(),
            'reps' => 0,
            'lapses' => 0,
        ], $overrides);
    }

    public function test_it_creates_a_card(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/user/practice-cards', [
            'cards' => [$this->payload()],
        ]);

        $response->assertOk();
        $this->assertSame('created', $response->json('data.0.status'));
        $this->assertDatabaseHas('practice_cards', [
            'user_id' => $user->id,
            'block_id' => 'block-a',
        ]);
    }

    public function test_a_newer_review_overwrites_the_stored_schedule(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        PracticeCard::create($this->payload([
            'user_id' => $user->id,
            'state' => 1,
            'reps' => 1,
            'last_review' => now()->subHours(2),
            'due_date' => now()->subHour(),
        ]));
        Sanctum::actingAs($user);

        $this->postJson('/api/user/practice-cards', [
            'cards' => [$this->payload([
                'state' => 2,
                'reps' => 2,
                'last_review' => now()->toIso8601String(),
                'due_date' => now()->addDays(10)->toIso8601String(),
            ])],
        ])->assertOk();

        $card = PracticeCard::where('user_id', $user->id)->first();
        $this->assertSame(2, $card->state);
        $this->assertSame(2, $card->reps);
        $this->assertTrue($card->due_date->isFuture());
    }

    public function test_a_stale_device_cannot_undo_a_newer_review(): void
    {
        // Device A reviewed the card and pushed. Device B still holds the
        // pre-review copy (state 0, due now) and syncs afterwards — without a
        // guard its blind upsert resets the card and the review is lost on
        // BOTH devices on the next pull.
        $user = User::factory()->create(['role' => 'student']);
        PracticeCard::create($this->payload([
            'user_id' => $user->id,
            'state' => 2,
            'reps' => 1,
            'last_review' => now()->subMinutes(5),
            'due_date' => now()->addDays(14),
        ]));
        Sanctum::actingAs($user);

        $this->postJson('/api/user/practice-cards', [
            'cards' => [$this->payload([
                'state' => 0,
                'reps' => 0,
                'due_date' => now()->toIso8601String(),
            ])],
        ])->assertOk();

        $card = PracticeCard::where('user_id', $user->id)->first();
        $this->assertSame(2, $card->state, 'stale push must not reset FSRS state');
        $this->assertSame(1, $card->reps);
        $this->assertTrue($card->due_date->isFuture(), 'stale push must not re-due the card');
    }

    public function test_a_stale_push_still_updates_non_scheduling_fields(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        PracticeCard::create($this->payload([
            'user_id' => $user->id,
            'state' => 2,
            'reps' => 1,
            'last_review' => now()->subMinutes(5),
            'due_date' => now()->addDays(14),
            'is_active' => true,
        ]));
        Sanctum::actingAs($user);

        $this->postJson('/api/user/practice-cards', [
            'cards' => [$this->payload([
                'lesson_id' => 'lesson-9',
                'is_active' => false,
            ])],
        ])->assertOk();

        $card = PracticeCard::where('user_id', $user->id)->first();
        $this->assertSame('lesson-9', $card->lesson_id);
        $this->assertFalse((bool) $card->is_active);
        $this->assertSame(2, $card->state);
    }

    public function test_it_accepts_a_card_without_a_lesson_id(): void
    {
        // Auto-bookmarking a wrong answer in the quiz has no lesson context, so
        // the card is created with an empty lesson_id. The client used to drop
        // those from the push entirely, which left them stuck local-only and
        // invisible on every other device (BR-ZBJ99Z).
        $user = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/user/practice-cards', [
            'cards' => [$this->payload(['lesson_id' => ''])],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('practice_cards', [
            'user_id' => $user->id,
            'block_id' => 'block-a',
            'lesson_id' => '',
        ]);
    }
}
