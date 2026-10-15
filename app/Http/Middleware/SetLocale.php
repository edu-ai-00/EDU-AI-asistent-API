<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Locales we actually ship translations for. Anything else falls back to
     * the app default rather than silently producing untranslated keys.
     *
     * @var list<string>
     */
    public const SUPPORTED = ['cs', 'sk', 'en'];

    /**
     * Language used when the client does not state a supported one.
     *
     * Deliberately Czech rather than `config('app.locale')` (which is `en`):
     * app builds released before the language switcher send no
     * `Accept-Language`, and they must keep receiving the Czech e-mail they
     * got before this middleware existed.
     */
    public const DEFAULT = 'cs';

    /**
     * Apply the client's language to this request.
     *
     * The Flutter app sends its UI language as `Accept-Language` on every
     * call (see `LocaleInterceptor`). Only the primary subtag is honoured —
     * a browser-style `sk-SK,sk;q=0.9` still resolves to `sk`.
     */
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolve($request->header('Accept-Language')));

        return $next($request);
    }

    private function resolve(?string $header): string
    {
        if ($header === null || $header === '') {
            return self::DEFAULT;
        }

        // "sk-SK,sk;q=0.9,en;q=0.8" → "sk"
        $primary = strtolower(trim(explode(',', $header)[0]));
        $primary = trim(explode(';', $primary)[0]);
        $primary = explode('-', $primary)[0];

        return in_array($primary, self::SUPPORTED, true) ? $primary : self::DEFAULT;
    }
}
