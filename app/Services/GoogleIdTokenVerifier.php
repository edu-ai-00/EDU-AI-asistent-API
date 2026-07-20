<?php

namespace App\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Verifies Google-issued OpenID Connect id_tokens locally.
 *
 * Signature is checked against Google's published JWKS (cached), then the
 * standard OIDC claims (iss, aud, exp) are validated. `aud` must match one of
 * the OAuth client IDs we configured — Web, iOS and Android each mint tokens
 * with their own audience, so all allowed client IDs live in config.
 */
class GoogleIdTokenVerifier
{
    /** Google's JWKS endpoint (RS256 signing keys). */
    private const CERTS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    /** Accepted issuers per the OIDC discovery document. */
    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    private const JWKS_CACHE_KEY = 'google_oidc_jwks';
    private const JWKS_CACHE_TTL = 3600; // 1 hour

    /**
     * @param  list<string>  $allowedAudiences  configured Google client IDs
     */
    public function __construct(private array $allowedAudiences)
    {
    }

    /**
     * Verify an id_token and return its claims.
     *
     * @return array<string, mixed> decoded claims (sub, email, name, ...)
     *
     * @throws InvalidIdTokenException on any signature/claim failure
     */
    public function verify(string $idToken): array
    {
        if (empty($this->allowedAudiences)) {
            throw new InvalidIdTokenException('Google client IDs are not configured.');
        }

        try {
            $keys = JWK::parseKeySet($this->fetchJwks());
            JWT::$leeway = 60; // tolerate minor clock skew
            $claims = (array) JWT::decode($idToken, $keys);
        } catch (\Throwable $e) {
            throw new InvalidIdTokenException('Token signature verification failed: '.$e->getMessage(), previous: $e);
        }

        $iss = $claims['iss'] ?? null;
        if (! in_array($iss, self::ISSUERS, true)) {
            throw new InvalidIdTokenException('Unexpected token issuer.');
        }

        $aud = $claims['aud'] ?? null;
        if (! in_array($aud, $this->allowedAudiences, true)) {
            throw new InvalidIdTokenException('Token audience does not match a known client.');
        }

        return $claims;
    }

    /**
     * Fetch Google's JWKS, cached for an hour to avoid a network round-trip
     * per login. Google rotates keys but publishes new ones ahead of use.
     *
     * @return array<string, mixed>
     */
    private function fetchJwks(): array
    {
        return Cache::remember(self::JWKS_CACHE_KEY, self::JWKS_CACHE_TTL, function () {
            $response = Http::retry(2, 200)->get(self::CERTS_URL);

            if (! $response->successful()) {
                throw new InvalidIdTokenException('Unable to fetch Google signing keys.');
            }

            return $response->json();
        });
    }
}
