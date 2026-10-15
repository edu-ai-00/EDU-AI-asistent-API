<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A language a course may be published in (BR-AYACEK).
 *
 * Course uploads validate `language` against this table, so every language
 * reference in the system resolves to one shared catalogue.
 */
class Language extends Model
{
    protected $fillable = [
        'code',
        'name',
        'native_name',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Languages offered to editors and students, in display order.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('code');
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'language', 'code');
    }
}
