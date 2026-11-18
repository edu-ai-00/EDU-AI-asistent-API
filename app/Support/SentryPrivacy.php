<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Laravel\Sanctum\Events\TokenAuthenticated;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Logs\Log;
use Sentry\State\Scope;

use function Sentry\configureScope;

/**
 * What Sentry learns about a user and a URL. config/sentry.php switches the
 * SDK's own user collection off, so this is the one place a user is described.
 */
class SentryPrivacy
{
    /**
     * Path segments that are themselves the credential: a guardian's
     * magic-link token (ConsentManageController) and the QR capture token
     * (CourseGenerationController::captureInfo/captureUpload). Either one in
     * a third-party store is a working link. Route patterns (`{token}`, the
     * transaction name) and the request-link route are left as they are.
     */
    private const SECRET_PATH = '#(/consent/manage|/generation/capture)/(?!request-link\b|\{)[^/?\#]+#';

    /**
     * Team accounts keep their email (an admin hitting a 500 is someone to
     * call). Everyone else — for most of our users a child — is sent as id +
     * role only, which is enough to look them up in the admin and keeps
     * student PII out of a store the erasure flow cannot reach.
     *
     * @return array<string, int|string>
     */
    public static function user(User $user, ?string $ipAddress): array
    {
        $isTeam = $user->isAdminOrTeacher();

        return array_filter([
            'id' => $user->getKey(),
            'email' => $isTeam ? $user->email : null,
            'ip_address' => $ipAddress,
            'role' => $isTeam ? $user->role : ($user->is_guest ? 'guest' : ($user->is_minor ? 'student (minor)' : 'student')),
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Listener for Sanctum's TokenAuthenticated (bearer tokens — the app and
     * the admin) and Laravel's Authenticated (session guard).
     */
    public function handle(Authenticated|TokenAuthenticated $event): void
    {
        $user = $event instanceof TokenAuthenticated ? $event->token->tokenable : $event->user;

        if (! $user instanceof User) {
            return;
        }

        $data = self::user($user, self::clientIp(request()));

        configureScope(function (Scope $scope) use ($data): void {
            $scope->setUser($data);
            $scope->setTag('user.role', (string) $data['role']);
        });
    }

    /**
     * before_send and before_send_transaction: replace credential path
     * segments in the request URL, the transaction name and the trace data,
     * and keep query bindings out of database error messages.
     */
    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        $queryException = self::queryExceptionIn($hint?->exception);
        foreach ($event->getExceptions() as $exception) {
            $exception->setValue($queryException !== null && is_a($exception->getType(), QueryException::class, true)
                ? self::describeQueryException($queryException)
                : self::scrubSql($exception->getValue()));
        }

        $event->setBreadcrumb(array_map(
            fn (Breadcrumb $breadcrumb) => $breadcrumb->getMessage() === null
                ? $breadcrumb
                : $breadcrumb->withMessage(self::scrubSql($breadcrumb->getMessage())),
            $event->getBreadcrumbs(),
        ));

        $request = $event->getRequest();
        if (isset($request['url']) && is_string($request['url'])) {
            $request['url'] = self::scrubPath($request['url']);
            $event->setRequest($request);
        }

        if ($event->getTransaction() !== null) {
            $event->setTransaction(self::scrubPath($event->getTransaction()));
        }

        $trace = $event->getContexts()['trace'] ?? null;
        if (is_array($trace) && isset($trace['data']['url']) && is_string($trace['data']['url'])) {
            $trace['data']['url'] = self::scrubPath($trace['data']['url']);
            $event->setContext('trace', $trace);
        }

        return $event;
    }

    /**
     * before_send_log: Laravel logs every reported exception, so a database
     * error's message reaches the logs too, with no exception object left to
     * rebuild it from.
     */
    public static function beforeSendLog(Log $log): ?Log
    {
        return $log->setBody(self::scrubSql($log->getBody()));
    }

    public static function scrubPath(string $url): string
    {
        return preg_replace(self::SECRET_PATH, '$1/[Filtered]', $url) ?? $url;
    }

    /**
     * QueryException::getMessage() has the bindings substituted into the SQL
     * — e-mails, names, answers — and Postgres adds a DETAIL line quoting the
     * offending values (`Key (email)=(…) already exists`). data_collection's
     * database_query_data only covers breadcrumbs and spans, not this text.
     */
    public static function scrubSql(string $message): string
    {
        $message = preg_replace('/DETAIL:.*?(?=\s*\(Connection:|\z)/s', 'DETAIL: [Filtered]', $message) ?? $message;

        return preg_replace('/(\(Connection: [^)]*?SQL: ).*\)/s', '$1[Filtered])', $message) ?? $message;
    }

    /** The message Laravel would build, with the `?` placeholders kept. */
    private static function describeQueryException(QueryException $e): string
    {
        $driverMessage = $e->getPrevious()?->getMessage() ?? '';

        return self::scrubSql($driverMessage).' (Connection: '.$e->getConnectionName().', SQL: '.$e->getSql().')';
    }

    private static function queryExceptionIn(?\Throwable $e): ?QueryException
    {
        for (; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof QueryException) {
                return $e;
            }
        }

        return null;
    }

    /**
     * bootstrap/app.php does not configure trustProxies() (see the rate-limit
     * notes in AppServiceProvider), so behind the Railway edge $request->ip()
     * is the proxy. The edge puts the caller's address in X-Real-IP.
     */
    private static function clientIp(Request $request): ?string
    {
        return $request->header('X-Real-IP') ?: $request->ip();
    }
}
