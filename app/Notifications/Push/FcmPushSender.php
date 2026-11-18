<?php

namespace App\Notifications\Push;

use App\Models\PushDevice;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Firebase Cloud Messaging, HTTP v1 API (spec §6.3). FCM fronts APNs, so iOS
 * and macOS tokens go through the same call.
 *
 * Auth is the service-account flow without the Google SDK: a JWT signed with
 * the account's private key (RS256) is exchanged for an OAuth2 access token,
 * which is cached until shortly before it expires — one token exchange per
 * hour, not per message.
 */
class FcmPushSender implements PushSender
{
    public const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    /** FCM error codes that mean "this token will never work again". */
    private const INVALID_TOKEN_CODES = ['UNREGISTERED', 'SENDER_ID_MISMATCH', 'NOT_FOUND'];

    /** @param  array{client_email: string, private_key: string, private_key_id?: string, token_uri?: string}  $credentials */
    public function __construct(
        private readonly string $projectId,
        private readonly array $credentials,
    ) {}

    /**
     * Build from config('notifications.fcm'), or null when push is not
     * configured (missing or unreadable credentials).
     */
    public static function fromConfig(): ?self
    {
        $credentials = self::parseCredentials(config('notifications.fcm.credentials'));
        if ($credentials === null) {
            return null;
        }

        $projectId = (string) (config('notifications.fcm.project_id') ?: ($credentials['project_id'] ?? ''));
        if ($projectId === '') {
            return null;
        }

        return new self($projectId, $credentials);
    }

    /**
     * Service-account JSON, raw or base64-encoded. Null when absent or not
     * a usable service account.
     *
     * @return array<string, mixed>|null
     */
    public static function parseCredentials(mixed $raw): ?array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $raw = trim($raw);
        $json = str_starts_with($raw, '{') ? $raw : base64_decode($raw, true);

        if (!is_string($json)) {
            return null;
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
            return null;
        }

        return $decoded;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(PushDevice $device, PushMessage $message): PushResult
    {
        try {
            $accessToken = $this->accessToken();
        } catch (\Throwable $e) {
            return PushResult::retryable('oauth: ' . $e->getMessage());
        }

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->timeout(10)
                ->post($this->endpoint(), ['message' => $this->payload($device, $message)]);
        } catch (ConnectionException $e) {
            return PushResult::retryable('connection: ' . $e->getMessage());
        }

        return $this->interpret($response);
    }

    public function endpoint(): string
    {
        return 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($this->projectId) . '/messages:send';
    }

    /** @return array<string, mixed> */
    private function payload(PushDevice $device, PushMessage $message): array
    {
        return [
            'token' => $device->token,
            'notification' => [
                'title' => $message->title,
                'body' => $message->body,
            ],
            'data' => array_map('strval', $message->data),
            'android' => [
                'priority' => 'high',
                'notification' => ['channel_id' => 'eduai_default'],
            ],
            'apns' => [
                'payload' => ['aps' => ['sound' => 'default']],
            ],
        ];
    }

    private function interpret(Response $response): PushResult
    {
        if ($response->successful()) {
            return PushResult::success();
        }

        $status = $response->status();
        $error = (array) $response->json('error', []);
        $code = $this->fcmErrorCode($error);
        $summary = trim($status . ' ' . $code . ' ' . (string) ($error['message'] ?? ''));

        if ($status === 401) {
            // Our access token died early (revoked key, clock skew) — mint a
            // fresh one on the next attempt.
            Cache::forget($this->cacheKey());

            return PushResult::retryable($summary);
        }

        if ($status === 404 || in_array($code, self::INVALID_TOKEN_CODES, true)) {
            return PushResult::invalidToken($summary);
        }

        // INVALID_ARGUMENT means a dead token only when FCM blames the token;
        // a malformed message gets the same status, and disabling every
        // device over our own payload bug would silence push for everyone.
        if ($code === 'INVALID_ARGUMENT' && $this->blamesToken($error)) {
            return PushResult::invalidToken($summary);
        }

        if ($status === 429 || $status >= 500) {
            return PushResult::retryable($summary);
        }

        return PushResult::failed($summary);
    }

    /** Whether an INVALID_ARGUMENT error points at the registration token rather than the message. */
    private function blamesToken(array $error): bool
    {
        foreach ((array) ($error['details'] ?? []) as $detail) {
            foreach ((array) (is_array($detail) ? ($detail['fieldViolations'] ?? []) : []) as $violation) {
                if (is_array($violation) && ($violation['field'] ?? null) === 'message.token') {
                    return true;
                }
            }
        }

        return stripos((string) ($error['message'] ?? ''), 'registration token') !== false;
    }

    /** The FcmError detail code if present, else the generic status. */
    private function fcmErrorCode(array $error): string
    {
        foreach ((array) ($error['details'] ?? []) as $detail) {
            if (is_array($detail) && !empty($detail['errorCode'])) {
                return (string) $detail['errorCode'];
            }
        }

        return (string) ($error['status'] ?? '');
    }

    /**
     * OAuth2 access token for the service account, cached until a few
     * minutes before it expires.
     */
    public function accessToken(): string
    {
        $cached = Cache::get($this->cacheKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $tokenUri = (string) ($this->credentials['token_uri'] ?? self::DEFAULT_TOKEN_URI);
        $now = time();

        $assertion = JWT::encode([
            'iss' => $this->credentials['client_email'],
            'scope' => self::SCOPE,
            'aud' => $tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ], (string) $this->credentials['private_key'], 'RS256', $this->credentials['private_key_id'] ?? null);

        $response = Http::asForm()->timeout(10)->post($tokenUri, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]);

        $token = $response->json('access_token');
        if (!$response->successful() || !is_string($token) || $token === '') {
            Log::channel('notifications')->error('notifications.fcm_oauth_failed', [
                'status' => $response->status(),
                'error' => $response->json('error'),
            ]);

            throw new \RuntimeException('token exchange failed with HTTP ' . $response->status());
        }

        $ttl = max(60, (int) $response->json('expires_in', 3600) - 300);
        Cache::put($this->cacheKey(), $token, $ttl);

        return $token;
    }

    private function cacheKey(): string
    {
        return 'notifications:fcm:access_token:' . sha1((string) $this->credentials['client_email']);
    }
}
