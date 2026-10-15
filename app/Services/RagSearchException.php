<?php

namespace App\Services;

/**
 * Failure talking to the RAG search service, classified so callers (and the
 * admin diagnostics endpoint) can tell whose problem it is:
 *
 *  - not_configured        RAG_API_KEY / RAG_BASE_URL missing on our side
 *  - unreachable           DNS/connect failure — network or wrong URL
 *  - timeout               no response within RAG_TIMEOUT
 *  - redirected            got a 3xx — base URL scheme/host is wrong (e.g. http → https)
 *  - cloudflare_challenge  Cloudflare bot-protection interstitial — the RAG
 *                          provider must exempt server-to-server traffic
 *  - unauthorized          401/403 from the service — wrong or missing API key
 *  - client_error          other 4xx — our request is malformed
 *  - upstream_error        5xx — the RAG service itself is failing
 *  - bad_response          2xx but the body is not the expected JSON
 */
class RagSearchException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }
}
