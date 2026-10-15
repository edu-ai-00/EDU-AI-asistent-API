<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A parent/legal guardian. Deliberately not an Authenticatable: a guardian has
 * no password and no session — email is the only channel.
 */
class Guardian extends Model
{
    protected $fillable = [
        'email',
        'locale',
        'country',
    ];

    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }

    public function children(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function classrooms(): HasMany
    {
        return $this->hasMany(Classroom::class);
    }

    /** The consent that currently governs this guardian's children, if any. */
    public function activeConsent(): ?Consent
    {
        return $this->consents()->where('status', Consent::STATUS_CONFIRMED)->latest('id')->first();
    }
}
