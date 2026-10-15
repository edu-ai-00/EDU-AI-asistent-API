<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avatar_items', function (Blueprint $table) {
            $table->id();

            // Stable identifier shared with the app ("head_zlata_korunka").
            // The app keys its local cache on this, never on the numeric id.
            $table->string('code', 128)->unique();

            $table->string('name', 255);

            // torso|legs|eyes|mouth|face|head|neck|hands|held
            $table->string('slot', 32);

            // Paint order inside the composed avatar. Taken from the artwork
            // filename prefix (2_ body, 3_ face, 4_ head, 5_ neck/hands), so
            // stacking the layers ascending reproduces the designer's intent.
            $table->unsignedSmallInteger('layer');

            // R2 object key + its public URL. The app downloads the SVG once
            // and caches the source text locally, so this is only ever hit on
            // a cache miss.
            $table->string('r2_path', 512);
            $table->string('url', 1024);

            $table->string('rarity', 16)->default('common');

            // Pricing is not live yet — every item ships at 0 and the purchase
            // endpoint skips the balance check. The column exists so switching
            // to paid items later is a data change, not a migration.
            $table->unsignedInteger('price')->default(0);

            // Part of the free starting avatar: granted implicitly, never sold.
            $table->boolean('is_default')->default(false);

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['slot', 'is_active']);
            $table->index('is_default');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avatar_items');
    }
};
