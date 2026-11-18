<?php

namespace App\Notifications;

use App\Jobs\EvaluateNotificationEvent;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Where producers report domain events (spec §3.1):
 *
 *   NotificationEvents::emit('course.completed', [
 *       'course_id' => $course->id, 'course_name' => $course->name, 'course_code' => $course->code,
 *   ], [$user->id]);
 *
 * The data is checked against EventCatalog first. Bad data — an unknown
 * event, a missing required field, a wrong type — is logged as an incident
 * on the `notifications` log channel and dropped: the producer never gets an
 * exception, and the rules are not run on data they were not written for.
 *
 * Valid events are handed to the queue (EvaluateNotificationEvent) so the
 * request that produced them never waits for notifications; a queue outage
 * is logged and swallowed for the same reason. Never throws.
 */
class NotificationEvents
{
    /**
     * @param  array<string, mixed>  $fields
     * @param  array<int, int|string>  $userIds
     * @param  string|null  $dedupKey  overrides the catalog's dedup key — for
     *                                 a producer that knows better, e.g. the
     *                                 recipient's own local date instead of
     *                                 the default timezone's
     * @return bool  whether the event was accepted and queued
     */
    public static function emit(string $eventKey, array $fields, array $userIds, ?string $dedupKey = null): bool
    {
        try {
            $prepared = self::prepare($eventKey, $fields, $userIds);
            if ($prepared === null) {
                return false;
            }

            [$fields, $userIds, $catalogKey] = $prepared;
            $dedupKey = $dedupKey !== null && $dedupKey !== '' ? $dedupKey : $catalogKey;

            app(Dispatcher::class)->dispatch(new EvaluateNotificationEvent($eventKey, $fields, $userIds, $dedupKey));

            return true;
        } catch (\Throwable $e) {
            Log::channel('notifications')->error('notifications.event_dispatch_failed', [
                'event' => $eventKey,
                'recipients' => count($userIds),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Validate and evaluate the event right here, in the caller's process —
     * for code already running on the notifications queue (a broadcast
     * chunk job) that needs the results to update its counters.
     *
     * Unlike emit() this lets unexpected errors through, so a queued caller
     * fails and is retried (sends are deduplicated, a retry is safe).
     *
     * @param  array<string, mixed>  $fields
     * @param  array<int, int|string>  $userIds
     * @param  list<string>|null  $channels  narrows the rules' channels (a broadcast's own choice)
     * @return list<array{rule_id: int, matched: bool, result: ?NotificationResult, error: ?string, skipped: list<int>}>
     */
    public static function evaluateNow(string $eventKey, array $fields, array $userIds, ?array $channels = null): array
    {
        $prepared = self::prepare($eventKey, $fields, $userIds);
        if ($prepared === null) {
            return [];
        }

        [$fields, $userIds, $dedupKey] = $prepared;

        return app(RuleEngine::class)->evaluate($eventKey, $fields, $userIds, $dedupKey, $channels);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<int, int|string>  $userIds
     * @return array{0: array<string, mixed>, 1: list<int>, 2: string}|null
     */
    private static function prepare(string $eventKey, array $fields, array $userIds): ?array
    {
        if (!EventCatalog::has($eventKey)) {
            Log::channel('notifications')->warning('notifications.event_unknown', [
                'event' => $eventKey,
                'fields' => array_keys($fields),
            ]);

            return null;
        }

        [$normalised, $errors] = EventCatalog::validateFields($eventKey, $fields);
        if ($errors !== []) {
            Log::channel('notifications')->warning('notifications.event_invalid', [
                'event' => $eventKey,
                'errors' => $errors,
            ]);

            return null;
        }

        $ids = [];
        foreach ($userIds as $id) {
            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0) {
                $ids[(int) $id] = true;
            }
        }

        if ($ids === []) {
            return null;
        }

        return [$normalised, array_keys($ids), EventCatalog::dedupKey($eventKey, $normalised)];
    }
}
