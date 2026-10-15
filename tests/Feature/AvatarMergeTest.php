<?php

namespace Tests\Feature;

use App\Models\AvatarItem;
use App\Models\User;
use App\Models\UserAvatar;
use App\Models\UserAvatarItem;
use App\Services\UserMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Avatar cosmetics survive a guest → account merge (BR-7JRBCN).
 *
 * A guest who dressed their avatar before signing up must keep the items. The
 * unique(user_id, avatar_item_id) constraint also means an overlap between the
 * two accounts would abort the entire merge transaction if it were not deduped.
 */
class AvatarMergeTest extends TestCase
{
    use RefreshDatabase;

    private UserMergeService $service;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(UserMergeService::class);
        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin@example.test',
        ]);
    }

    private function item(string $code, string $slot, int $layer): AvatarItem
    {
        return AvatarItem::create([
            'code' => $code,
            'name' => $code,
            'slot' => $slot,
            'layer' => $layer,
            'r2_path' => "avatar/{$slot}/{$code}.svg",
            'url' => "https://r2.test/avatar/{$slot}/{$code}.svg",
            'rarity' => 'common',
            'price' => 0,
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function grant(User $user, AvatarItem $item): void
    {
        UserAvatarItem::create([
            'user_id' => $user->id,
            'avatar_item_id' => $item->id,
            'price_paid' => 0,
            'acquired_at' => now(),
        ]);
    }

    public function test_a_guests_items_move_to_the_kept_account(): void
    {
        $crown = $this->item('head_crown', 'head', 40);

        $guest = User::factory()->create(['is_guest' => true]);
        $target = User::factory()->create();

        $this->grant($guest, $crown);

        $this->service->merge($guest->id, $target->id, $this->admin->id);

        $this->assertDatabaseHas('user_avatar_items', [
            'user_id' => $target->id,
            'avatar_item_id' => $crown->id,
        ]);
        $this->assertSame(1, UserAvatarItem::count());
    }

    public function test_an_item_owned_by_both_sides_is_deduped_not_duplicated(): void
    {
        $crown = $this->item('head_crown', 'head', 40);

        $guest = User::factory()->create(['is_guest' => true]);
        $target = User::factory()->create();

        $this->grant($guest, $crown);
        $this->grant($target, $crown);

        $this->service->merge($guest->id, $target->id, $this->admin->id);

        $this->assertSame(
            1,
            UserAvatarItem::where('user_id', $target->id)->count(),
            'the unique constraint would abort the whole merge otherwise',
        );
    }

    public function test_the_guests_outfit_carries_over_when_the_target_has_none(): void
    {
        $crown = $this->item('head_crown', 'head', 40);

        $guest = User::factory()->create(['is_guest' => true]);
        $target = User::factory()->create();

        $this->grant($guest, $crown);
        UserAvatar::create([
            'user_id' => $guest->id,
            'equipped' => ['head' => 'head_crown'],
        ]);

        $this->service->merge($guest->id, $target->id, $this->admin->id);

        $avatar = UserAvatar::where('user_id', $target->id)->first();
        $this->assertNotNull($avatar);
        $this->assertSame('head_crown', $avatar->equipped['head']);
    }

    public function test_the_targets_own_outfit_wins(): void
    {
        $crown = $this->item('head_crown', 'head', 40);
        $cap = $this->item('head_cap', 'head', 40);

        $guest = User::factory()->create(['is_guest' => true]);
        $target = User::factory()->create();

        $this->grant($guest, $crown);
        $this->grant($target, $cap);

        UserAvatar::create([
            'user_id' => $guest->id,
            'equipped' => ['head' => 'head_crown'],
        ]);
        UserAvatar::create([
            'user_id' => $target->id,
            'equipped' => ['head' => 'head_cap'],
        ]);

        $this->service->merge($guest->id, $target->id, $this->admin->id);

        $avatars = UserAvatar::where('user_id', $target->id)->get();
        $this->assertCount(1, $avatars, 'user_avatars is one row per user');
        $this->assertSame('head_cap', $avatars->first()->equipped['head']);

        // The guest's item is still owned — the user just has to re-equip it.
        $this->assertDatabaseHas('user_avatar_items', [
            'user_id' => $target->id,
            'avatar_item_id' => $crown->id,
        ]);
    }
}
