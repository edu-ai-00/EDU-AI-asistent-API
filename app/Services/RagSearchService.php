<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Mentor;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Client for the RAG search service (POST {base_url}/ask).
 *
 * Chat sessions whose mentor has engine=rag are answered by this service
 * instead of the LLM in ChatAiService. The RAG service returns a complete
 * answer with sources and follow-up suggestions; we render those into one
 * markdown message and keep the raw fields in chat_messages.metadata.
 */
class RagSearchService
{
    /** users.role → RAG user_role. The service knows STUDENT|TEACHER|PARENT. */
    private const ROLE_MAP = [
        'student' => 'STUDENT',
        'teacher' => 'TEACHER',
        'admin' => 'TEACHER',
    ];

    public function __construct(
        private string $baseUrl,
        private ?string $apiKey,
        private int $timeout,
        private string $legalEntity,
        private ?string $cfAccessClientId = null,
        private ?string $cfAccessClientSecret = null,
    ) {
        // Env values get copy-pasted; strip stray whitespace and trailing
        // punctuation so "…/v1," doesn't silently break every request.
        $this->baseUrl = rtrim(trim($this->baseUrl), " \t\n\r,;/");
        $this->apiKey = $this->apiKey !== null ? trim($this->apiKey) : null;
    }

    private function hasCfAccessToken(): bool
    {
        return (bool) ($this->cfAccessClientId && $this->cfAccessClientSecret);
    }

    /**
     * Auth headers for every call: the service's own key (bare, no "Bearer")
     * and/or the Cloudflare Access service token that gets us through the
     * provider's Zero Trust edge.
     */
    private function authHeaders(): array
    {
        $headers = [];
        if ($this->apiKey) {
            $headers['Authorization'] = $this->apiKey;
        }
        if ($this->hasCfAccessToken()) {
            $headers['CF-Access-Client-Id'] = $this->cfAccessClientId;
            $headers['CF-Access-Client-Secret'] = $this->cfAccessClientSecret;
        }

        return $headers;
    }

    /**
     * Whether a mentor slug is answered by the RAG service rather than the LLM.
     * Driven by mentors.engine, so switching a mentor over is an admin edit.
     */
    public static function isRagPersona(?string $persona): bool
    {
        return Mentor::findBySlug($persona)?->isRag() ?? false;
    }

    /**
     * Answer the latest user question in a chat session and persist the
     * result on the assistant placeholder message. Returns the message text.
     *
     * Which documents get searched comes from the session's mentor, so two RAG
     * mentors can sit on different legal entities of the same service. An
     * unconfigured mentor falls back to config('rag.legal_entity').
     */
    public function answer(User $user, ChatSession $session, ChatMessage $assistantMessage): string
    {
        $question = $session->messages()
            ->where('id', '<', $assistantMessage->id)
            ->where('role', 'user')
            ->orderByDesc('id')
            ->first();

        if (!$question || trim($question->content) === '') {
            throw new \RuntimeException('No user question to answer');
        }

        if (!$session->rag_conversation_id) {
            $session->update(['rag_conversation_id' => (string) Str::uuid()]);
        }

        $turnSeq = $session->messages()
            ->where('role', 'user')
            ->where('id', '<=', $question->id)
            ->count();

        $mentor = Mentor::findBySlug($session->persona);

        $payload = [
            'question' => mb_substr(trim($question->content), 0, 2000),
            'legal_entity' => $mentor?->legalEntity() ?? $this->legalEntity,
            'user_role' => self::ROLE_MAP[$user->role] ?? 'STUDENT',
            'conversation_id' => $session->rag_conversation_id,
            'turn_seq' => $turnSeq,
        ];

        // BR-DAB49K — the provider's document-domain filter (Cermatík:
        // ["SECONDARY_ADMISSIONS"]). Only ever sent when the mentor has one;
        // the schema is closed and an empty list is not "no filter".
        $domains = $mentor?->ragDomains() ?? [];
        if ($domains !== []) {
            $payload['domains'] = $domains;
        }

        $response = $this->ask($payload, 'eduai-msg-' . $assistantMessage->id);

        $text = $this->formatAnswer($response);

        $assistantMessage->update([
            'content' => $text,
            'metadata' => [
                'source' => 'rag',
                'outcome' => $response['outcome'] ?? null,
                'answer_source' => $response['answer_source'] ?? null,
                'match_score' => $response['match_score'] ?? null,
                'matched_entry_id' => $response['matched_entry_id'] ?? null,
                'sources' => $response['sources'] ?? [],
                'suggestions' => $response['suggestions'] ?? [],
            ],
        ]);

        return $text;
    }

