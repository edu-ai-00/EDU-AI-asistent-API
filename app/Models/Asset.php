<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    protected $fillable = [
        'course_id',
        'filename',
        'path',
        'url',
        'size',
        'mime_type',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function course()
    {
        return $this->belongsTo(Course::class, 'course_id', 'course_id');
    }
}
