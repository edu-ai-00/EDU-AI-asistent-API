<?php

namespace App\Support;

/**
 * Pseudonymous display names for child accounts. A minor account must never
 * hold a real name, so the server picks one. Mirrors the admin-side generator
 * in eduai-admin/src/lib/classroom.ts so school and self-service classes look
 * the same to a teacher.
 */
class StudentNames
{
    private const ADJECTIVES = [
        'Stydlivý', 'Odvážný', 'Veselý', 'Moudrý', 'Rychlý',
        'Klidný', 'Chytrý', 'Legrační', 'Zvědavý', 'Šikovný',
        'Statečný', 'Hravý', 'Líný', 'Tichý', 'Zářivý',
        'Tajemný', 'Bystrý', 'Smělý', 'Snivý', 'Zlatý',
        'Kulatý', 'Divoký', 'Ztracený', 'Létající', 'Kosmický',
    ];

    private const ANIMALS = [
        'delfín', 'tučňák', 'medvěd', 'kolibřík', 'lev',
        'chameleon', 'ježek', 'panda', 'tygr', 'slon',
        'orel', 'kocour', 'zajíc', 'vlk', 'sob',
        'plameňák', 'mrož', 'vydra', 'jelen', 'koala',
        'papouček', 'klokan', 'surikata', 'žirafa', 'bobr',
    ];

    public static function generate(): string
    {
        $adjective = self::ADJECTIVES[random_int(0, count(self::ADJECTIVES) - 1)];
        $animal = self::ANIMALS[random_int(0, count(self::ANIMALS) - 1)];

        return "{$adjective} {$animal}";
    }
}
