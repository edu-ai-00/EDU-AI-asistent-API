<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Classroom extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    public function students(): HasMany
    {
        return $this->hasMany(User::class, 'classroom_id');
    }

    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'classroom_teacher', 'classroom_id', 'teacher_id')
            ->withTimestamps();
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'classroom_courses')
            ->withTimestamps();
    }

    public function hasTeacher(int $userId): bool
    {
        return $this->teachers()->where('users.id', $userId)->exists();
    }
}
