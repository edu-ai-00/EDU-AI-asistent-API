<?php

return [
    // Base URL including the version prefix. Public: https://rag2.edu-ai.eu/search/v1
    // (the provider's edge 301s plain http, which would break POSTs).
    // Internal (docker network): http://edu_ai_search:8090/v1
    'base_url' => env('RAG_BASE_URL', 'https://rag2.edu-ai.eu/search/v1'),
    'api_key' => env('RAG_API_KEY'),
    // Cloudflare Access service token — the provider's Zero Trust edge sits in
    // front of the service; these headers let server-to-server traffic through.
    'cf_access_client_id' => env('RAG_CF_ACCESS_CLIENT_ID'),
    'cf_access_client_secret' => env('RAG_CF_ACCESS_CLIENT_SECRET'),
    // Typical answers take 3-30 s; the service's nginx cuts off at 120 s.
    'timeout' => (int) env('RAG_TIMEOUT', 120),
    // Default documents to search, for mentors that have no rag_legal_entity
    // of their own. Codes: GET /search/v1/entities
    'legal_entity' => env('RAG_LEGAL_ENTITY', 'ZS_KAVCI_HORY_ZS'),
];
