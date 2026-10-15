<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AvatarItem extends Model
{
    /**
     * Avatar slots, in paint order.
     *
     * A slot holds at most one item, so equipping is a map slot => code and
     * "take the hat off" is the same operation as "wear another hat".
     */
    public const SLOT_TORSO = 'torso';

    public const SLOT_LEGS = 'legs';

    public const SLOT_EYES = 'eyes';

    public const SLOT_MOUTH = 'mouth';

    /** Glasses, nose, moustache, eye patch — face add-ons layered over eyes/mouth. */
    public const SLOT_FACE = 'face';

    public const SLOT_HEAD = 'head';

    public const SLOT_NECK = 'neck';

    public const SLOT_HANDS = 'hands';

    /** Book, lollipop, crayon, ice cream — one held object at a time. */
    public const SLOT_HELD = 'held';

    public const SLOTS = [
        self::SLOT_TORSO,
        self::SLOT_LEGS,
        self::SLOT_EYES,
        self::SLOT_MOUTH,
        self::SLOT_FACE,
        self::SLOT_HEAD,
        self::SLOT_NECK,
        self::SLOT_HANDS,
        self::SLOT_HELD,
    ];

    public const RARITIES = ['common', 'rare', 'epic'];

    protected $fillable = [
        'code',
        'name',
        'slot',
        'layer',
        'r2_path',
        'url',
        'rarity',
        'price',
        'is_default',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'layer' => 'integer',
            'price' => 'integer',
            'sort_order' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Scopes
    // ═══════════════════════════════════════════════════════════════════════════

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * The free starting outfit. Never sold, always equippable.
     */
    public function scopeDefaults($query)
    {
        return $query->where('is_default', true);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Relationships
    // ═══════════════════════════════════════════════════════════════════════════

    public function owners(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_avatar_items')
            ->withPivot(['price_paid', 'acquired_at'])
            ->withTimestamps();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Serialisation
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Catalog payload.
     *
     * [owned] is resolved by the caller against the user's ownership set —
     * default items count as owned without a `user_avatar_items` row, so the
     * app never has to special-case the starting outfit.
     *
     * @return array<string, mixed>
     */
    public function toClientArray(bool $owned): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'slot' => $this->slot,
            'layer' => $this->layer,
            'url' => $this->url,
            'rarity' => $this->rarity,
            'price' => $this->price,
            'is_default' => $this->is_default,
            'owned' => $owned,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Admin payload — adds the storage key, which clients have no use for.
     *
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'slot' => $this->slot,
            'layer' => $this->layer,
            'r2_path' => $this->r2_path,
            'url' => $this->url,
            'rarity' => $this->rarity,
            'price' => $this->price,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
