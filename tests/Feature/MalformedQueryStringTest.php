<?php

namespace Tests\Feature;

use ErrorException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;
use Throwable;

/**
 * Scanners probe the API with query strings past PHP's input limits
 * (max_input_vars, max_input_nesting_level). Symfony re-parses the raw query
 * with parse_str() whenever the full URL is built — HandleCors does that on
 * every request — and Laravel turns parse_str()'s warning into an
 * ErrorException, so each probe was a 500 and a Nightwatch issue (BR-RSWREP).
 */
class MalformedQueryStringTest extends TestCase
{
    /** @var list<Throwable> */
    private array $reported = [];

    private string|false $displayErrors;

    protected function setUp(): void
    {
        parent::setUp();

        // PHP only warns about nesting past max_input_nesting_level while
        // display_errors is off. Laravel turns it off in every environment
        // but `testing`, so production sees a warning this would otherwise hide.
        $this->displayErrors = ini_set('display_errors', '0');

        // Sentry (Integration::handles) hooks in the same way, so this sees
        // what it would see.
        /** @var Handler $handler */
        $handler = $this->app->make(ExceptionHandler::class);
        $handler->reportable(function (Throwable $e) {
            $this->reported[] = $e;
        });
    }

    protected function tearDown(): void
    {
        if ($this->displayErrors !== false) {
            ini_set('display_errors', $this->displayErrors);
        }

        parent::tearDown();
    }

    public static function hostileQueries(): array
    {
        return [
            // The probe from Nightwatch issue #24, 130 levels deep.
            'nesting past max_input_nesting_level' => [
                'a'.implode('', array_map(fn (int $i) => "[k{$i}]", range(0, 129))).'=1',
            ],
            'more variables than max_input_vars' => [
                implode('&', array_map(fn (int $i) => "v{$i}=1", range(0, 1000))),
            ],
        ];
    }

    #[DataProvider('hostileQueries')]
    public function test_a_query_php_cannot_parse_is_a_bad_request(string $query): void
    {
        $response = $this->sendRaw('/', $query);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->reported);
    }

    #[DataProvider('hostileQueries')]
    public function test_an_api_call_gets_the_bad_request_as_json(string $query): void
    {
        $response = $this->sendRaw('/api/health', $query, ['HTTP_ACCEPT' => 'application/json']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertJson($response->getContent());
        $this->assertSame([], $this->reported);
    }

    public function test_an_ordinary_query_string_is_untouched(): void
    {
        $response = $this->sendRaw('/api/health', 'a[b][c]=1&d=2');

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_other_warnings_still_fail_loudly(): void
    {
        Route::get('/_test/warning', fn () => throw new ErrorException('Undefined array key "x"', 0, E_WARNING));

        $response = $this->sendRaw('/_test/warning', '');

        $this->assertSame(500, $response->getStatusCode());
        $this->assertCount(1, $this->reported);
        $this->assertInstanceOf(ErrorException::class, $this->reported[0]);
    }

    /**
     * $this->get() cannot carry such a query: Request::create() parse_str()s
     * it up front and the test would throw before the app sees it. In
     * production PHP has already parsed $_GET (truncating it) and the raw
     * string waits in QUERY_STRING, which is what this rebuilds.
     */
    private function sendRaw(string $path, string $query, array $server = []): Response
    {
        $request = Request::create($path, 'GET', server: $server);
        $request->server->set('QUERY_STRING', $query);

        return $this->app->make(Kernel::class)->handle($request);
    }
}
