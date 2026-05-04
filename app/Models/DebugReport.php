<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DebugReport extends Model
{
    protected $fillable = [
        'user_id',
        'app_version',
        'platform',
        'os_version',
        'device_model',
        'report_data',
    ];

    protected $casts = [
        'report_data' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
