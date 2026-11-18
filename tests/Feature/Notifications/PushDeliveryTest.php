<?php

namespace Tests\Feature\Notifications;

use App\Jobs\SendPushNotification;
use App\Models\NotificationEvent;
use App\Models\PushDevice;
use App\Models\UserNotification;
use App\Notifications\Push\FcmPushSender;
use App\Notifications\Push\NullPushSender;
use App\Notifications\Push\PushMessage;
use App\Notifications\Push\PushResult;
use App\Notifications\Push\PushSender;
use App\Notifications\StatusTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Push delivery (NOTIF-2, NOTIF-3, spec §6.1/§6.3): the FCM HTTP v1 adapter
 * against a faked Google, and the SendPushNotification job's retries,
 * invalid-token handling and idempotency.
 */
class PushDeliveryTest extends TestCase
{
    use NotificationTestHelpers;
    use RefreshDatabase;

    private const FCM_URL = 'https://fcm.googleapis.com/v1/projects/eduai-test/messages:send';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();
    }

    /** A throwaway service account with a real RSA key, as raw JSON. */
    private function serviceAccountJson(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        return json_encode([
            'type' => 'service_account',
            'project_id' => 'eduai-test',
            'private_key_id' => 'kid-1',
            'private_key' => $pem,
            'client_email' => 'push@eduai-test.iam.gserviceaccount.com',
            'token_uri' => self::TOKEN_URL,
        ]);
    }

    private function configureFcm(bool $base64 = false): void
    {
        $json = $this->serviceAccountJson();
        config([
            'notifications.fcm.project_id' => 'eduai-test',
            'notifications.fcm.credentials' => $base64 ? base64_encode($json) : $json,
        ]);
    }

    private function queuedNotification(): UserNotification
    {
        Queue::fake();
        $this->fakePush();
        $student = $this->student();
        $this->device($student, ['token' => 'device-token-1']);
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-07 10:00:00', 'UTC'));

        $notification = $this->sendTo('learning_reminder', [$student])->delivered[0]->fresh();
        $this->assertSame(UserNotification::PUSH_QUEUED, $notification->push_status);

        return $notification;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Binding
    // ─────────────────────────────────────────────────────────────────────────

    public function test_push_sender_binding_follows_configuration(): void
    {
        config(['notifications.fcm.project_id' => null, 'notifications.fcm.credentials' => null]);
        $this->assertInstanceOf(NullPushSender::class, app(PushSender::class));
        $this->assertFalse(app(PushSender::class)->isConfigured());

        $this->configureFcm(base64: true);
        $this->assertInstanceOf(FcmPushSender::class, app(PushSender::class));

        config(['notifications.fcm.credentials' => 'not json, not base64 json']);
        $this->assertInstanceOf(NullPushSender::class, app(PushSender::class));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FCM adapter
    // ─────────────────────────────────────────────────────────────────────────

    public function test_fcm_sends_the_v1_message_with_a_cached_oauth_token(): void
    {
        $this->configureFcm();
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            self::FCM_URL => Http::response(['name' => 'projects/eduai-test/messages/1']),
        ]);

        $sender = FcmPushSender::fromConfig();
        $device = $this->device($this->student(), ['token' => 'tok-ok', 'platform' => 'ios']);
        $message = new PushMessage('Je čas na učení', 'Tereza, stačí pár minut.', [
            'notification_id' => '01JABCDEFGHJKMNPQRSTVWXYZ0', 'deep_link' => '/dash', 'type_key' => 'learning_reminder',
        ]);

        $this->assertTrue($sender->send($device, $message)->ok);
        $this->assertTrue($sender->send($device, $message)->ok);

        Http::assertSentCount(3); // one token exchange, two sends
        Http::assertSent(function (HttpRequest $request) {
            if ($request->url() !== self::TOKEN_URL) {
                return false;
            }
            $jwt = explode('.', $request['assertion']);
            $header = json_decode(base64_decode(strtr($jwt[0], '-_', '+/')), true);
            $claims = json_decode(base64_decode(strtr($jwt[1], '-_', '+/')), true);

            return $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
                && $header['alg'] === 'RS256'
                && $header['kid'] === 'kid-1'
                && $claims['scope'] === FcmPushSender::SCOPE
                && $claims['aud'] === self::TOKEN_URL;
        });
        Http::assertSent(function (HttpRequest $request) {
            if ($request->url() !== self::FCM_URL) {
                return false;
            }
            $message = $request['message'];

            return $request->hasHeader('Authorization', 'Bearer ya29.test')
                && $message['token'] === 'tok-ok'
                && $message['notification'] === ['title' => 'Je čas na učení', 'body' => 'Tereza, stačí pár minut.']
                && $message['data']['deep_link'] === '/dash'
                && $message['android'] === ['priority' => 'high', 'notification' => ['channel_id' => 'eduai_default']]
                && $message['apns'] === ['payload' => ['aps' => ['sound' => 'default']]];
        });
    }

    public function test_fcm_classifies_errors(): void
    {
        $this->configureFcm();
        $device = $this->device($this->student(), ['token' => 'tok']);
        $message = new PushMessage('T', 'B');

        $cases = [
            [404, ['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 'invalid'],
            [400, ['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'The registration token is not a valid FCM registration token']], 'invalid'],
            [400, ['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'details' => [['fieldViolations' => [['field' => 'message.token']]]]]], 'invalid'],
            // A malformed message is our bug, not a dead device — never disable on it.
            [400, ['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'Invalid JSON payload received.']], 'failed'],
            [503, ['error' => ['code' => 503, 'status' => 'UNAVAILABLE']], 'retryable'],
            [429, ['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED']], 'retryable'],
            [401, ['error' => ['code' => 401, 'status' => 'UNAUTHENTICATED']], 'retryable'],
            [403, ['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED']], 'failed'],
        ];

        foreach ($cases as [$status, $body, $expected]) {
            // A fresh fake per case — stubs registered earlier would match first.
            Http::swap(new HttpFactory(app('events')));
            Http::fake([
                self::TOKEN_URL => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
                self::FCM_URL => Http::response($body, $status),
            ]);

            $result = FcmPushSender::fromConfig()->send($device, $message);

            $actual = match (true) {
                $result->invalidToken => 'invalid',
                $result->retryable => 'retryable',
                $result->ok => 'ok',
                default => 'failed',
            };
            $this->assertSame($expected, $actual, "HTTP {$status}");
        }
    }

    public function test_an_oauth_failure_is_retryable(): void
    {
        $this->configureFcm();
        Http::fake([self::TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);

        $result = FcmPushSender::fromConfig()->send($this->device($this->student()), new PushMessage('T', 'B'));

        $this->assertTrue($result->retryable);
        $this->assertStringStartsWith('oauth:', $result->error);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SendPushNotification job
    // ─────────────────────────────────────────────────────────────────────────

    public function test_unregistered_token_disables_the_device_and_fails_the_push(): void
    {
        $notification = $this->queuedNotification();
        $this->configureFcm();
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            self::FCM_URL => Http::response(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
        ]);

        (new SendPushNotification($notification->id))->handle(FcmPushSender::fromConfig(), app(StatusTracker::class));

        $device = PushDevice::where('token', 'device-token-1')->first();
        $this->assertNotNull($device->disabled_at);
        $this->assertStringContainsString('UNREGISTERED', $device->disabled_reason);

        $notification->refresh();
        $this->assertSame(UserNotification::PUSH_FAILED, $notification->push_status);
        $this->assertSame(UserNotification::STATUS_FAILED, $notification->status);
        $this->assertSame('invalid_token', $notification->failure_reason);
        $this->assertNotNull($notification->failed_at);
        $this->assertTrue($notification->in_feed);
    }

    public function test_success_on_any_device_marks_the_push_sent(): void
    {
        $notification = $this->queuedNotification();
        $this->device($notification->user, ['token' => 'device-token-2']);

        $fake = new FakePushSender();
        $fake->results['device-token-1'] = PushResult::invalidToken('UNREGISTERED');

        (new SendPushNotification($notification->id))->handle($fake, app(StatusTracker::class));

        $notification->refresh();
        $this->assertSame(UserNotification::PUSH_SENT, $notification->push_status);
        $this->assertSame(UserNotification::STATUS_SENT, $notification->status);
        $this->assertNotNull(PushDevice::where('token', 'device-token-1')->value('disabled_at'));
        $this->assertNull(PushDevice::where('token', 'device-token-2')->value('disabled_at'));
    }

    public function test_retryable_errors_retry_and_the_last_attempt_fails(): void
    {
        $notification = $this->queuedNotification();
        $fake = new FakePushSender();
        $fake->default = PushResult::retryable('503 UNAVAILABLE');

        // Attempt 1 of 3: released for a retry, nothing marked.
        (new SendPushNotification($notification->id))->handle($fake, app(StatusTracker::class));
        $this->assertSame(UserNotification::PUSH_QUEUED, $notification->fresh()->push_status);

        // Out of attempts: failed, with the reason.
        $job = new SendPushNotification($notification->id);
        $job->tries = 1;
        $job->handle($fake, app(StatusTracker::class));

        $notification->refresh();
        $this->assertSame(UserNotification::PUSH_FAILED, $notification->push_status);
        $this->assertSame('503 UNAVAILABLE', $notification->failure_reason);
        $this->assertNull(PushDevice::where('token', 'device-token-1')->value('disabled_at'));
    }

    public function test_the_failed_handler_records_the_technical_reason(): void
    {
        $notification = $this->queuedNotification();

        (new SendPushNotification($notification->id))->failed(new \RuntimeException('worker killed'));

        $notification->refresh();
        $this->assertSame(UserNotification::PUSH_FAILED, $notification->push_status);
        $this->assertStringContainsString('worker killed', $notification->failure_reason);
        $this->assertDatabaseHas('notification_events', ['notification_id' => $notification->id, 'event' => 'failed']);
    }

    public function test_a_push_already_sent_is_never_sent_again(): void
    {
        $notification = $this->queuedNotification();
        $fake = new FakePushSender();

        (new SendPushNotification($notification->id))->handle($fake, app(StatusTracker::class));
        (new SendPushNotification($notification->id))->handle($fake, app(StatusTracker::class));
        (new SendPushNotification($notification->id))->failed(new \RuntimeException('late failure'));

        $this->assertCount(1, $fake->sent);
        $notification->refresh();
        $this->assertSame(UserNotification::PUSH_SENT, $notification->push_status);
        $this->assertSame(UserNotification::STATUS_SENT, $notification->status);
        $this->assertSame(1, NotificationEvent::where('notification_id', $notification->id)->where('event', 'sent')->count());
    }

    public function test_a_deferred_push_is_sent_once_quiet_hours_are_over(): void
    {
        Queue::fake();
        $this->fakePush();
        $student = $this->student();
        $this->device($student);
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-07 21:00:00', 'UTC')); // 23:00 Prague

        $notification = $this->sendTo('learning_reminder', [$student])->delivered[0]->fresh();
        $this->assertSame(UserNotification::PUSH_DEFERRED, $notification->push_status);

        $fake = new FakePushSender();

        // Picked up early: nothing sent.
        (new SendPushNotification($notification->id))->handle($fake, app(StatusTracker::class));
        $this->assertCount(0, $fake->sent);

        // After 07:00 Prague.
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-08 05:01:00', 'UTC'));
        (new SendPushNotification($notification->id))->handle($fake, app(StatusTracker::class));

        $notification->refresh();
        $this->assertCount(1, $fake->sent);
        $this->assertSame(UserNotification::PUSH_SENT, $notification->push_status);
        $this->assertNotNull($notification->queued_at);
    }

    public function test_opting_out_while_queued_skips_the_push(): void
    {
        $notification = $this->queuedNotification();
        $this->preferences($notification->user, ['push_enabled' => false]);
        $fake = new FakePushSender();

        (new SendPushNotification($notification->id))->handle($fake, app(StatusTracker::class));

        $this->assertCount(0, $fake->sent);
        $this->assertSame(UserNotification::PUSH_SKIPPED, $notification->fresh()->push_status);
    }
}
