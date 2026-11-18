<?php

namespace Tests\Feature\Notifications;

use App\Models\NotificationBroadcast;
use App\Models\NotificationBroadcastRecipient;
use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\NotificationUserState;
use App\Models\PushDevice;
use App\Models\User;
use App\Models\UserMerge;
use App\Models\UserNotification;
use App\Services\UserMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UserMergeService and the notification tables (spec §1): rows move to the
 * surviving account, one-per-user rows keep the target's, and push devices
 * go with the source's revoked sessions.
 */
class NotificationMergeTest extends TestCase
{
    use NotificationTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();
    }

    private function broadcast(): NotificationBroadcast
    {
        return NotificationBroadcast::create([
            'title' => 'Zpráva', 'body' => 'Text', 'type_key' => 'school_important_message',
            'channels' => ['feed'], 'targeting' => ['school_ids' => [1], 'classroom_ids' => [], 'roles' => ['student']],
        ]);
    }

    public function test_merge_moves_notifications_and_keeps_the_targets_single_rows(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $source = $this->student(['email' => null, 'login_code' => 'SRC123']);
        $target = $this->student();

        $sourceNotification = $this->sendTo('course_completed', [$source], ['course_id' => 1])->delivered[0];
        $this->sendTo('course_completed', [$source], ['course_id' => 1]); // a suppressed decision
        $this->sendTo('course_completed', [$target], ['course_id' => 2]);

        NotificationPreference::create(['user_id' => $source->id, 'timezone' => 'Asia/Tokyo']);
        NotificationPreference::create(['user_id' => $target->id, 'timezone' => 'Europe/Prague']);
        NotificationUserState::create(['user_id' => $source->id, 'week_key' => '2026-W41', 'week_start_xp' => 5]);

        $shared = $this->broadcast();
        $sourceOnly = $this->broadcast();
        NotificationBroadcastRecipient::create(['broadcast_id' => $shared->id, 'user_id' => $source->id, 'role' => 'student']);
        NotificationBroadcastRecipient::create(['broadcast_id' => $shared->id, 'user_id' => $target->id, 'role' => 'student']);
        NotificationBroadcastRecipient::create(['broadcast_id' => $sourceOnly->id, 'user_id' => $source->id, 'role' => 'student']);

        $token = $source->createToken('t');
        $this->device($source, ['token' => 'source-phone', 'personal_access_token_id' => $token->accessToken->id]);
        $this->device($source, ['token' => 'source-tablet']);

        $merge = app(UserMergeService::class)->merge($source->id, $target->id, $admin->id);

        $this->assertSame(0, UserNotification::where('user_id', $source->id)->count());
        $this->assertSame(2, UserNotification::where('user_id', $target->id)->count());
        $this->assertSame($target->id, $sourceNotification->fresh()->user_id);
        $this->assertSame(0, NotificationEvent::where('user_id', $source->id)->count());

        // The moved dedup key still protects the surviving account.
        $again = $this->sendTo('course_completed', [$target], ['course_id' => 1]);
        $this->assertSame(1, $again->suppressedCount());

        $this->assertSame('Europe/Prague', NotificationPreference::find($target->id)->timezone);
        $this->assertNull(NotificationPreference::find($source->id));
        $this->assertSame('2026-W41', NotificationUserState::find($target->id)->week_key);

        $this->assertSame(0, NotificationBroadcastRecipient::where('user_id', $source->id)->count());
        $this->assertEqualsCanonicalizing(
            [$shared->id, $sourceOnly->id],
            NotificationBroadcastRecipient::where('user_id', $target->id)->pluck('broadcast_id')->all(),
        );

        $this->assertSame(0, PushDevice::where('user_id', $source->id)->count());
        $this->assertSame(0, PushDevice::where('user_id', $target->id)->count());

        $log = UserMerge::find($merge->id)->strategy_log['notifications'];
        $this->assertSame(1, $log['user_notifications']);
        $this->assertSame('kept_target', $log['notification_preferences']);
        $this->assertSame('reassigned_source', $log['notification_user_states']);
        $this->assertSame(1, $log['notification_broadcast_recipients_dropped']);
        $this->assertSame(2, $log['push_devices_dropped']);
    }

    public function test_source_preferences_move_when_the_target_has_none(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $source = $this->student(['email' => null, 'login_code' => 'SRC456']);
        $target = $this->student();
        NotificationPreference::create(['user_id' => $source->id, 'push_enabled' => false, 'reminder_time' => '17:00:00']);

        app(UserMergeService::class)->merge($source->id, $target->id, $admin->id);

        $moved = NotificationPreference::find($target->id);
        $this->assertFalse($moved->push_enabled);
        $this->assertSame('17:00', NotificationPreference::hhmm($moved->reminder_time));
    }
}
