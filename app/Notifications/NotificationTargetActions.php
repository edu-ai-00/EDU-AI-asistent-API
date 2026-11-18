<?php

namespace App\Notifications;

use App\Models\UserNotification;
use Illuminate\Support\Facades\Log;

/**
 * "Did the notification work?" (NOTIF-8 F, spec §6.4)
 *
 * A notification type may name a target action — the thing it is meant to
 * get the user to do. Producers report those actions here:
 *
 *   learning.activity  progress / heartbeat / quiz sync
 *   quiz.completed     a quiz attempt
 *   practice.reviewed  a practice review log
 *   game.played        a mini game play
 *
 * The user's most recent notification with that target action that they
 * clicked or opened from within the last 24 hours, and that is not marked
 * yet, gets target_action_at. Never throws — a producer's own work must not
 * fail over analytics.
 */
class NotificationTargetActions
{
    public const LEARNING_ACTIVITY = 'learning.activity';
    public const QUIZ_COMPLETED = 'quiz.completed';
    public const PRACTICE_REVIEWED = 'practice.reviewed';
    public const GAME_PLAYED = 'game.played';

    public const ACTIONS = [
        self::LEARNING_ACTIVITY,
        self::QUIZ_COMPLETED,
        self::PRACTICE_REVIEWED,
        self::GAME_PLAYED,
    ];

    private const WINDOW_HOURS = 24;

    /** @return bool  whether a notification was marked */
    public static function record(int $userId, string $action): bool
    {
        try {
            $since = now()->subHours(self::WINDOW_HOURS);

            $notification = UserNotification::where('user_id', $userId)
                ->where('target_action', $action)
                ->whereNull('target_action_at')
                ->where(function ($query) use ($since) {
                    $query->where('clicked_at', '>=', $since)
                        ->orWhere('app_opened_at', '>=', $since);
                })
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            if ($notification === null) {
                return false;
            }

            return app(StatusTracker::class)->transition($notification, StatusTracker::TARGET_ACTION, [
                'action' => $action,
            ]);
        } catch (\Throwable $e) {
            Log::channel('notifications')->warning('notifications.target_action_failed', [
                'user_id' => $userId,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