    /**
     * POST /ask. The request schema is closed — only send documented fields.
     * The Idempotency-Key makes a retry after a timeout replay the stored
     * answer instead of computing it again.
     *
     * @throws RagSearchException classified — see RagSearchException for reasons
     */
    public function ask(array $payload, ?string $idempotencyKey = null): array
    {
        $headers = [];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $response = $this->request('post', '/ask', $payload, $headers);

        $data = $response->json();
        if (!is_array($data)) {
            throw new RagSearchException('bad_response', 'RAG search returned a non-JSON response', $response->status());
        }

        return $data;
    }

    /**
     * One classified HTTP call to the RAG service. Redirects are NOT followed:
     * a 301 (e.g. http → https at the provider's edge) would silently turn the
     * POST into a GET, so it is surfaced as its own failure instead.
     */
    private function request(string $method, string $path, array $payload = [], array $extraHeaders = []): \Illuminate\Http\Client\Response
    {
        if (!$this->baseUrl || (!$this->apiKey && !$this->hasCfAccessToken())) {
            throw new RagSearchException('not_configured', 'RAG service is not configured (need RAG_BASE_URL plus RAG_API_KEY and/or RAG_CF_ACCESS_CLIENT_ID + RAG_CF_ACCESS_CLIENT_SECRET)');
        }

        $url = rtrim($this->baseUrl, '/') . $path;

        try {
            $response = Http::withHeaders(array_merge(
                ['Content-Type' => 'application/json'],
                $this->authHeaders(),
                $extraHeaders,
            ))
                ->timeout($this->timeout)
                ->withOptions(['allow_redirects' => false])
                ->{$method}($url, $payload);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $reason = str_contains(strtolower($e->getMessage()), 'timed out') ? 'timeout' : 'unreachable';
            throw new RagSearchException($reason, 'RAG service ' . $reason . ': ' . $e->getMessage());
        }

        if ($response->successful()) {
            return $response;
        }

        $status = $response->status();
        $body = $response->body();

        if ($status >= 300 && $status < 400) {
            $reason = 'redirected';
            $detail = 'redirected to ' . ($response->header('Location') ?: '?') . ' — fix RAG_BASE_URL scheme/host';
        } elseif (($status === 403 || $status === 503) && (str_contains($body, '_cf_chl_opt') || str_contains($body, 'Just a moment'))) {
            $reason = 'cloudflare_challenge';
            $detail = 'blocked by a Cloudflare browser challenge — the RAG provider must exempt API traffic';
        } elseif ($status === 401 || $status === 403) {
            $reason = 'unauthorized';
            $detail = 'rejected the API key';
        } elseif ($status >= 500) {
            $reason = 'upstream_error';
            $detail = 'server error';
        } else {
            $reason = 'client_error';
            $detail = 'rejected the request';
        }

        // The service's own error envelope carries the most precise code.
        $ragCode = null;
        $json = json_decode($body, true);
        if (is_array($json) && isset($json['error']['code'])) {
            $ragCode = $json['error']['code'];
            $detail .= ' (' . $ragCode . ': ' . ($json['error']['message'] ?? '') . ')';
        }

        Log::error('RAG search error', [
            'url' => $url,
            'status' => $status,
            'reason' => $reason,
            'rag_code' => $ragCode,
            'body' => mb_substr($body, 0, 1000),
        ]);

        throw new RagSearchException($reason, "RAG search failed ({$status}): {$detail}", $status);
    }

    /**
     * The legal entities this RAG service exposes — what a mentor can be
     * pointed at. Feeds the picker in the admin's mentor editor.
     *
     * The service's shape has moved around, so accept either a bare list or
     * one wrapped in data/entities and normalise to [{code, name}].
     *
     * @return array<int, array{code: string, name: string}>
     *
     * @throws RagSearchException
     */
    public function entities(): array
    {
        $body = $this->request('get', '/entities')->json();

        $items = $body['entities'] ?? $body['data'] ?? $body;
        if (!is_array($items)) {
            throw new RagSearchException('bad_response', 'RAG /entities returned an unexpected shape');
        }

        $out = [];
        foreach ($items as $key => $item) {
            if (is_string($item)) {
                $code = $item;
                $name = is_string($key) ? $key : $item;
            } elseif (is_array($item)) {
                $code = (string) ($item['code'] ?? $item['id'] ?? $item['legal_entity'] ?? '');
                $name = (string) ($item['name'] ?? $item['title'] ?? $code);
            } else {
                continue;
            }

            if ($code !== '') {
                $out[] = ['code' => $code, 'name' => $name !== '' ? $name : $code];
            }
        }

        return $out;
    }

