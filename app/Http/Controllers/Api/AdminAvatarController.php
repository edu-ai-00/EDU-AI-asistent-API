<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AvatarItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Catalog management for avatar cosmetics.
 *
 * Artwork lives in R2 next to the course assets; this controller owns the
 * upload plus the metadata row that makes a file purchasable.
 */
class AdminAvatarController extends Controller
{
    /** SVG only — the client composes the avatar by stacking vector layers. */
    private const ALLOWED_MIMES = ['image/svg+xml', 'text/plain'];

    private const R2_PREFIX = 'avatar';

    /**
     * GET /api/admin/avatar-items
     */
    public function index(Request $request): JsonResponse
    {
        $items = AvatarItem::query()
            ->when(
                $request->query('slot'),
                fn ($q, $slot) => $q->where('slot', $slot)
            )
            ->orderBy('layer')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $items->map(fn (AvatarItem $item) => $item->toAdminArray())->values(),
            'meta' => [
                'total' => $items->count(),
                'slots' => AvatarItem::SLOTS,
                'rarities' => AvatarItem::RARITIES,
            ],
        ]);
    }

    /**
     * Upload artwork and create the catalog row in one step.
     *
     * POST /api/admin/avatar-items/upload
     *
     * Upload and metadata are deliberately not split: a row without artwork
     * would render as a hole in the avatar, and an object without a row is
     * invisible to everyone. Doing both here means neither can exist alone.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|mimetypes:' . implode(',', self::ALLOWED_MIMES) . '|max:5120',
            'code' => 'required|string|max:128|unique:avatar_items,code',
            'name' => 'required|string|max:255',
            'slot' => ['required', Rule::in(AvatarItem::SLOTS)],
            'layer' => 'required|integer|min:0|max:1000',
            'rarity' => ['sometimes', Rule::in(AvatarItem::RARITIES)],
            'price' => 'sometimes|integer|min:0',
            'is_default' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        $file = $request->file('file');
        $path = self::R2_PREFIX . '/' . $validated['slot'] . '/' . $validated['code'] . '.svg';

        Storage::disk('s3')->put($path, file_get_contents($file->getRealPath()), [
            'ContentType' => 'image/svg+xml',
        ]);

        $item = AvatarItem::create([
            'code' => $validated['code'],
            'name' => $validated['name'],
            'slot' => $validated['slot'],
            'layer' => $validated['layer'],
            'r2_path' => $path,
            'url' => $this->publicUrl($path),
            'rarity' => $validated['rarity'] ?? 'common',
            'price' => $validated['price'] ?? 0,
            'is_default' => $validated['is_default'] ?? false,
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return response()->json(['data' => $item->toAdminArray()], 201);
    }

    /**
     * Update metadata, optionally replacing the artwork.
     *
     * PUT /api/admin/avatar-items/{id}
     *
     * A replacement file keeps the same R2 key, so the URL the clients cached
     * stays valid; `updated_at` moving is what tells them to re-download.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $item = AvatarItem::findOrFail($id);

        $validated = $request->validate([
            'file' => 'sometimes|file|mimetypes:' . implode(',', self::ALLOWED_MIMES) . '|max:5120',
            'name' => 'sometimes|string|max:255',
            'slot' => ['sometimes', Rule::in(AvatarItem::SLOTS)],
            'layer' => 'sometimes|integer|min:0|max:1000',
            'rarity' => ['sometimes', Rule::in(AvatarItem::RARITIES)],
            'price' => 'sometimes|integer|min:0',
            'is_default' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        if ($request->hasFile('file')) {
            Storage::disk('s3')->put(
                $item->r2_path,
                file_get_contents($request->file('file')->getRealPath()),
                ['ContentType' => 'image/svg+xml'],
            );

            // The bytes changed under an unchanged key — force a new
            // updated_at even when no metadata field was touched, otherwise
            // clients keep serving the stale cached SVG.
            $item->touch();
        }

        unset($validated['file']);

        if ($validated !== []) {
            $item->update($validated);
        }

        return response()->json(['data' => $item->fresh()->toAdminArray()]);
    }

    /**
     * DELETE /api/admin/avatar-items/{id}
     *
     * Removes the row and the object. Ownership rows cascade, and any user
     * wearing the item falls back to the default for that slot on next load.
     */
    public function destroy(int $id): JsonResponse
    {
        $item = AvatarItem::findOrFail($id);

        Storage::disk('s3')->delete($item->r2_path);
        $item->delete();

        return response()->json([
            'data' => (object) [],
            'message' => 'Avatar item deleted',
        ]);
    }

    private function publicUrl(string $path): string
    {
        return rtrim(config('filesystems.disks.s3.url'), '/') . '/' . $path;
    }
}
