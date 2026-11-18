<?php

namespace App\Notifications;

use App\Models\News;
use App\Models\UserNotification;

/**
 * The Novinky badge (NOTIF-4): unread published news plus unread in-feed
 * notifications. One definition shared by every endpoint that returns it.
 */
final class UnreadCount
{
    public static function forUser(int $userId): int
    {
        $news = News::published()
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $userId))
            ->count();

        $notifications = UserNotification::where('user_id', $userId)
            ->inFeed()
            ->unread()
            ->count();

        return $news + $notifications;
    }
}
