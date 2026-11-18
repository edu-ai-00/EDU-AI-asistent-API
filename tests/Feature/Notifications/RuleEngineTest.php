<?php

namespace Tests\Feature\Notifications;

use App\Jobs\DispatchNotificationRequest;
use App\Jobs\EvaluateNotificationEvent;
use App\Models\NotificationEvent;
use App\Models\NotificationRule;
use App\Models\UserNotification;
use App\Models\UserStats;
use App\Notifications\EventCatalog;
use App\Notifications\NotificationEvents;
use App\Notifications\NotificationTargetActions;
use App\Notifications\RuleDefinitionValidator;
use App\Notifications\RuleEngine;
use App\Notifications\StatusTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Rules (NOTIF-7, NOTIF-9, spec §3): definition validation with Czech
 * {path, message} errors, the activation guard, the engine, emit(), and
 * target actions.
 */
class RuleEngineTest extends TestCase
{
    use NotificationTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();
    }

    private function validator(): RuleDefinitionValidator
    {
        return app(RuleDefinitionValidator::class);
    }

    private function definition(array $overrides = []): array
    {
        return array_replace_recursive([
            'when' => ['event' => 'course.progress_milestone'],
            'if' => [['field' => 'percent', 'op' => 'eq', 'value' => 50]],
            'then' => ['type' => 'course_halfway', 'target_role' => 'student', 'channels' => ['feed'], 'delay_minutes' => 0],
        ], $overrides);
    }

    private function paths(array $errors): array
    {
        return array_column($errors, 'path');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Validation
    // ─────────────────────────────────────────────────────────────────────────

    public function test_seeded_rules_are_valid_and_active(): void
    {
        $rules = NotificationRule::all();

        $this->assertCount(15, $rules);
        foreach ($rules as $rule) {
            $this->assertTrue($rule->is_system);
            $this->assertTrue($rule->is_active);
            $this->assertSame('system.' . $rule->type->key, $rule->key);
            $this->assertSame([], $this->validator()->errors($rule->definition), "rule {$rule->key}");
            $this->assertSame([], $this->validator()->activationErrors($rule->definition, $rule->id), "rule {$rule->key}");
        }
    }

    public function test_seeder_never_overwrites_admin_edits(): void
    {
        $type = $this->type('course_completed');
        $type->update(['feed_title' => 'Upraveno adminem', 'is_active' => false]);
        $rule = NotificationRule::where('key', 'system.course_completed')->first();
        $rule->update(['is_active' => false]);
        NotificationRule::where('key', 'system.topic_mastered')->delete();

        $this->seedCatalog();

        $this->assertSame('Upraveno adminem', $type->fresh()->feed_title);
        $this->assertFalse($type->fresh()->is_active);
        $this->assertFalse($rule->fresh()->is_active);
        // A missing row is recreated.
        $this->assertTrue(NotificationRule::where('key', 'system.topic_mastered')->exists());
        $this->assertSame(15, NotificationRule::count());
    }

    public function test_valid_definition_has_no_errors(): void
    {
        $this->assertSame([], $this->validator()->errors($this->definition()));
        $this->assertSame([], $this->validator()->errors(json_encode($this->definition())));
    }

    public function test_unknown_field_message_matches_the_contract(): void
    {
        $errors = $this->validator()->errors($this->definition([
            'when' => ['event' => 'course.completed'],
            'if' => [['field' => 'foo', 'op' => 'eq', 'value' => 1]],
            'then' => ['type' => 'course_completed'],
        ]));

        $this->assertSame([['path' => 'if[0].field', 'message' => 'Pole „foo“ událost course.completed nemá.']], $errors);
    }

    public function test_structural_errors_have_paths(): void
    {
        $cases = [
            'when.event' => ['when' => ['event' => 'no.such']],
            'if[0].op' => ['if' => [['field' => 'percent', 'op' => 'like', 'value' => 1]]],
            'if[0].value' => ['if' => [['field' => 'percent', 'op' => 'eq', 'value' => '50']]],
            'then.type' => ['then' => ['type' => 'no_such_type']],
            'then.target_role' => ['then' => ['target_role' => 'teacher']],
            'then.channels[0]' => ['then' => ['channels' => ['push']]],
            'then.delay_minutes' => ['then' => ['delay_minutes' => 10081]],
            'unexpected' => ['unexpected' => true],
        ];

        foreach ($cases as $path => $override) {
            $errors = $this->validator()->errors($this->definition($override));
            $this->assertContains($path, $this->paths($errors), "expected an error at {$path}");
            foreach ($errors as $error) {
                $this->assertNotSame('', $error['message']);
            }
        }
    }

    public function test_operator_and_value_typing(): void
    {
        $numericOnString = $this->validator()->errors([
            'when' => ['event' => 'topic.mastered'],
            'if' => [['field' => 'topic_key', 'op' => 'gt', 'value' => 'a']],
            'then' => ['type' => 'topic_mastered'],
        ]);
        $this->assertSame('if[0].op', $numericOnString[0]['path']);

        $inNeedsList = $this->validator()->errors([
            'when' => ['event' => 'streak.milestone'],
            'if' => [['field' => 'streak_days', 'op' => 'in', 'value' => 7]],
            'then' => ['type' => 'streak_milestone'],
        ]);
        $this->assertSame('if[0].value', $inNeedsList[0]['path']);

        $badEnum = $this->validator()->errors([
            'when' => ['event' => 'activation.daily'],
            'if' => [['field' => 'suggestion', 'op' => 'in', 'value' => ['quiz', 'chess']]],
            'then' => ['type' => 'activation_daily_quiz'],
        ]);
        $this->assertSame('if[0].value[1]', $badEnum[0]['path']);
        $this->assertStringContainsString('quiz, practice, game', $badEnum[0]['message']);
    }

    public function test_target_role_may_narrow_all_but_not_change_a_role(): void
    {
        $narrowed = [
            'when' => ['event' => 'broadcast.sent'],
            'if' => [],
            'then' => ['type' => 'school_important_message', 'target_role' => 'teacher', 'channels' => ['feed']],
        ];
        $this->assertSame([], $this->validator()->errors($narrowed));
    }

    public function test_invalid_json_string_reports_line_and_column(): void
    {
        $json = "{\n  \"when\": {\"event\": \"course.completed\"},\n  \"then\": {\"type\" \"course_completed\"}\n}";

        $errors = $this->validator()->errors($json);

        $this->assertCount(1, $errors);
        $this->assertSame('$', $errors[0]['path']);
        $this->assertSame(3, $errors[0]['line']);
        $this->assertSame(19, $errors[0]['column']);
        $this->assertStringContainsString('řádku 3, sloupci 19', $errors[0]['message']);

        $trailingComma = $this->validator()->errors("{\"when\": {\"event\": \"course.completed\"},}");
        $this->assertSame(1, $trailingComma[0]['line']);
        $this->assertSame(40, $trailingComma[0]['column']);

        $notObject = $this->validator()->errors('[1, 2]');
        $this->assertSame('$', $notObject[0]['path']);
    }

    public function test_activation_guard_requires_an_active_type_and_no_identical_active_twin(): void
    {
        $definition = $this->definition();

        // The seeded system rule course_halfway has exactly this event, type and condition.
        $conflict = $this->validator()->activationErrors($definition);
        $this->assertSame('conflict', $conflict[0]['code']);
        $this->assertSame('$', $conflict[0]['path']);

        // Different conditions — no conflict.
        $other = $this->definition(['if' => [['field' => 'percent', 'op' => 'gte', 'value' => 50]]]);
        $this->assertSame([], $this->validator()->activationErrors($other));

        // The same rule is not its own twin.
        $system = NotificationRule::where('key', 'system.course_halfway')->first();
        $this->assertSame([], $this->validator()->activationErrors($system->definition, $system->id));

        $this->type('course_halfway')->update(['is_active' => false]);
        $inactive = $this->validator()->activationErrors($other);
        $this->assertSame('then.type', $inactive[0]['path']);
    }

    public function test_system_rules_keep_their_event_and_type(): void
    {
        $system = NotificationRule::where('key', 'system.course_halfway')->first();

        $changed = $this->definition(['when' => ['event' => 'course.completed'], 'then' => ['type' => 'course_completed']]);
        $this->assertEqualsCanonicalizing(['when.event', 'then.type'], $this->paths($this->validator()->systemLockErrors($system, $changed)));

        $this->assertSame([], $this->validator()->systemLockErrors($system, $this->definition(['if' => []])));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Engine
    // ─────────────────────────────────────────────────────────────────────────

    public function test_conditions_select_the_matching_rule(): void
    {
        $student = $this->student();
        $engine = app(RuleEngine::class);
        $fields = ['course_id' => 3, 'course_name' => 'Zlomky', 'course_code' => 'ZL1'];

        $engine->evaluate('course.progress_milestone', $fields + ['percent' => 40], [$student->id]);
        $this->assertSame(0, UserNotification::count());

        $outcomes = $engine->evaluate('course.progress_milestone', $fields + ['percent' => 50], [$student->id]);

        $notification = UserNotification::sole();
        $this->assertSame('course_halfway', $notification->type_key);
        $this->assertSame('rule', $notification->source);
        $this->assertSame(NotificationRule::where('key', 'system.course_halfway')->value('id'), $notification->rule_id);
        $this->assertSame('course_halfway:course_milestone:3:50', $notification->dedup_key);
        $this->assertSame('V kurzu Zlomky máš hotovou polovinu. Jen tak dál!', $notification->body);
        $this->assertSame('learning.activity', $notification->target_action);
        $this->assertTrue($outcomes[0]['matched']);

        // Re-running the same event is deduplicated.
        $engine->evaluate('course.progress_milestone', $fields + ['percent' => 50], [$student->id]);
        $this->assertSame(1, UserNotification::count());
    }

    public function test_in_and_range_conditions(): void
    {
        $student = $this->student();
        $engine = app(RuleEngine::class);

        $engine->evaluate('streak.milestone', ['streak_days' => 8], [$student->id]);
        $engine->evaluate('user.inactive', ['days_inactive' => 31], [$student->id]);
        $this->assertSame(0, UserNotification::count());

        $engine->evaluate('streak.milestone', ['streak_days' => 30], [$student->id]);
        $engine->evaluate('user.inactive', ['days_inactive' => 3], [$student->id]);
        $this->assertEqualsCanonicalizing(['streak_milestone', 'inactivity_comeback'], UserNotification::pluck('type_key')->all());

        $this->assertTrue(RuleEngine::compare('int', 'not_in', 5, [7, 30]));
        $this->assertFalse(RuleEngine::compare('int', 'gt', null, 1));
        $this->assertTrue(RuleEngine::compare('string', 'neq', null, 'x'));
    }

    public function test_broadcast_sent_fires_only_the_rule_of_its_type(): void
    {
        $student = $this->student();
        $teacher = $this->teacher();

        app(RuleEngine::class)->evaluate('broadcast.sent', [
            'type_key' => 'maintenance_notice', 'broadcast_id' => 12, 'title' => 'Údržba', 'body' => 'V neděli',
        ], [$student->id, $teacher->id], null, ['feed']);

        $rows = UserNotification::all();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('maintenance_notice', $row->type_key);
            $this->assertSame('broadcast', $row->source);
            $this->assertSame(12, $row->broadcast_id);
            $this->assertSame(['feed'], $row->channels);
            $this->assertSame('Údržba', $row->title);
        }
    }

    public function test_goal_period_label_is_derived(): void
    {
        $student = $this->student();

        app(RuleEngine::class)->evaluate('goal.completed', ['period' => 'weekly', 'xp' => 260, 'target' => 250], [$student->id]);

        $this->assertSame('Máš splněný týdenní cíl: 260 XP. Výborně!', UserNotification::sole()->body);
    }

    public function test_rule_delay_schedules_the_send(): void
    {
        Queue::fake();
        $student = $this->student();
        $rule = NotificationRule::where('key', 'system.topic_mastered')->first();
        $definition = $rule->definition;
        $definition['then']['delay_minutes'] = 30;
        $rule->update(['definition' => $definition]);

        app(RuleEngine::class)->evaluate('topic.mastered', ['topic_key' => 't1', 'topic_name' => 'Zlomky'], [$student->id]);

        $this->assertSame(0, UserNotification::count());
        Queue::assertPushed(DispatchNotificationRequest::class, fn ($job) => $job->request['type_key'] === 'topic_mastered');
    }

    public function test_a_custom_rule_narrowing_the_role(): void
    {
        $student = $this->student();
        $teacher = $this->teacher();

        NotificationRule::create([
            'name' => 'Učitelům při údržbě',
            'is_system' => false,
            'is_active' => true,
            'event_key' => 'broadcast.deadline_approaching',
            'notification_type_id' => $this->type('school_important_message')->id,
            'definition' => [
                'when' => ['event' => 'broadcast.deadline_approaching'],
                'if' => [],
                'then' => ['type' => 'school_important_message', 'target_role' => 'teacher', 'channels' => ['feed']],
            ],
        ]);

        app(RuleEngine::class)->evaluate('broadcast.deadline_approaching', [
            'broadcast_id' => 4, 'title' => 'Výlet', 'deadline' => '10. 10. 2026 12:00', 'hours_left' => 24,
        ], [$student->id, $teacher->id]);

        // The system rule (org_deadline_reminder, all) reaches both; the custom one only the teacher.
        $this->assertSame(2, UserNotification::where('type_key', 'org_deadline_reminder')->count());
        $this->assertSame([$teacher->id], UserNotification::where('type_key', 'school_important_message')->pluck('user_id')->all());
        $this->assertDatabaseHas('notification_events', ['user_id' => $student->id, 'reason' => 'role_mismatch']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Audience (user.* conditions, BR-KSNDPF)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_recipient_conditions_are_validated_against_the_recipient_catalog(): void
    {
        $this->assertSame([], $this->validator()->errors($this->definition([
            'if' => [
                ['field' => 'percent', 'op' => 'eq', 'value' => 50],
                ['field' => 'user.xp', 'op' => 'gte', 'value' => 31],
                ['field' => 'user.xp', 'op' => 'lte', 'value' => 150],
            ],
        ])));

        $unknown = $this->validator()->errors($this->definition([
            'if' => [['field' => 'user.coins', 'op' => 'gte', 'value' => 1]],
        ]));
        $this->assertSame('if[0].field', $unknown[0]['path']);
        $this->assertSame('Vlastnost příjemce „user.coins“ neexistuje. Povolené: user.xp.', $unknown[0]['message']);

        // The recipient catalog types its fields too: XP is a whole number.
        $wrongType = $this->validator()->errors($this->definition([
            'if' => [['field' => 'user.xp', 'op' => 'gte', 'value' => '31']],
        ]));
        $this->assertSame('if[0].value', $wrongType[0]['path']);

        // Even under an event that does not exist — the property means the same.
        $unknownEvent = $this->validator()->errors([
            'when' => ['event' => 'no.such'],
            'if' => [['field' => 'user.xp', 'op' => 'gte', 'value' => 'x']],
            'then' => ['type' => 'course_completed'],
        ]);
        $this->assertEqualsCanonicalizing(['when.event', 'if[0].value'], $this->paths($unknownEvent));
    }

    public function test_recipient_xp_range_picks_the_audience_of_the_event(): void
    {
        // The dashboard's steps as a rule: the middle band only (31–150 XP).
        $newcomer = $this->student();
        $middle = $this->student();
        UserStats::create(['user_id' => $middle->id, 'xp_points' => 120]);
        $veteran = $this->student();
        UserStats::create(['user_id' => $veteran->id, 'xp_points' => 500]);

        $rule = NotificationRule::create([
            'name' => 'Přijímačky pro 31–150 XP',
            'is_system' => false,
            'is_active' => true,
            'event_key' => 'course.completed',
            'notification_type_id' => $this->type('school_important_message')->id,
            'definition' => [
                'when' => ['event' => 'course.completed'],
                'if' => [
                    ['field' => 'user.xp', 'op' => 'gte', 'value' => 31],
                    ['field' => 'user.xp', 'op' => 'lte', 'value' => 150],
                ],
                'then' => ['type' => 'school_important_message', 'channels' => ['feed']],
            ],
        ]);

        $outcomes = app(RuleEngine::class)->evaluate(
            'course.completed',
            ['course_id' => 7, 'course_name' => 'Zlomky', 'course_code' => 'ZL1'],
            [$newcomer->id, $middle->id, $veteran->id],
        );

        // The system rule has no audience conditions, so it reaches all three.
        $this->assertSame(3, UserNotification::where('type_key', 'course_completed')->count());
        $this->assertSame(
            [$middle->id],
            UserNotification::where('type_key', 'school_important_message')->pluck('user_id')->all(),
        );

        $audience = collect($outcomes)->firstWhere('rule_id', $rule->id);
        $this->assertTrue($audience['matched']);
        $this->assertEqualsCanonicalizing([$newcomer->id, $veteran->id], $audience['skipped']);
    }

    public function test_an_account_without_stats_has_zero_xp_not_an_unknown_one(): void
    {
        $newcomer = $this->student();
        $this->assertDatabaseMissing('user_stats', ['user_id' => $newcomer->id]);

        NotificationRule::create([
            'name' => 'Úvodní kurz do 30 XP',
            'is_system' => false,
            'is_active' => true,
            'event_key' => 'course.completed',
            'notification_type_id' => $this->type('school_important_message')->id,
            'definition' => [
                'when' => ['event' => 'course.completed'],
                'if' => [['field' => 'user.xp', 'op' => 'lte', 'value' => 30]],
                'then' => ['type' => 'school_important_message', 'channels' => ['feed']],
            ],
        ]);

        app(RuleEngine::class)->evaluate(
            'course.completed',
            ['course_id' => 7, 'course_name' => 'Zlomky', 'course_code' => 'ZL1'],
            [$newcomer->id],
        );

        $this->assertSame(1, UserNotification::where('type_key', 'school_important_message')->count());
    }

    public function test_recipient_conditions_do_not_decide_whether_the_rule_runs(): void
    {
        $student = $this->student();
        UserStats::create(['user_id' => $student->id, 'xp_points' => 10]);
        $engine = app(RuleEngine::class);

        // The event side alone: a caller asking "does this event fit the
        // rule?" (the broadcast editor does) must not be told no because the
        // event carries no XP of its own.
        $event = $engine->evaluateConditions('course.progress_milestone', [
            ['field' => 'percent', 'op' => 'eq', 'value' => 50],
            ['field' => 'user.xp', 'op' => 'gte', 'value' => 31],
        ], ['course_id' => 1, 'percent' => 50]);
        $this->assertTrue($event['matched']);
        $this->assertSame(['percent'], array_column($event['conditions'], 'field'));

        $recipient = $engine->evaluateRecipientConditions(
            [['field' => 'user.xp', 'op' => 'gte', 'value' => 31]],
            $student->id,
        );
        $this->assertFalse($recipient['matched']);
        $this->assertSame(10, $recipient['conditions'][0]['actual']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // emit()
    // ─────────────────────────────────────────────────────────────────────────

    public function test_emit_queues_valid_events_and_drops_invalid_ones(): void
    {
        Queue::fake();
        $student = $this->student();

        $this->assertTrue(NotificationEvents::emit('course.completed', ['course_id' => '5', 'course_name' => 'Kurz'], [$student->id, $student->id]));
        Queue::assertPushedOn('notifications', EvaluateNotificationEvent::class, function (EvaluateNotificationEvent $job) use ($student) {
            return $job->fields['course_id'] === 5
                && $job->userIds === [$student->id]
                && $job->dedupKey === 'course_completed:5';
        });

        $this->assertFalse(NotificationEvents::emit('no.such.event', [], [$student->id]));
        $this->assertFalse(NotificationEvents::emit('course.completed', ['course_name' => 'bez id'], [$student->id]));
        $this->assertFalse(NotificationEvents::emit('goal.completed', ['period' => 'monthly', 'xp' => 1, 'target' => 1], [$student->id]));
        $this->assertFalse(NotificationEvents::emit('course.completed', ['course_id' => 5], []));
        Queue::assertPushed(EvaluateNotificationEvent::class, 1);
    }

    public function test_emit_runs_end_to_end_on_the_sync_queue(): void
    {
        $student = $this->student();

        NotificationEvents::emit('course.completed', ['course_id' => 9, 'course_name' => 'Fyzika', 'course_code' => 'FYZ9'], [$student->id]);

        $notification = UserNotification::sole();
        $this->assertSame('course_completed', $notification->type_key);
        $this->assertSame('/course/FYZ9', $notification->deep_link);
    }

    public function test_dedup_key_templates(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-07 23:30:00', 'UTC'));

        // {date} is the default timezone's date: already the 8th in Prague.
        $this->assertSame('activation:quiz:2026-10-08', EventCatalog::dedupKey('activation.daily', ['suggestion' => 'quiz']));
        $this->assertSame('broadcast:3', EventCatalog::dedupKey('broadcast.sent', ['broadcast_id' => 3]));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Target actions (NOTIF-8 F)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_target_action_marks_the_latest_clicked_notification_within_24_hours(): void
    {
        $student = $this->student();
        $tracker = app(StatusTracker::class);

        $old = $this->sendTo('activation_daily_quiz', [$student], [], ['dedupKey' => 'old'])->delivered[0];
        $tracker->transition($old, 'clicked');
        $this->travel(2)->days();

        // Clicked long ago — does not count.
        $this->assertFalse(NotificationTargetActions::record($student->id, 'quiz.completed'));

        $recent = $this->sendTo('activation_daily_quiz', [$student], [], ['dedupKey' => 'new'])->delivered[0];
        $this->assertFalse(NotificationTargetActions::record($student->id, 'quiz.completed'), 'not clicked yet');

        $tracker->transition($recent, 'app_opened');
        $this->assertFalse(NotificationTargetActions::record($student->id, 'practice.reviewed'), 'other action');
        $this->assertTrue(NotificationTargetActions::record($student->id, 'quiz.completed'));
        $this->assertFalse(NotificationTargetActions::record($student->id, 'quiz.completed'), 'already marked');

        $this->assertNotNull($recent->fresh()->target_action_at);
        $this->assertNull($old->fresh()->target_action_at);
        $this->assertSame(1, NotificationEvent::where('event', 'target_action')->count());
        $this->assertSame('app_opened', $recent->fresh()->status);
    }
}
