<?php

namespace App\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Verifies Apple-issued "Sign in with Apple" identity tokens locally.
 *
 * Same shape as [GoogleIdTokenVerifier] but against Apple's JWKS and issuer.
 * `aud` must match one of the configured client IDs — the native iOS bundle ID
 * and (for web) the Services ID.
 */
class AppleIdTokenVerifier
{
    private const CERTS_URL = 'https://appleid.apple.com/auth/keys';
    private const ISSUER = 'https://appleid.apple.com';

    private const JWKS_CACHE_KEY = 'apple_oidc_jwks';
    private const JWKS_CACHE_TTL = 3600; // 1 hour

    /**
     * @param  list<string>  $allowedAudiences  configured Apple client IDs
     */
    public function __construct(private array $allowedAudiences)
    {
    }

    /**
     * Verify an identity token and return its claims.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidIdTokenException on any signature/claim failure
     */
    public function verify(string $identityToken): array
    {
        if (empty($this->allowedAudiences)) {
            throw new InvalidIdTokenException('Apple client IDs are not configured.');
        }

        try {
            $keys = JWK::parseKeySet($this->fetchJwks());
            JWT::$leeway = 60;
            $claims = (array) JWT::decode($identityToken, $keys);
        } catch (\Throwable $e) {
            throw new InvalidIdTokenException('Token signature verification failed: '.$e->getMessage(), previous: $e);
        }

        if (($claims['iss'] ?? null) !== self::ISSUER) {
            throw new InvalidIdTokenException('Unexpected token issuer.');
        }

        if (! in_array($claims['aud'] ?? null, $this->allowedAudiences, true)) {
            throw new InvalidIdTokenException('Token audience does not match a known client.');
        }

        return $claims;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchJwks(): array
    {
        return Cache::remember(self::JWKS_CACHE_KEY, self::JWKS_CACHE_TTL, function () {
            $response = Http::retry(2, 200)->get(self::CERTS_URL);

            if (! $response->successful()) {
                throw new InvalidIdTokenException('Unable to fetch Apple signing keys.');
            }

            return $response->json();
        });
    }
}
