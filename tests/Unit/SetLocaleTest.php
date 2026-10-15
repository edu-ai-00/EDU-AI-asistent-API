<?php

namespace Tests\Unit;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SetLocaleTest extends TestCase
{
    /**
     * @return array<string, array{0: string|null, 1: string}>
     */
    public static function headerProvider(): array
    {
        return [
            'czech' => ['cs', 'cs'],
            'slovak' => ['sk', 'sk'],
            'english' => ['en', 'en'],
            'region suffix' => ['sk-SK', 'sk'],
            'quality values' => ['sk-SK,sk;q=0.9,en;q=0.8', 'sk'],
            'uppercase' => ['SK', 'sk'],
            'unsupported' => ['de', 'cs'],
            // App builds released before the language switcher send no
            // header at all — they must keep getting Czech, not the `en`
            // sitting in config('app.locale').
            'absent' => [null, 'cs'],
            'empty' => ['', 'cs'],
        ];
    }

    #[DataProvider('headerProvider')]
    public function test_it_resolves_the_request_locale(?string $header, string $expected): void
    {
        App::setLocale('en');

        $request = Request::create('/api/anything', 'POST');
        // Symfony seeds a default Accept-Language on created requests, so an
        // "absent" header has to be removed explicitly.
        $request->headers->remove('Accept-Language');
        if ($header !== null) {
            $request->headers->set('Accept-Language', $header);
        }

        $seen = null;
        (new SetLocale)->handle($request, function () use (&$seen) {
            $seen = App::getLocale();

            return response('ok');
        });

        $this->assertSame($expected, $seen);
    }
}
