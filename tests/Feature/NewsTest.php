<?php

namespace Tests\Feature;

use App\Models\News;
use App\Models\NewsUserRead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(User $user): self
    {
        $token = $user->createToken('test')->plainTextToken;

        return $this->withHeaders(['Authorization' => "Bearer {$token}"]);
    }

    private function makeStudent(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['role' => 'student'], $overrides));
    }

    private function makeAdmin(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['role' => 'admin'], $overrides));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Drafts hidden from users, visible to admin
    // ─────────────────────────────────────────────────────────────────────────

    public function test_drafts_are_hidden_from_user_index_but_present_in_admin_index(): void
    {
        $published = News::factory()->create(['title' => 'Live story']);
        $draft = News::factory()->draft()->create(['title' => 'Draft story']);

        // Admin sees both published and draft.
        $admin = $this->makeAdmin();
        $adminResponse = $this->actingAsUser($admin)->getJson('/api/admin/news');

        $adminResponse->assertStatus(200);
        $adminIds = collect($adminResponse->json('data'))->pluck('id')->all();
        $this->assertContains($published->id, $adminIds);
        $this->assertContains($draft->id, $adminIds);

        // Regular user only sees the published item.
        $user = $this->makeStudent();
        $userResponse = $this->actingAsUser($user)->getJson('/api/news');

        $userResponse->assertStatus(200);
        $ids = collect($userResponse->json('data'))->pluck('id')->all();
        $this->assertContains($published->id, $ids);
        $this->assertNotContains($draft->id, $ids);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // is_read flips false -> true and unread_count decrements
    // ─────────────────────────────────────────────────────────────────────────

    public function test_is_read_flips_and_unread_count_decrements_after_read(): void
    {
        $newsA = News::factory()->create();
        $newsB = News::factory()->create();

        $user = $this->makeStudent();

        // Initially both unread.
        $before = $this->actingAsUser($user)->getJson('/api/news');
        $before->assertStatus(200);
        $before->assertJsonPath('meta.unread_count', 2);

        $itemA = collect($before->json('data'))->firstWhere('id', $newsA->id);
        $this->assertFalse($itemA['is_read']);

        // Mark newsA as read.
        $read = $this->actingAsUser($user)->postJson("/api/news/{$newsA->id}/read");
        $read->assertStatus(200);
        $read->assertJsonPath('data.unread_count', 1);

        // is_read now true for A in index.
        $after = $this->actingAsUser($user)->getJson('/api/news');
        $afterItemA = collect($after->json('data'))->firstWhere('id', $newsA->id);
        $this->assertTrue($afterItemA['is_read']);
        $after->assertJsonPath('meta.unread_count', 1);

        // show() also reflects read state.
        $show = $this->actingAsUser($user)->getJson("/api/news/{$newsA->id}");
        $show->assertStatus(200);
        $show->assertJsonPath('data.is_read', true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // markRead is idempotent (no duplicate row)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_mark_read_is_idempotent(): void
    {
        $news = News::factory()->create();
        $user = $this->makeStudent();

        $this->actingAsUser($user)->postJson("/api/news/{$news->id}/read")->assertStatus(200);
        $second = $this->actingAsUser($user)->postJson("/api/news/{$news->id}/read");
        $second->assertStatus(200);
        $second->assertJsonPath('data.unread_count', 0);

        $this->assertSame(
            1,
            NewsUserRead::where('news_id', $news->id)->where('user_id', $user->id)->count()
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Cannot read a draft (404)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_user_cannot_view_or_read_a_draft(): void
    {
        $draft = News::factory()->draft()->create();
        $user = $this->makeStudent();

        $this->actingAsUser($user)->getJson("/api/news/{$draft->id}")->assertStatus(404);
        $this->actingAsUser($user)->postJson("/api/news/{$draft->id}/read")->assertStatus(404);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Admin store / publish toggle
    // ─────────────────────────────────────────────────────────────────────────

    public function test_admin_can_create_published_news(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAsUser($admin)->postJson('/api/admin/news', [
            'title' => 'Hello',
            'perex' => 'Short summary',
            'body' => 'Full body text',
            'publish' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.is_published', true);
        $response->assertJsonPath('data.created_by', $admin->id);

        $newsId = $response->json('data.id');

        // Visible to a user since published.
        $user = $this->makeStudent();
        $userIndex = $this->actingAsUser($user)->getJson('/api/news');
        $ids = collect($userIndex->json('data'))->pluck('id')->all();
        $this->assertContains($newsId, $ids);
    }

    public function test_admin_publish_toggle_unpublishes_to_draft(): void
    {
        $admin = $this->makeAdmin();
        $news = News::factory()->create();

        $response = $this->actingAsUser($admin)->putJson("/api/admin/news/{$news->id}", [
            'publish' => false,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.is_published', false);
        $this->assertNull($news->fresh()->published_at);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Non-admin forbidden on admin endpoints
    // ─────────────────────────────────────────────────────────────────────────

    public function test_non_admin_cannot_create_news(): void
    {
        $user = $this->makeStudent();

        $response = $this->actingAsUser($user)->postJson('/api/admin/news', [
            'title' => 'Hello',
            'perex' => 'Short summary',
            'body' => 'Full body text',
        ]);

        $response->assertStatus(403);
    }
}
