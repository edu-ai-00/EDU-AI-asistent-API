<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Calibrated state of one task (block), global across schools, classes and
 * language variants (BR-697NCA).
 *
 * `elo_vector` is the calibrated difficulty D per GPF dimension — NULL where
 * no answer has calibrated that dimension yet, so the course's own elo_vector
 * still applies there. `item_pocet` is the update count m per dimension that
 * drives the task K-factor. Only TaskCalibrationService writes either.
 */
class BlockStat extends Model
{
    protected $fillable = [
        'block_id',
        'item_pocet',
        'elo_vector',
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
            'elo_vector' => 'array',
        ];
    }
}
