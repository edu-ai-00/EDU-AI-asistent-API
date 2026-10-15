<?php

namespace App\Support;

/**
 * Makes a User-Agent header safe to store in a 255-character audit column.
 * Shared by the consent trail and the AUM audit log (BR-BNNASY).
 */
class UserAgent
{
    /**
     * Truncate by character, not byte, so a multi-byte sequence is never
     * split, and reject anything that is not valid UTF-8 outright rather than
     * risk a strict-mode DB error. This path runs on every failed consent
     * confirmation attempt too — it must never throw, or a crafted User-Agent
     * lets an attacker turn a failed guess into a 500 that also suppresses
     * the audit entry for it.
     *
     * NUL (U+0000) is valid UTF-8 and MySQL will store it, but Postgres
     * rejects it outright, so it is stripped explicitly rather than relied on
     * to be caught by the encoding check. Defence in depth: most front-ends
     * already reject a NUL byte in a header before this ever runs.
     */
    public static function sanitize(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '' || ! mb_check_encoding($userAgent, 'UTF-8')) {
            return null;
        }

        $userAgent = str_replace("\0", '', $userAgent);

        if ($userAgent === '') {
            return null;
        }

        return mb_substr($userAgent, 0, 255, 'UTF-8');
    }
}