    /**
     * Step-by-step connectivity report for the admin diagnostics endpoint.
     * Never throws — each step records ok/reason so the caller can see exactly
     * where the chain breaks (our config vs. their edge vs. their service).
     */
    public function diagnose(bool $withAsk = false): array
    {
        $checks = [];

        $configOk = (bool) ($this->baseUrl && ($this->apiKey || $this->hasCfAccessToken()));
        $checks[] = [
            'name' => 'config',
            'ok' => $configOk,
            'detail' => $configOk
                ? 'auth configured: ' . implode(' + ', array_filter([
                    $this->apiKey ? 'RAG_API_KEY' : null,
                    $this->hasCfAccessToken() ? 'CF Access service token' : null,
                ]))
                : 'missing: ' . implode(', ', array_filter([
                    $this->baseUrl ? null : 'RAG_BASE_URL',
                    ($this->apiKey || $this->hasCfAccessToken()) ? null : 'RAG_API_KEY and/or RAG_CF_ACCESS_CLIENT_ID + RAG_CF_ACCESS_CLIENT_SECRET',
                ])),
        ];

        if ($configOk) {
            // /health lives above the version prefix: .../search/v1 -> .../search/health
            $healthBase = preg_replace('#/v\d+$#', '', rtrim($this->baseUrl, '/'));
            $checks[] = $this->probe('health', fn () => Http::withHeaders($this->authHeaders())
                ->timeout(min($this->timeout, 15))
                ->withOptions(['allow_redirects' => false])
                ->get($healthBase . '/health'));

            $checks[] = $this->probe('entities', function () {
                $response = $this->request('get', '/entities');
                $body = $response->body();
                if (!str_contains($body, $this->legalEntity)) {
                    throw new RagSearchException(
                        'client_error',
                        "configured RAG_LEGAL_ENTITY '{$this->legalEntity}' not found in the entities list",
                        $response->status(),
                    );
                }

                return $response;
            });

            if ($withAsk) {
                $checks[] = $this->probe('ask', function () {
                    $this->ask([
                        'question' => 'Diagnostický dotaz — funguje spojení?',
                        'legal_entity' => $this->legalEntity,
                        'user_role' => 'STUDENT',
                    ]);

                    return null;
                });
            }
        }

        return [
            'ok' => !in_array(false, array_column($checks, 'ok'), true),
            'base_url' => $this->baseUrl,
            'legal_entity' => $this->legalEntity,
            'api_key_set' => (bool) $this->apiKey,
            'cf_access_token_set' => $this->hasCfAccessToken(),
            // The provider whitelists our egress IP — report what it actually
            // is, since Railway's default egress pool is dynamic.
            'egress_ip' => $this->egressIp(),
            'checks' => $checks,
        ];
    }

    private function egressIp(): ?string
    {
        try {
            return Http::timeout(5)->get('https://api.ipify.org', ['format' => 'json'])->json('ip');
        } catch (\Throwable) {
            return null;
        }
    }

