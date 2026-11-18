<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SentryPrivacy;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDOException;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionDataBag;
use Sentry\Logs\Log;
use Sentry\Logs\LogLevel;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Sentry\UserDataBag;
use Tests\TestCase;

/**
 * What leaves the app for Sentry. Once `data_collection` is set, every
 * category it leaves out falls back to a permissive default (bodies, cookies,
 * SQL bindings, the logged-in user's email), so each one is pinned here
 * rather than trusted to a Railway variable.
 */
class SentryConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The hub outlives a single test; start every test from an empty scope.
        SentrySdk::getCurrentHub()->configureScope(fn (Scope $scope) => $scope->clear());
    }

    public function test_bodies_cookies_and_query_data_are_not_collected(): void
    {
        $collection = config('sentry.data_collection');

        $this->assertSame([], $collection['http_bodies']);
        $this->assertSame('off', $collection['cookies']['mode']);
        $this->assertFalse($collection['database_query_data']);
        $this->assertFalse($collection['queues']);
        $this->assertFalse($collection['stack_frame_variables']);
        $this->assertSame(['inputs' => false, 'outputs' => false], $collection['gen_ai']);
    }

    public function test_auth_headers_are_filtered(): void
    {
        $request = config('sentry.data_collection.http_headers.request');

        $this->assertSame('denyList', $request['mode']);
        foreach (['authorization', 'cookie', 'x-admin-key'] as $header) {
            $this->assertContains($header, $request['terms']);
        }
        $this->assertSame('off', config('sentry.data_collection.http_headers.response.mode'));
    }

    public function test_the_sdk_does_not_describe_users_on_its_own(): void
    {
        // Its own collection would attach every student's email.
        $this->assertFalse(config('sentry.data_collection.user_info'));
    }

    public function test_a_student_is_sent_as_id_and_role_only(): void
    {
        $student = User::factory()->create(['role' => 'student', 'is_minor' => true]);

        $data = SentryPrivacy::user($student, '203.0.113.7');

        $this->assertSame(['id' => $student->id, 'ip_address' => '203.0.113.7', 'role' => 'student (minor)'], $data);
        $this->assertStringNotContainsString($student->email, json_encode($data));
        $this->assertStringNotContainsString($student->name, json_encode($data));
    }

    public function test_a_guest_is_sent_as_a_guest(): void
    {
        $guest = User::factory()->create(['role' => 'student', 'is_guest' => true]);

        $this->assertSame(['id' => $guest->id, 'role' => 'guest'], SentryPrivacy::user($guest, null));
    }

    public function test_a_teacher_keeps_their_email(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->assertSame(
            ['id' => $teacher->id, 'email' => $teacher->email, 'ip_address' => '203.0.113.7', 'role' => 'teacher'],
            SentryPrivacy::user($teacher, '203.0.113.7'),
        );
    }

    public function test_a_bearer_request_puts_the_student_on_the_scope_without_email(): void
    {
        $student = User::factory()->create(['role' => 'student', 'is_minor' => true]);
        $token = $student->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->withHeader('X-Real-IP', '203.0.113.7')
            ->getJson('/api/user');

        $user = $this->scopeUser();
        $this->assertNotNull($user, 'AppServiceProvider must listen for TokenAuthenticated');
        $this->assertSame($student->id, $user->getId());
        $this->assertNull($user->getEmail());
        $this->assertSame('203.0.113.7', $user->getIpAddress());
        $this->assertSame(['role' => 'student (minor)'], $user->getMetadata());
    }

    public function test_a_bearer_request_puts_the_teacher_on_the_scope_with_email(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $token = $teacher->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/user');

        $this->assertSame($teacher->email, $this->scopeUser()?->getEmail());
    }

    public function test_credential_path_segments_are_filtered(): void
    {
        $event = Event::createEvent();
        $event->setRequest(['url' => 'https://app-api.edu-ai.eu/api/consent/manage/s3cr3t-token/revoke', 'method' => 'POST']);
        $event->setTransaction('/api/generation/capture/qr-token-123/attachments');

        $event = SentryPrivacy::beforeSend($event);

        $this->assertSame('https://app-api.edu-ai.eu/api/consent/manage/[Filtered]/revoke', $event->getRequest()['url']);
        $this->assertSame('/api/generation/capture/[Filtered]/attachments', $event->getTransaction());
    }

    public function test_route_patterns_and_the_magic_link_request_route_are_left_alone(): void
    {
        $this->assertSame('/api/consent/manage/request-link', SentryPrivacy::scrubPath('/api/consent/manage/request-link'));
        $this->assertSame('/api/consent/manage/{token}/revoke', SentryPrivacy::scrubPath('/api/consent/manage/{token}/revoke'));
    }

    public function test_query_bindings_never_reach_an_exception_or_a_breadcrumb(): void
    {
        $pdo = new PDOException('SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "users_email_unique"'
            ."\nDETAIL:  Key (email)=(pupil@example.test) already exists.");
        $query = new UniqueConstraintViolationException('pgsql', 'insert into "users" ("email", "name") values (?, ?)', ['pupil@example.test', 'Anička'], $pdo);

        $event = Event::createEvent();
        $event->setExceptions([new ExceptionDataBag($query), new ExceptionDataBag($pdo)]);
        $event->setBreadcrumb([new Breadcrumb('error', 'default', 'log.error', $query->getMessage())]);

        $event = SentryPrivacy::beforeSend($event, EventHint::fromArray(['exception' => $query]));

        $sent = json_encode([
            array_map(fn (ExceptionDataBag $e) => $e->getValue(), $event->getExceptions()),
            array_map(fn (Breadcrumb $b) => $b->getMessage(), $event->getBreadcrumbs()),
        ]);
        $this->assertStringNotContainsString('pupil@example.test', $sent);
        $this->assertStringNotContainsString('Anička', $sent);
        // The statement itself stays, with its placeholders, so the error is still debuggable.
        $this->assertStringContainsString('insert into "users" ("email", "name") values (?, ?)', $event->getExceptions()[0]->getValue());
        $this->assertStringContainsString('users_email_unique', $event->getExceptions()[1]->getValue());
    }

    public function test_query_bindings_never_reach_a_log(): void
    {
        $query = new QueryException('pgsql', 'select * from "users" where "email" = ?', ['pupil@example.test'], new PDOException('SQLSTATE[08006]: connection failure'));

        $log = SentryPrivacy::beforeSendLog(new Log(microtime(true), '0123456789abcdef0123456789abcdef', LogLevel::error(), $query->getMessage()));

        $this->assertStringNotContainsString('pupil@example.test', $log->getBody());
        $this->assertStringContainsString('SQLSTATE[08006]', $log->getBody());
    }

    private function scopeUser(): ?UserDataBag
    {
        $user = null;
        SentrySdk::getCurrentHub()->configureScope(function (Scope $scope) use (&$user) {
            $user = $scope->getUser();
        });

        return $user;
    }
}
