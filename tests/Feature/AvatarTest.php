<?php

namespace Tests\Feature;

use App\Models\AvatarItem;
use App\Models\User;
use App\Models\UserAvatar;
use App\Models\UserAvatarItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(User $user): self
    {
        $token = $user->createToken('test')->plainTextToken;

        // The guard caches the user it resolved on the previous request in
        // this test, so without this a second identity in one test silently
        // keeps acting as the first — which is exactly what the isolation
        // test needs to be able to observe.
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$token}"]);
    }

    private function item(string $code, string $slot, int $layer, array $overrides = []): AvatarItem
    {
        return AvatarItem::create(array_merge([
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
        ], $overrides));
    }

    private function seedCatalog(): void
    {
        $this->item('torso_base', 'torso', 20, ['is_default' => true]);
        $this->item('eyes_base', 'eyes', 30, ['is_default' => true]);
        $this->item('head_crown', 'head', 40);
        $this->item('head_cap', 'head', 40);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Catalog
    // ─────────────────────────────────────────────────────────────────────────

    public function test_catalog_is_readable_without_a_token(): void
    {
        $this->seedCatalog();

        $response = $this->getJson('/api/avatar/catalog');

        $response->assertOk();
        $this->assertCount(4, $response->json('data'));
    }

    public function test_inactive_items_are_hidden_from_the_catalog(): void
    {
        $this->seedCatalog();
        $this->item('head_retired', 'head', 40, ['is_active' => false]);

        $codes = collect($this->getJson('/api/avatar/catalog')->json('data'))
            ->pluck('code');

        $this->assertNotContains('head_retired', $codes);
    }

    public function test_default_items_are_owned_without_a_purchase_row(): void
    {
        $this->seedCatalog();
        $user = User::factory()->create();

        $data = collect($this->actingAsUser($user)
            ->getJson('/api/avatar/catalog')
            ->json('data'))
            ->keyBy('code');

        $this->assertTrue($data['torso_base']['owned']);
        $this->assertFalse($data['head_crown']['owned']);
        $this->assertDatabaseCount('user_avatar_items', 0);
    }

    public function test_catalog_is_ordered_back_to_front(): void
    {
        $this->seedCatalog();

        $layers = collect($this->getJson('/api/avatar/catalog')->json('data'))
            ->pluck('layer')
            ->all();

        $sorted = $layers;
        sort($sorted);

        $this->assertSame($sorted, $layers);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Reading the user's avatar
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_user_who_never_customised_gets_the_default_outfit(): void
    {
        $this->seedCatalog();
        $user = User::factory()->create();

        $data = $this->actingAsUser($user)->getJson('/api/user/avatar')->json('data');

        $this->assertSame('torso_base', $data['equipped']['torso']);
        $this->assertSame('eyes_base', $data['equipped']['eyes']);
        $this->assertArrayNotHasKey('head', $data['equipped']);
    }

    public function test_owned_list_includes_the_defaults(): void
    {
        $this->seedCatalog();
        $user = User::factory()->create();

        $owned = $this->actingAsUser($user)->getJson('/api/user/avatar')->json('data.owned');

        $this->assertContains('torso_base', $owned);
        $this->assertContains('eyes_base', $owned);
        $this->assertNotContains('head_crown', $owned);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Purchase
    // ─────────────────────────────────────────────────────────────────────────

    public function test_purchasing_grants_the_item(): void
    {
        $this->seedCatalog();
        $user = User::factory()->create();

        $response = $this->actingAsUser($user)
            ->postJson('/api/avatar/items/head_crown/purchase');

        $response->assertOk();
        $this->assertContains('head_crown', $response->json('data.owned'));
        $this->assertDatabaseHas('user_avatar_items', [
            'user_id' => $user->id,
            'price_paid' => 0,
        ]);
    }

    public function test_purchasing_twice_does_not_duplicate_the_grant(): void
    {
        $this->seedCatalog();
        $user = User::factory()->create();

        $this->actingAsUser($user)->postJson('/api/avatar/items/head_crown/purchase');
        $this->actingAsUser($user)->postJson('/api/avatar/items/head_crown/purchase')
            ->assertOk();

        $this->assertDatabaseCount('user_avatar_items', 1);
    }

    public function test_purchasing_an_unknown_item_is_a_404(): void
    {
        $this->seedCatalog();
        $user = User::factory()->create();

        $this->actingAsUser($user)
            ->postJson('/api/avatar/items/nope/purchase')
            ->assertNotFound();
    }

    public function test_purchasing_requires_authentication(): void
    {
        $this->seedCatalog();

        $this->postJson('/api/avatar/items/head_crown/purchase')
            ->assertUnauthorized();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Equipping
    // ─────────────────────────────────────────────────────────────────────────

    public function test_equipping_an_owned_item_persists(): void
    {
        $this->seedCatalog();
        $user = User::factory()->create();

        $this->actingAsUser($user)->postJson('/api/avatar/items/head_crown/purchase');

        $response = $this->actingAsUser($user)->putJson('/api/user/avatar', [
            'equipped' => ['torso' => 'torso_base', 'head' => 'head_crown'],
        ]);

        $response->assertOk();
        $this->assertSame('head_crown', $response->json('data.equipped.head'));
        $this->assertDatabaseHas('user_avatars', ['user_id' => $user->id]);
    }

    public function test_equipping_an_unowned_item_is_forbidden(): void
    {
        $this->seedCatalog();
        $user = User::factory()->create();

        $this->actingAsUser($user)
            ->putJson('/api/user/avatar', ['equipped' => ['head' => 'head_crown']])
            ->assertForbidden();

        $this->assertDatabaseCount('user_avatars', 0);
    }

    public function test_an_item_cannot_be_worn_in_the_wrong_slot(): void
    {
        $this->seedCatalog();
        $user = User::factory()->create();

        $this->actingAsUser($user)->postJson('/api/avatar/items/head_crown/purchase');

        // Right item, wrong slot — accepting this would silently break the
        // layer stacking on every client.
        $this->actingAsUser($user)
            ->putJson('/api/user/avatar', ['equipped' => ['torso' => 'head_crown']])
            ->assertStatus(422);
    }

    public function test_an_unknown_slot_is_rejected(): void
    {
        $this->seedCatalog();
        $user = User::factory()->create();

        $this->actingAsUser($user)
            ->putJson('/api/user/avatar', ['equipped' => ['tail' => 'head_crown']])
            ->assertStatus(422);
    }

    public function test_an_unknown_item_is_rejected(): void
    {
        $this->seedCatalog();
        $user = User::factory()->create();

        $this->actingAsUser($user)
            ->putJson('/api/user/avatar', ['equipped' => ['head' => 'nope']])
            ->assertStatus(422);
    }

    public function test_a_null_slot_clears_it(): void
    {
        $this->seedCatalog();
        $user = User::factory()->create();

        $response = $this->actingAsUser($user)->putJson('/api/user/avatar', [
            'equipped' => ['torso' => 'torso_base', 'head' => null],
        ]);

        $response->assertOk();
        $this->assertNull($response->json('data.equipped.head'));
    }

    public function test_a_slot_holds_only_one_item(): void
    {
        $this->seedCatalog();
        $user = User::factory()->create();

        $this->actingAsUser($user)->postJson('/api/avatar/items/head_crown/purchase');
        $this->actingAsUser($user)->postJson('/api/avatar/items/head_cap/purchase');

        $this->actingAsUser($user)->putJson('/api/user/avatar', [
            'equipped' => ['head' => 'head_crown'],
        ]);
        $response = $this->actingAsUser($user)->putJson('/api/user/avatar', [
            'equipped' => ['head' => 'head_cap'],
        ]);

        $response->assertOk();
        $this->assertSame(['head' => 'head_cap'], $response->json('data.equipped'));
        $this->assertDatabaseCount('user_avatars', 1);
    }

    public function test_one_avatar_row_per_user(): void
    {
        $this->seedCatalog();
        $user = User::factory()->create();

        foreach (['torso_base', 'eyes_base'] as $code) {
            $this->actingAsUser($user)->putJson('/api/user/avatar', [
                'equipped' => [AvatarItem::where('code', $code)->first()->slot => $code],
            ])->assertOk();
        }

        $this->assertSame(1, UserAvatar::where('user_id', $user->id)->count());
    }

    public function test_one_users_items_are_not_visible_to_another(): void
    {
        $this->seedCatalog();
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAsUser($owner)->postJson('/api/avatar/items/head_crown/purchase');

        $owned = $this->actingAsUser($stranger)
            ->getJson('/api/user/avatar')
            ->json('data.owned');

        $this->assertNotContains('head_crown', $owned);
        $this->assertSame(1, UserAvatarItem::count());
    }
}
