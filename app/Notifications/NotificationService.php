<?php

namespace App\Notifications;

use App\Jobs\DispatchNotificationRequest;
use App\Jobs\SendPushNotification;
use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\NotificationType;
use App\Models\PushDevice;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\Push\PushSender;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one pipeline every notification goes through (NOTIF-1, NOTIF-6,
 * spec §2): rule engine, broadcasts and admin test sends all end in send().
 *
 * Per recipient, in order:
 *   1. duplicates in the recipient list are merged
 *   2. recipient check (exists, active, not merged, role, context access)
 *   3. type active
 *   4. dedup — same user + dedup key inside the type's window, guarded by
 *      an atomic cache lock so two workers cannot both insert
 *   5. frequency limit per type, then the category's daily cap counted in
 *      the recipient's local day (mandatory types and tests are exempt)
 *   6. channel intersection
 *   7. the user_notifications row (= the feed item) with rendered texts
 *   8. the push sub-decision: unavailable / no device / opted out / quiet
 *      hours (deferred) / queued
 *
 * Every decision is written to notification_events with a reason. Feed
 * first, push optional: nothing that happens to the push — skipped, deferred,
 * a queue outage — touches the feed row once it exists.
 *
 * Only invalid input throws (NotificationValidationException); a problem
 * with one recipient is a logged decision, never an exception.
 */
class NotificationService
{
    public const REASON_TYPE_INACTIVE = 'type_inactive';
    public const REASON_DUPLICATE = 'duplicate';
    public const REASON_FREQUENCY_LIMIT = 'frequency_limit';
    public const REASON_CATEGORY_DAILY_CAP = 'category_daily_cap';
    public const REASON_NO_CHANNELS = 'no_channels';
    public const REASON_PUSH_UNAVAILABLE = 'push_unavailable';
    public const REASON_NO_DEVICE = 'no_device';
    public const REASON_PUSH_DISABLED = 'push_disabled_by_user';
    public const REASON_QUIET_HOURS = 'quiet_hours';
    public const REASON_QUEUE_UNAVAILABLE = 'queue_unavailable';

    /** How long the dedup lock may be held, and how long a second worker waits for it. */
    private const LOCK_SECONDS = 15;
    private const LOCK_WAIT_SECONDS = 5;

    public function __construct(
        private readonly RecipientGuard $guard,
        private readonly TemplateRenderer $renderer,
        private readonly StatusTracker $tracker,
        private readonly PushSender $push,
        private readonly Dispatcher $bus,
    ) {}

    /**
     * @throws NotificationValidationException for a request that cannot be processed at all
     */
    public function send(NotificationRequest $request): NotificationResult
    {
        [$type, $recipientIds, $channels, $payload] = $this->validate($request);

        if ($request->deliverAt !== null) {
            $scheduled = $this->schedule($request);
            if ($scheduled !== null) {
                return $scheduled;
            }
        }

        $result = new NotificationResult();
        $dedupKey = $this->dedupKey($request, $type, $payload);
        $users = User::whereIn('id', $recipientIds)->get()->keyBy('id');

        foreach ($recipientIds as $userId) {
            $this->sendToRecipient($request, $type, $userId, $users->get($userId), $payload, $channels, $dedupKey, $result);
        }

        return $result;
    }