    private function probe(string $name, callable $call): array
    {
        $start = microtime(true);
        try {
            $response = $call();
            $status = $response?->status();
            if ($response !== null && !$response->successful()) {
                return [
                    'name' => $name,
                    'ok' => false,
                    'status' => $status,
                    'ms' => (int) ((microtime(true) - $start) * 1000),
                    'detail' => mb_substr($response->body(), 0, 200),
                ];
            }

            return [
                'name' => $name,
                'ok' => true,
                'status' => $status,
                'ms' => (int) ((microtime(true) - $start) * 1000),
            ];
        } catch (RagSearchException $e) {
            return [
                'name' => $name,
                'ok' => false,
                'status' => $e->status,
                'reason' => $e->reason,
                'ms' => (int) ((microtime(true) - $start) * 1000),
                'detail' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            return [
                'name' => $name,
                'ok' => false,
                'ms' => (int) ((microtime(true) - $start) * 1000),
                'detail' => $e->getMessage(),
            ];
        }
    }

    /**
     * Render a RAG response into one chat-friendly markdown message:
     * answer (or an outcome-specific fallback), follow-up suggestions,
     * and document sources.
     */
    public function formatAnswer(array $response): string
    {
        $answer = trim((string) ($response['answer'] ?? ''));
        $outcome = (string) ($response['outcome'] ?? 'answer');

        if ($answer === '') {
            $answer = match ($outcome) {
                'suggest' => 'Na tuhle otázku přímou odpověď nemám.',
                'no_answer' => 'Na tuhle otázku jsem v dostupných dokumentech odpověď nenašel. Zkus ji prosím položit jinak.',
                'unusable' => 'Téhle otázce úplně nerozumím. Zkus ji prosím napsat jinak.',
                'blocked' => 'Na tuhle otázku bohužel nemůžu odpovědět.',
                'self_harm' => 'Vypadá to, že ti není dobře. Pokud potřebuješ pomoc, zavolej Linku bezpečí: 116 111 (nonstop, zdarma) a promluv si s dospělým, kterému důvěřuješ.',
                default => 'Odpověď se teď nepodařilo získat. Zkus to prosím znovu.',
            };
        }

        $parts = [$answer];

        $suggestions = array_values(array_filter(
            (array) ($response['suggestions'] ?? []),
            fn ($s) => is_array($s) && trim((string) ($s['question'] ?? '')) !== '',
        ));
        if ($suggestions !== []) {
            $lines = array_map(fn ($s) => '- ' . trim((string) $s['question']), $suggestions);
            $parts[] = "**Mohlo by tě zajímat:**\n" . implode("\n", $lines);
        }

        $sources = array_values(array_filter(
            (array) ($response['sources'] ?? []),
            fn ($s) => is_array($s)
                && (trim((string) ($s['title'] ?? '')) !== '' || trim((string) ($s['url'] ?? '')) !== ''),
        ));
        if ($sources !== []) {
            $lines = array_map(function ($s) {
                $url = trim((string) ($s['url'] ?? ''));
                $title = $this->sourceLabel(trim((string) ($s['title'] ?? '')), $url);

                return $url !== '' ? "- [{$title}]({$url})" : "- {$title}";
            }, $sources);
            $parts[] = "**Zdroje:**\n" . implode("\n", $lines);
        }

        return implode("\n\n", $parts);
    }

    /**
     * A readable label for one source.
     *
     * The RAG service derives `title` from the document URL and cuts it to a
     * fixed length. When the cut lands inside a percent escape, what arrives
     * is a title ending in a broken character — "… K PŘIJ\u{FFFD}%"
     * (BR-K2DAVZ). The `url` beside it always arrives whole, so a damaged
     * title is thrown away and the label rebuilt from the URL instead.
     */
    private function sourceLabel(string $title, string $url): string
    {
        if ($title !== '' && !self::isDamagedTitle($title)) {
            return $title;
        }

        $fromUrl = self::labelFromUrl($url);
        if ($fromUrl !== '') {
            return $fromUrl;
        }

        // No usable URL either — keep whatever survived before the damage.
        $trimmed = self::trimDamagedTail($title);

        return $trimmed !== '' ? $trimmed : $url;
    }

    /**
     * Whether a title bears the marks of a mid-character truncation: the
     * replacement character itself, a dangling percent escape left over from
     * one, or bytes that are not valid UTF-8 at all.
     */
    private static function isDamagedTitle(string $title): bool
    {
        return str_contains($title, "\u{FFFD}")
            || preg_match('/%[0-9A-Fa-f]?$/', $title) === 1
            || !mb_check_encoding($title, 'UTF-8');
    }

    /** Everything before the first damaged character, without its ragged tail. */
    private static function trimDamagedTail(string $title): string
    {
        $clean = mb_convert_encoding($title, 'UTF-8', 'UTF-8');
        $cut = mb_strpos($clean, "\u{FFFD}");
        if ($cut !== false) {
            $clean = mb_substr($clean, 0, $cut);
        }
        $clean = preg_replace('/%[0-9A-Fa-f]?$/', '', $clean) ?? $clean;

        return trim($clean, " \t\n\r-–—,;.");
    }

    /**
     * "https://host/files/PR%C5%AEVODCE.pdf" → "PRŮVODCE.pdf", falling back to
     * the host when the path holds nothing worth showing.
     */
    private static function labelFromUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $segments = array_values(array_filter(explode('/', $path), fn ($p) => $p !== ''));
        $last = $segments === [] ? '' : (string) end($segments);

        if ($last !== '') {
            $decoded = rawurldecode($last);
            // A path encoded in something other than UTF-8 would decode into
            // the very garbage we are trying to avoid; show it raw instead.
            $label = mb_check_encoding($decoded, 'UTF-8') ? $decoded : $last;
            $label = trim(str_replace(['_', '+'], ' ', $label));
            if ($label !== '') {
                return $label;
            }
        }

        return (string) (parse_url($url, PHP_URL_HOST) ?? '');
    }
}
