<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A protected endpoint opened without a token by a client that asks for HTML
 * (a browser tab, a scanner) must get the same 401 as the app does — not a
 * 500 from Laravel trying to redirect to a `login` route the API never had
 * (BR-MTUTEX).
 */
class UnauthenticatedHtmlRequestTest extends TestCase
{
    public function test_a_browser_request_without_a_token_gets_a_json_401(): void
    {
        $this->get('/api/user?id=', ['Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_a_request_without_an_accept_header_gets_a_json_401(): void
    {
        $this->call('POST', '/api/logout')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_a_json_client_still_gets_a_json_401(): void
    {
        $this->getJson('/api/user')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }
}
