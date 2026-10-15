<?php

namespace App\Support;

/**
 * Login/consent code primitives.
 *
 * Codes are Crockford Base32 (digits + letters, minus I, L, O and U) so they
 * survive being read aloud over the phone by a parent. Minor login codes are
 * never stored in plaintext — only the keyed hash produced here, which is
 * deterministic and therefore still indexable for a direct lookup.
 */
class StudentCodes
{
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(int $length = 6): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    /**
     * Canonicalise what the user typed: uppercase, trimmed, and Crockford's
     * confusables folded onto the character that is actually in the alphabet.
     */
    public static function normalize(string $code): string
    {
        return strtr(strtoupper(trim($code)), [
            'I' => '1',
            'L' => '1',
            'O' => '0',
        ]);
    }

    /** Keyed, deterministic hash — safe at rest, still a single-row lookup. */
    public static function hash(string $code): string
    {
        return hash_hmac('sha256', self::normalize($code), (string) config('app.key'));
    }

    /** Opaque URL token for magic links. Stored hashed, same as codes. */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
