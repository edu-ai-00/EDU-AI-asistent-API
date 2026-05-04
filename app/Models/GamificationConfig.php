<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GamificationConfig extends Model
{
    protected $fillable = [
        'version',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'config' => 'array',
        ];
    }
}
