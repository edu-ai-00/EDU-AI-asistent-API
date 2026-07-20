<?php

namespace App\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Verifies Microsoft (Entra ID / Azure AD) v2.0 id_tokens locally.
 *
 * Multi-tenant differs from Google/Apple: the issuer is per-tenant
 * (`https://login.microsoftonline.com/{tid}/v2.0`), so it's validated against
 * the token's own `tid` claim. When a specific tenant GUID is configured
 * (single-tenant), the token's `tid` must match it; `common`/`organizations`/
 * `consumers` accept any tenant.
 */
class MicrosoftIdTokenVerifier
{
    private const ISSUER_TEMPLATE = 'https://login.microsoftonline.com/%s/v2.0';

    private const MULTI_TENANT = ['common', 'organizations', 'consumers'];

    private const JWKS_CACHE_TTL = 3600; // 1 hour

    /**
     * @param  list<string>  $allowedAudiences  configured Microsoft client IDs
     * @param  string  $tenant  configured tenant (GUID or common/organizations/consumers)
     */
    public function __construct(
        private array $allowedAudiences,
        private string $tenant = 'common',
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidIdTokenException on any signature/claim failure
     */
    public function verify(string $idToken): array
    {
        if (empty($this->allowedAudiences)) {
            throw new InvalidIdTokenException('Microsoft client IDs are not configured.');
        }

        try {
            $keys = JWK::parseKeySet($this->fetchJwks());
            JWT::$leeway = 60;
            $claims = (array) JWT::decode($idToken, $keys);
        } catch (\Throwable $e) {
            throw new InvalidIdTokenException('Token signature verification failed: '.$e->getMessage(), previous: $e);
        }

        $tid = (string) ($claims['tid'] ?? '');
        if ($tid === '') {
            throw new InvalidIdTokenException('Token is missing the tenant (tid) claim.');
        }

        // Issuer must be the tenant-specific v2.0 issuer for this token's tid.
        if (($claims['iss'] ?? null) !== sprintf(self::ISSUER_TEMPLATE, $tid)) {
            throw new InvalidIdTokenException('Unexpected token issuer.');
        }

        // Single-tenant config: lock to the configured tenant.
        if (! in_array($this->tenant, self::MULTI_TENANT, true) && $this->tenant !== $tid) {
            throw new InvalidIdTokenException('Token is from an unexpected tenant.');
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
        $url = sprintf(
            'https://login.microsoftonline.com/%s/discovery/v2.0/keys',
            $this->tenant,
        );

        return Cache::remember('microsoft_oidc_jwks_'.$this->tenant, self::JWKS_CACHE_TTL, function () use ($url) {
            $response = Http::retry(2, 200)->get($url);

            if (! $response->successful()) {
                throw new InvalidIdTokenException('Unable to fetch Microsoft signing keys.');
            }

            return $response->json();
        });
    }
}
