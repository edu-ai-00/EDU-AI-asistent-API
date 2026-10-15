<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AvatarItem;
use App\Models\UserAvatar;
use App\Models\UserAvatarItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Avatar cosmetics: catalog, ownership and the worn outfit.
 *
 * The client mirrors all three into its local database and renders from there,
 * so every payload here is written to be cacheable as-is: item *codes* rather
 * than ids, and no field that only makes sense while online.
 */
class AvatarController extends Controller
{
    /**
     * The cosmetics catalog.
     *
     * GET /api/avatar/catalog
     *
     * Readable without a token (auth.optional) so the shop can be browsed by a
     * guest whose token has not been resolved yet; `owned` is then false for
     * everything except the default outfit.
     */
    public function catalog(Request $request): JsonResponse
    {
        $items = AvatarItem::query()
            ->active()
            ->orderBy('layer')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $ownedIds = $this->ownedItemIds($request);

        return response()->json([
            'data' => $items->map(
                // Defaults are owned by everyone without a row of their own.
                fn (AvatarItem $item) => $item->toClientArray(
                    $item->is_default || $ownedIds->contains($item->id)
                )
            )->values(),
            'meta' => [
                'total' => $items->count(),
            ],
        ]);
    }

    /**
     * The caller's outfit and inventory.
     *
     * GET /api/user/avatar
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->avatarPayload($request),
        ]);
    }

    /**
     * Acquire an item.
     *
     * POST /api/avatar/items/{code}/purchase
     *
     * Free for now: every item is priced at 0 and there is no balance to check.
     * When credits arrive this is the single place that grows a debit — the
     * recorded `price_paid` is already per-row so old grants stay at 0.
     */
    public function purchase(Request $request, string $code): JsonResponse
    {
        $item = AvatarItem::query()->active()->where('code', $code)->first();

        if ($item === null) {
            return response()->json(['message' => 'Avatar item not found'], 404);
        }

        $userId = $request->user()->id;

        // Idempotent: a double tap (or a retried offline request) must not
        // create a second grant, and must not read as an error either.
        if (! $item->is_default) {
            UserAvatarItem::firstOrCreate(
                ['user_id' => $userId, 'avatar_item_id' => $item->id],
                ['price_paid' => $item->price, 'acquired_at' => now()],
            );
        }

        return response()->json([
            'data' => $this->avatarPayload($request),
            'message' => 'Item acquired',
        ]);
    }

    /**
     * Replace the worn outfit.
     *
     * PUT /api/user/avatar
     *
     * The whole map is sent at once because that is what the device holds —
     * a per-slot endpoint would make an offline device replay N requests that
     * can interleave badly. Unknown slots are rejected; a null value clears
     * the slot.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'equipped' => ['required', 'array'],
            'equipped.*' => ['nullable', 'string', 'max:128'],
        ]);

        $equipped = $validated['equipped'];

        $unknownSlots = array_diff(array_keys($equipped), AvatarItem::SLOTS);
        if ($unknownSlots !== []) {
            return response()->json([
                'message' => 'Unknown avatar slot: ' . implode(', ', $unknownSlots),
            ], 422);
        }

        $codes = array_values(array_filter($equipped, fn ($code) => $code !== null));

        $items = AvatarItem::query()
            ->active()
            ->whereIn('code', $codes)
            ->get()
            ->keyBy('code');

        $missing = array_diff($codes, $items->keys()->all());
        if ($missing !== []) {
            return response()->json([
                'message' => 'Unknown avatar item: ' . implode(', ', $missing),
            ], 422);
        }

        $userId = $request->user()->id;
        $ownedIds = $this->ownedItemIds($request);

        foreach ($equipped as $slot => $code) {
            if ($code === null) {
                continue;
            }

            /** @var AvatarItem $item */
            $item = $items[$code];

            // Wearing a hat in the shoes slot would silently corrupt the
            // layering, so the slot has to match what the artwork was cut for.
            if ($item->slot !== $slot) {
                return response()->json([
                    'message' => "Item {$code} does not belong in slot {$slot}",
                ], 422);
            }

            if (! $item->is_default && ! $ownedIds->contains($item->id)) {
                return response()->json([
                    'message' => "Item {$code} is not owned",
                ], 403);
            }
        }

        UserAvatar::updateOrCreate(
            ['user_id' => $userId],
            ['equipped' => $equipped],
        );

        return response()->json([
            'data' => $this->avatarPayload($request),
            'message' => 'Avatar updated',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Ids of the items the caller bought. Empty for an unauthenticated caller.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function ownedItemIds(Request $request): \Illuminate\Support\Collection
    {
        $user = $request->user();

        if ($user === null) {
            return collect();
        }

        return UserAvatarItem::query()
            ->where('user_id', $user->id)
            ->pluck('avatar_item_id');
    }

    /**
     * Outfit + inventory, as the app caches it.
     *
     * @return array<string, mixed>
     */
    private function avatarPayload(Request $request): array
    {
        $userId = $request->user()->id;

        $avatar = UserAvatar::where('user_id', $userId)->first();

        $ownedCodes = AvatarItem::query()
            ->active()
            ->whereIn('id', $this->ownedItemIds($request))
            ->pluck('code');

        $defaultCodes = AvatarItem::query()->active()->defaults()->pluck('code');

        return [
            // A user who never opened the shop still needs something to draw.
            'equipped' => $avatar?->equipped ?? UserAvatar::defaultEquipped(),
            'owned' => $ownedCodes->merge($defaultCodes)->unique()->values(),
            'updated_at' => $avatar?->updated_at?->toIso8601String(),
        ];
    }
}
