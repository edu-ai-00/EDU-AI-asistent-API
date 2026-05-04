<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BlockStat extends Model
{
    protected $fillable = [
        'block_id',
        'item_pocet',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'item_pocet' => 'array',
        ];
    }
}
