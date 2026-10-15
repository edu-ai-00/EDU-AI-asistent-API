<?php

namespace Database\Seeders;

use App\Models\AvatarItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Publish the shipped avatar cosmetics: SVG to R2, metadata to `avatar_items`.
 *
 * Runs on every deploy (see `start.sh`) and is keyed on `code`, so metadata
 * edits in the manifest reconcile in place and no ownership row is ever
 * orphaned.
 *
 * Artwork is only uploaded for codes that have no row yet. A deploy must not
 * silently overwrite a file an admin replaced through the admin UI, and the
 * steady-state case — every item already published — then costs zero R2 calls.
 * Pass `--reupload` via [AvatarItemSeeder::$reupload] to force a re-push.
 */
class AvatarItemSeeder extends Seeder
{
    /** Force artwork back to the repo copy, discarding admin uploads. */
    public static bool $reupload = false;

    private const R2_PREFIX = 'avatar';

    public function run(): void
    {
        $manifest = require __DIR__ . '/avatar_manifest.php';
        $assetRoot = __DIR__ . '/avatar_assets';

        $published = 0;
        $uploaded = 0;

        foreach ($manifest as $entry) {
            $localPath = "{$assetRoot}/{$entry['slot']}/{$entry['code']}.svg";

            if (! is_file($localPath)) {
                $this->command?->warn("Missing artwork for {$entry['code']} — skipped");
                continue;
            }

            $r2Path = self::R2_PREFIX . "/{$entry['slot']}/{$entry['code']}.svg";
            $existing = AvatarItem::where('code', $entry['code'])->first();

            if ($existing === null || self::$reupload) {
                Storage::disk('s3')->put($r2Path, file_get_contents($localPath), [
                    'ContentType' => 'image/svg+xml',
                ]);
                $uploaded++;
            }

            AvatarItem::updateOrCreate(
                ['code' => $entry['code']],
                [
                    'name' => $entry['name'],
                    'slot' => $entry['slot'],
                    'layer' => $entry['layer'],
                    'r2_path' => $r2Path,
                    'url' => rtrim(config('filesystems.disks.s3.url'), '/') . '/' . $r2Path,
                    'rarity' => $entry['rarity'],
                    // Free period: everything ships at 0. Prices are a data
                    // change here once credits exist.
                    'price' => 0,
                    'is_default' => $entry['is_default'],
                    'is_active' => true,
                    'sort_order' => $entry['sort_order'],
                ],
            );

            $published++;
        }

        $this->command?->info("Avatar catalog: {$published} items, {$uploaded} uploaded to R2");
    }
}