    /**
     * Put a push that never reached the queue (queue_unavailable) or whose
     * quiet-hours deferral is overdue back on the queue. For the
     * notifications:retry-stuck command. Returns false if the queue is still
     * down.
     */
    public function retryPush(UserNotification $notification): bool
    {
        if ($notification->push_status !== UserNotification::PUSH_DEFERRED) {
            return false;
        }

        try {
            $this->bus->dispatch(new SendPushNotification($notification->id));
        } catch (\Throwable $e) {
            Log::channel('notifications')->warning('notifications.push_redispatch_failed', [
                'notification_id' => $notification->uuid,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if ($notification->failure_reason === self::REASON_QUEUE_UNAVAILABLE) {
            $notification->forceFill(['failure_reason' => null])->save();
        }

        return true;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Request validation
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * @return array{0: NotificationType, 1: list<int>, 2: list<string>, 3: array<string, scalar|null>}
     */
    private function validate(NotificationRequest $request): array
    {
        if (!in_array($request->source, UserNotification::SOURCES, true)) {
            throw NotificationValidationException::make(
                'invalid_source',
                'source',
                sprintf('Neplatný zdroj „%s“. Povolené: %s.', $request->source, implode(', ', UserNotification::SOURCES)),
            );
        }

        $type = NotificationType::where('key', $request->typeKey)->first();
        if ($type === null) {
            throw NotificationValidationException::make(
                'unknown_type',
                'type_key',
                sprintf('Neznámý typ notifikace „%s“.', $request->typeKey),
            );
        }

        $recipientIds = [];
        foreach ($request->recipientIds as $id) {
            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0) {
                $recipientIds[(int) $id] = true;
            }
        }
        $recipientIds = array_keys($recipientIds);

        if ($recipientIds === []) {
            throw NotificationValidationException::make('no_recipients', 'recipient_ids', 'Notifikace nemá žádné příjemce.');
        }

        $payload = [];
        foreach ($request->payload as $key => $value) {
            if (!is_scalar($value) && $value !== null) {
                throw NotificationValidationException::make(
                    'invalid_payload',
                    'payload.' . $key,
                    sprintf('Hodnota proměnné „%s“ musí být text nebo číslo.', $key),
                );
            }

            $payload[(string) $key] = $value;
        }

        if ($request->targetRole !== null && !in_array($request->targetRole, NotificationType::ROLES, true)) {
            throw NotificationValidationException::make(
                'invalid_target_role',
                'target_role',
                sprintf('Neplatná cílová role „%s“.', $request->targetRole),
            );
        }

        $typeChannels = $type->channelList();

        if ($request->channels === null) {
            $channels = $typeChannels;
        } else {
            $requested = array_values(array_unique($request->channels));
            $unknown = array_diff($requested, NotificationType::CHANNELS);

            if ($requested === [] || $unknown !== []) {
                throw NotificationValidationException::make(
                    'invalid_channels',
                    'channels',
                    'Kanály musí být neprázdný výběr z: push, feed.',
                );
            }

            $channels = array_values(array_intersect($typeChannels, $requested));
        }

        // A direct caller (admin test, broadcast) learns immediately that the
        // type does not allow what it asked for. The rule engine does not:
        // a rule left behind by a later type edit must not fail its job, so
        // each recipient gets a logged no_channels decision instead (step 6).
        if ($channels === [] && $request->source !== UserNotification::SOURCE_RULE) {
            throw NotificationValidationException::make(
                'no_channels',
                'channels',
                sprintf('Typ „%s“ nepovoluje žádný z požadovaných kanálů.', $type->key),
            );
        }

        return [$type, $recipientIds, $channels, $payload];
    }

    /**
     * The request's own dedup key, or "{type_key}:sha1(payload)". Keys longer
     * than the column are shortened with a hash suffix, keeping them unique.
     *
     * @param  array<string, scalar|null>  $payload
     */
    private function dedupKey(NotificationRequest $request, NotificationType $type, array $payload): string
    {
        if ($request->dedupKey !== null && $request->dedupKey !== '') {
            $key = $request->dedupKey;
        } else {
            ksort($payload);
            $key = $type->key . ':' . sha1((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        }

        return strlen($key) > 191 ? substr($key, 0, 150) . ':' . sha1($key) : $key;
    }

    /**
     * A future deliverAt: queue a delayed replay of the request and stop.
     * Returns null when the time is not (meaningfully) in the future, or the
     * queue refuses the job — then the notification goes out now rather
     * than never.
     */
    private function schedule(NotificationRequest $request): ?NotificationResult
    {
        $at = CarbonImmutable::instance($request->deliverAt);

        if ($at->lessThanOrEqualTo(now()->addSecond())) {
            return null;
        }

        try {
            $this->bus->dispatch(
                (new DispatchNotificationRequest($request->withoutDeliverAt()->toArray()))->delay($at)
            );
        } catch (\Throwable $e) {
            Log::channel('notifications')->warning('notifications.schedule_failed_sending_now', [
                'type_key' => $request->typeKey,
                'deliver_at' => $at->toIso8601String(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return NotificationResult::scheduledFor($at);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Per-recipient pipeline
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * @param  array<string, scalar|null>  $payload
     * @param  list<string>  $channels
     */
    private function sendToRecipient(
        NotificationRequest $request,
        NotificationType $type,
        int $userId,
        ?User $user,
        array $payload,
        array $channels,
        string $dedupKey,
        NotificationResult $result,
    ): void {
        $isTest = $request->isTest();

        // 2. Recipient check
        $reject = $this->guard->rejectReason($user, $type, $request->context, $isTest, $request->targetRole);
        if ($reject !== null) {
            $this->decide($result, $request, $type, $userId, NotificationEvent::DECISION_REJECT, $reject);

            return;
        }

        // 3. Type active
        if (!$type->is_active) {
            $this->decide($result, $request, $type, $userId, NotificationEvent::DECISION_REJECT, self::REASON_TYPE_INACTIVE);

            return;
        }

        $preference = NotificationPreference::forUser($userId);

        if ($isTest) {
            // Tests skip dedup and limits — the admin wants to see it now.
            $notification = $this->limitsAndCreate($request, $type, $user, $preference, $payload, $channels, $dedupKey, $result);
        } else {
            $lock = Cache::lock('notif:' . $userId . ':' . $dedupKey, self::LOCK_SECONDS);

            try {
                $notification = $lock->block(
                    self::LOCK_WAIT_SECONDS,
                    fn () => $this->limitsAndCreate($request, $type, $user, $preference, $payload, $channels, $dedupKey, $result),
                );
            } catch (LockTimeoutException) {
                // Another worker holds the lock for this very user + key, i.e.
                // is creating this notification right now.
                $this->decide($result, $request, $type, $userId, NotificationEvent::DECISION_SUPPRESS, self::REASON_DUPLICATE, [
                    'dedup_key' => $dedupKey,
                    'lock_timeout' => true,
                ]);

                return;
            }
        }

        if ($notification !== null && $notification->hasChannel(NotificationType::CHANNEL_PUSH)) {
            $this->decidePush($notification, $type, $preference, $isTest, $result);
        }
    }

    /**
     * Steps 4–7. Runs under the dedup lock (except for test sends).
     *
     * @param  array<string, scalar|null>  $payload
     * @param  list<string>  $channels
     */
    private function limitsAndCreate(
        NotificationRequest $request,
        NotificationType $type,
        User $user,
        NotificationPreference $preference,
        array $payload,
        array $channels,
        string $dedupKey,
        NotificationResult $result,
    ): ?UserNotification {
        $isTest = $request->isTest();

        // 4. Dedup — mandatory types too; only test sends are exempt.
        if (!$isTest && $type->dedup_window_hours > 0) {
            $existing = UserNotification::where('user_id', $user->id)
                ->where('dedup_key', $dedupKey)
                ->where('is_test', false)
                ->where('created_at', '>=', now()->subHours($type->dedup_window_hours))
                ->value('uuid');

            if ($existing !== null) {
                $this->decide($result, $request, $type, $user->id, NotificationEvent::DECISION_SUPPRESS, self::REASON_DUPLICATE, [
                    'dedup_key' => $dedupKey,
                    'existing_notification_id' => $existing,
                ]);

                return null;
            }
        }

        // 5. Frequency limit, then the category's daily cap.
        if (!$isTest && !$type->is_mandatory) {
            if ($type->frequency_limit !== null && $type->frequency_period_hours) {
                $sent = UserNotification::where('user_id', $user->id)
                    ->where('notification_type_id', $type->id)
                    ->where('is_test', false)
                    ->where('created_at', '>=', now()->subHours($type->frequency_period_hours))
                    ->count();

                if ($sent >= $type->frequency_limit) {
                    $this->decide($result, $request, $type, $user->id, NotificationEvent::DECISION_SUPPRESS, self::REASON_FREQUENCY_LIMIT, [
                        'limit' => $type->frequency_limit,
                        'period_hours' => $type->frequency_period_hours,
                        'count' => $sent,
                    ]);

                    return null;
                }
            }

            $cap = config('notifications.category_daily_caps.' . $type->category);
            if ($cap !== null) {
                $timezone = QuietHours::safeTimezone($preference->effectiveTimezone());
                [$dayStart, $dayEnd] = QuietHours::localDayBounds(now(), $timezone);

                $sentToday = UserNotification::where('user_id', $user->id)
                    ->where('category', $type->category)
                    ->where('is_test', false)
                    ->where('created_at', '>=', $dayStart)
                    ->where('created_at', '<', $dayEnd)
                    ->count();

                if ($sentToday >= (int) $cap) {
                    $this->decide($result, $request, $type, $user->id, NotificationEvent::DECISION_SUPPRESS, self::REASON_CATEGORY_DAILY_CAP, [
                        'category' => $type->category,
                        'cap' => (int) $cap,
                        'timezone' => $timezone,
                    ]);

                    return null;
                }
            }
        }

        // 6. Channels
        if ($channels === []) {
            $this->decide($result, $request, $type, $user->id, NotificationEvent::DECISION_SUPPRESS, self::REASON_NO_CHANNELS);

            return null;
        }

        // 7. Create
        return $this->create($request, $type, $user, $payload, $channels, $dedupKey, $result);
    }

    /**
     * @param  array<string, scalar|null>  $payload
     * @param  list<string>  $channels
     */
    private function create(
        NotificationRequest $request,
        NotificationType $type,
        User $user,
        array $payload,
        array $channels,
        string $dedupKey,
        NotificationResult $result,
    ): UserNotification {
        $payload = $this->recipientPayload($type, $payload, $user);
        $rendered = $this->renderer->renderForSend($type, $payload, ['user_id' => $user->id]);
        $withPush = in_array(NotificationType::CHANNEL_PUSH, $channels, true);

        $notification = DB::transaction(function () use ($request, $type, $user, $payload, $channels, $dedupKey, $rendered, $withPush) {
            $notification = UserNotification::create([
                'user_id' => $user->id,
                'notification_type_id' => $type->id,
                'type_key' => $type->key,
                'category' => $type->category,
                'source' => $request->source,
                'rule_id' => $request->ruleId,
                'broadcast_id' => $request->broadcastId,
                'is_test' => $request->isTest(),
                'dedup_key' => $dedupKey,
                'channels' => $channels,
                'in_feed' => in_array(NotificationType::CHANNEL_FEED, $channels, true),
                'title' => $rendered['feed_title'],
                'body' => $rendered['feed_body'],
                'push_title' => $withPush ? $rendered['push_title'] : null,
                'push_body' => $withPush ? $rendered['push_body'] : null,
                'deep_link' => $rendered['deep_link'],
                'payload' => $payload,
                'status' => UserNotification::STATUS_CREATED,
                'target_action' => $type->target_action,
            ]);

            NotificationEvent::forNotification($notification, UserNotification::STATUS_CREATED, null, [
                'channels' => $channels,
                'source' => $request->source,
            ]);
            NotificationEvent::forNotification($notification, NotificationEvent::DECISION_DELIVER, null, [
                'is_test' => $request->isTest(),
            ]);

            return $notification;
        });

        $result->addDecision($user->id, NotificationResult::DELIVER, null, $notification);

        return $notification;
    }

    /**
     * The variables for this recipient: the request's payload plus `name`
     * (first name) when the type declares it and the caller did not set it.
     *
     * @param  array<string, scalar|null>  $payload
     * @return array<string, scalar|null>
     */
    private function recipientPayload(NotificationType $type, array $payload, User $user): array
    {
        if ($type->declaresVariable('name') && (!isset($payload['name']) || trim((string) $payload['name']) === '')) {
            $payload['name'] = self::firstName($user);
        }

        return $payload;
    }

    /** First word of the user's name; '' lets the variable's fallback ("Ahoj") take over. */
    public static function firstName(User $user): string
    {
        $parts = preg_split('/\s+/u', trim((string) $user->name), -1, PREG_SPLIT_NO_EMPTY);

        return $parts[0] ?? '';
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Push sub-decision (step 8)
    // ═══════════════════════════════════════════════════════════════════════════

    private function decidePush(
        UserNotification $notification,
        NotificationType $type,
        NotificationPreference $preference,
        bool $isTest,
        NotificationResult $result,
    ): void {
        $userId = $notification->user_id;

        if (!$this->push->isConfigured()) {
            $this->skipPush($notification, self::REASON_PUSH_UNAVAILABLE, $result);

            return;
        }

        if (!PushDevice::where('user_id', $userId)->enabled()->exists()) {
            $this->skipPush($notification, self::REASON_NO_DEVICE, $result);

            return;
        }

        if (!$preference->push_enabled && !$type->is_mandatory) {
            $this->skipPush($notification, self::REASON_PUSH_DISABLED, $result);

            return;
        }

        if ($type->respect_quiet_hours && !$type->is_mandatory && !$isTest) {
            $quiet = QuietHours::forPreference($preference);

            if ($quiet->contains(now())) {
                $until = $quiet->endAfter(now());

                $notification->forceFill([
                    'push_status' => UserNotification::PUSH_DEFERRED,
                    'deferred_until' => $until,
                ])->save();

                NotificationEvent::forNotification($notification, NotificationEvent::DECISION_DEFER, self::REASON_QUIET_HOURS, [
                    'channel' => NotificationType::CHANNEL_PUSH,
                    'until' => $until->toIso8601String(),
                    'timezone' => $quiet->timezone,
                ]);
                $result->setPush($userId, UserNotification::PUSH_DEFERRED, self::REASON_QUIET_HOURS);

                $this->dispatchPush($notification, $until, $result);

                return;
            }
        }

        $notification->forceFill(['push_status' => UserNotification::PUSH_QUEUED])->save();
        $this->tracker->transition($notification, UserNotification::STATUS_QUEUED);
        $result->setPush($userId, UserNotification::PUSH_QUEUED);

        $this->dispatchPush($notification, null, $result);
    }

    private function skipPush(UserNotification $notification, string $reason, NotificationResult $result): void
    {
        $notification->forceFill([
            'push_status' => UserNotification::PUSH_SKIPPED,
            'failure_reason' => $reason,
        ])->save();

        NotificationEvent::forNotification($notification, NotificationEvent::DECISION_SUPPRESS, $reason, [
            'channel' => NotificationType::CHANNEL_PUSH,
        ]);
        $result->setPush($notification->user_id, UserNotification::PUSH_SKIPPED, $reason);
    }

    /**
     * Hand the push to the queue. If the queue itself is down, the feed row
     * stays as it is and the push is parked as deferred/queue_unavailable for
     * notifications:retry-stuck (NOTIF-1: "feed zůstane, push se zařadí k
     * opakování").
     */
    private function dispatchPush(UserNotification $notification, ?CarbonImmutable $delayUntil, NotificationResult $result): void
    {
        try {
            $job = new SendPushNotification($notification->id);
            if ($delayUntil !== null) {
                $job->delay($delayUntil);
            }

            $this->bus->dispatch($job);
        } catch (\Throwable $e) {
            Log::channel('notifications')->error('notifications.push_dispatch_failed', [
                'notification_id' => $notification->uuid,
                'error' => $e->getMessage(),
            ]);

            $notification->forceFill([
                'push_status' => UserNotification::PUSH_DEFERRED,
                'deferred_until' => $delayUntil ?? now(),
                'failure_reason' => self::REASON_QUEUE_UNAVAILABLE,
            ])->save();

            NotificationEvent::forNotification($notification, NotificationEvent::DECISION_DEFER, self::REASON_QUEUE_UNAVAILABLE, [
                'channel' => NotificationType::CHANNEL_PUSH,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);
            $result->setPush($notification->user_id, UserNotification::PUSH_DEFERRED, self::REASON_QUEUE_UNAVAILABLE);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Decision log
    // ═══════════════════════════════════════════════════════════════════════════

    /** @param  array<string, mixed>  $meta */
    private function decide(
        NotificationResult $result,
        NotificationRequest $request,
        NotificationType $type,
        int $userId,
        string $event,
        string $reason,
        array $meta = [],
    ): void {
        NotificationEvent::record([
            'notification_id' => null,
            'user_id' => $userId,
            'type_key' => $type->key,
            'rule_id' => $request->ruleId,
            'broadcast_id' => $request->broadcastId,
            'event' => $event,
            'reason' => $reason,
            'meta' => ['source' => $request->source] + $meta,
        ]);

        $result->addDecision(
            $userId,
            $event === NotificationEvent::DECISION_REJECT ? NotificationResult::REJECT : NotificationResult::SUPPRESS,
            $reason,
        );
    }
}
