<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An organisational unit grouping classrooms and the teachers who work there
 * (BR-8QAWKP).
 *
 * A school is a *visibility* boundary, never an access one: belonging to the
 * same school lets a teacher see that a colleague's class exists and who
 * teaches it, and stops there. Nothing about students, courses or results
 * follows from school membership — those stay keyed to the classroom_teacher
 * pivot via User::teachesStudent() and TeacherCourseAccess.
 */
class School extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'address',
        'identifier',
    ];

    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'school_teacher', 'school_id', 'teacher_id')
            ->withTimestamps();
    }

    public function classrooms(): HasMany
    {
        return $this->hasMany(Classroom::class);
    }
}
