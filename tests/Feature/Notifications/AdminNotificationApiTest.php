<?php

namespace Tests\Feature\Notifications;

use App\Models\AuditLog;
use App\Models\NotificationRule;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\UserStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The notification admin API (spec §5.2): types, rules, catalog and queue
 * health — admin-only, validated with the contract's error shapes, and
 * audited.
 */
class AdminNotificationApiTest extends TestCase
{
    use NotificationTestHelpers;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalog();
        $this->fakePush(false);

        $this->admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($this->admin);
    }

    private function ruleId(string $typeKey): int
    {
        return (int) NotificationRule::where('key', 'system.' . $typeKey)->value('id');
    }

    private function customDefinition(array $overrides = []): array
    {
        return array_replace_recursive([
            'when' => ['event' => 'course.progress_milestone'],
            'if' => [['field' => 'percent', 'op' => 'gte', 'value' => 50]],
            'then' => ['type' => 'course_halfway', 'target_role' => 'student', 'channels' => ['feed'], 'delay_minutes' => 0],
        ], $overrides);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Access
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_teacher_gets_403_everywhere(): void
    {
        Sanctum::actingAs($this->teacher());

        foreach ([
            '/api/admin/notification-types',
            '/api/admin/notification-rules',
            '/api/admin/notifications/meta',
            '/api/admin/notifications/queue-health',
            '/api/admin/notification-history',
            '/api/admin/notification-history/export',
            '/api/admin/notification-broadcasts',
        ] as $path) {
            $this->getJson($path)->assertForbidden();
        }

        $this->putJson('/api/admin/notification-types/1', ['name' => 'X'])->assertForbidden();
        $this->postJson('/api/admin/notification-rules', ['name' => 'X', 'definition' => []])->assertForbidden();
    }

    public function test_the_admin_key_works_without_a_user(): void
    {
        $this->app['auth']->forgetGuards();
        config(['admin.api_key' => 'secret-key']);

        $this->withHeader('X-Admin-Key', 'secret-key')
            ->getJson('/api/admin/notification-types')
            ->assertOk()
            ->assertJsonCount(15, 'data');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Types
    // ─────────────────────────────────────────────────────────────────────────

    public function test_type_list_has_rules_count_and_last_non_test_send(): void
    {
        $student = $this->student();
        $this->sendTo('course_completed', [$student], ['course_id' => 1, 'course_name' => 'Zlomky']);
        $this->sendTo('streak_milestone', [$student], ['streak_days' => 7], ['source' => 'test']);

        $types = collect($this->getJson('/api/admin/notification-types')->assertOk()->json('data'))->keyBy('key');

        $this->assertCount(15, $types);
        $this->assertSame(1, $types['course_completed']['rules_count']);
        $this->assertNotNull($types['course_completed']['last_sent_at']);
        $this->assertNull($types['streak_milestone']['last_sent_at'], 'A test send is not a send.');
        $this->assertSame(['push', 'feed'], $types['school_important_message']['channels']);
        $this->assertSame('provoz', $types['school_important_message']['category']);

        $id = $types['course_completed']['id'];
        $this->getJson("/api/admin/notification-types/{$id}")
            ->assertOk()
            ->assertJsonPath('data.key', 'course_completed')
            ->assertJsonPath('data.rules_count', 1);
    }

    public function test_type_update_saves_and_audits_the_diff(): void
    {
        $type = $this->type('course_completed');

        $this->putJson("/api/admin/notification-types/{$type->id}", [
            'name' => 'Kurz hotový',
            'feed_title' => 'Hotovo: {{course_name}}',
            'dedup_window_hours' => 100,
            'frequency_limit' => 2,
            'frequency_period_hours' => 24,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Kurz hotový')
            ->assertJsonPath('data.feed_title', 'Hotovo: {{course_name}}')
            ->assertJsonPath('data.frequency_limit', 2);

        $log = AuditLog::where('action', 'notification_type.updated')->sole();
        $this->assertSame($type->id, $log->target_id);
        $this->assertSame('notification_type', $log->target_type);
        $this->assertSame(['from' => 'Kurz dokončen', 'to' => 'Hotovo: {{course_name}}'], $log->diff['feed_title']);
        $this->assertSame($this->admin->id, $log->actor_id);

        $audit = $this->getJson("/api/admin/notification-types/{$type->id}/audit")->assertOk()->json('data');
        $this->assertCount(1, $audit);
        $this->assertSame('notification_type.updated', $audit[0]['action']);
    }

    public function test_type_template_errors_come_back_as_errors_templates(): void
    {
        // A push type: its push texts are length-checked too.
        $type = $this->type('inactivity_comeback');

        $response = $this->putJson("/api/admin/notification-types/{$type->id}", [
            'feed_title' => 'Vrať se {{foo}}',
            'push_title' => str_repeat('Dlouhý nadpis ', 10),
        ])->assertStatus(422);

        $errors = collect($response->json('errors.templates'));
        $this->assertSame('feed_title', $errors->firstWhere('code', 'unknown_variable')['template']);
        $this->assertSame('push_title', $errors->firstWhere('code', 'too_long')['template']);
        $this->assertSame('Vrať se k učení', $type->fresh()->feed_title);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_variable_keys_are_fixed_but_their_details_editable(): void
    {
        $type = $this->type('course_completed');
        $variables = $type->variables;

        $renamed = $variables;
        $renamed[0]['key'] = 'something_else';
        $this->putJson("/api/admin/notification-types/{$type->id}", ['variables' => $renamed])
            ->assertStatus(422)->assertJsonValidationErrors(['variables']);

        $edited = $variables;
        $index = array_search('course_name', array_column($variables, 'key'), true);
        $edited[$index]['fallback'] = 'tvůj kurz';
        $edited[$index]['label'] = 'Jméno kurzu';
        $this->putJson("/api/admin/notification-types/{$type->id}", ['variables' => $edited])->assertOk();

        $saved = collect($type->fresh()->variables)->keyBy('key');
        $this->assertSame('tvůj kurz', $saved['course_name']['fallback']);
        $this->assertSame('Jméno kurzu', $saved['course_name']['label']);
    }

    public function test_a_frequency_limit_needs_a_period(): void
    {
        $type = $this->type('course_completed');

        $this->putJson("/api/admin/notification-types/{$type->id}", ['frequency_limit' => 3])
            ->assertStatus(422)->assertJsonValidationErrors(['frequency_period_hours']);
    }

    public function test_type_preview_renders_without_saving(): void
    {
        $type = $this->type('course_completed');

        $data = $this->postJson("/api/admin/notification-types/{$type->id}/preview", [
            'templates' => ['feed_title' => 'Hotovo: {{course_name}}'],
            'sample' => ['course_name' => 'Fyzika'],
        ])->assertOk()->json('data');

        $this->assertSame('Hotovo: Fyzika', $data['rendered']['feed_title']);
        $this->assertSame(mb_strlen('Hotovo: Fyzika'), $data['lengths']['feed_title']);
        $this->assertSame(65, $data['limits']['push_title']);
        $this->assertSame([], $data['errors']);
        $this->assertSame('Kurz dokončen', $type->fresh()->feed_title);
    }

    public function test_type_test_send_goes_through_the_service_as_a_test(): void
    {
        $student = $this->student(['name' => 'Tereza Nováková']);
        $type = $this->type('learning_reminder');

        $data = $this->postJson("/api/admin/notification-types/{$type->id}/test", [
            'user_id' => $student->id,
        ])->assertOk()->json('data');

        $notification = UserNotification::where('uuid', $data['notification_id'])->sole();
        $this->assertTrue($notification->is_test);
        $this->assertSame('test', $notification->source);
        $this->assertStringStartsWith('Tereza,', $notification->push_body);
        $this->assertSame('deliver', $data['decisions'][0]['decision']);
        $this->assertSame('decision.deliver', $data['decisions'][0]['event']);
        // The push part of the decision, as its own row: no FCM here.
        $this->assertSame('decision.suppress', $data['decisions'][1]['event']);
        $this->assertSame('push_unavailable', $data['decisions'][1]['reason']);

        $this->postJson("/api/admin/notification-types/{$type->id}/test", ['user_id' => 999999])
            ->assertStatus(422)->assertJsonValidationErrors(['user_id']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Rules
    // ─────────────────────────────────────────────────────────────────────────

    public function test_rule_list_filters_by_event_type_key_and_active(): void
    {
        $this->assertCount(15, $this->getJson('/api/admin/notification-rules')->json('data'));
        $this->assertCount(3, $this->getJson('/api/admin/notification-rules?event=broadcast.sent')->json('data'));

        $byType = $this->getJson('/api/admin/notification-rules?type=course_halfway')->assertOk()->json('data');
        $this->assertCount(1, $byType);
        $this->assertSame(['id' => $this->type('course_halfway')->id, 'key' => 'course_halfway', 'name' => 'Polovina kurzu'], $byType[0]['type']);

        $this->assertCount(0, $this->getJson('/api/admin/notification-rules?active=0')->json('data'));
        NotificationRule::whereKey($this->ruleId('topic_mastered'))->update(['is_active' => false]);
        $this->assertCount(1, $this->getJson('/api/admin/notification-rules?active=0')->json('data'));
        $this->assertCount(14, $this->getJson('/api/admin/notification-rules?active=1')->json('data'));
    }

    public function test_a_custom_rule_is_created_with_its_denormalised_columns_and_audited(): void
    {
        $response = $this->postJson('/api/admin/notification-rules', [
            'name' => 'Polovina a víc',
            'is_active' => true,
            'definition' => $this->customDefinition(),
        ])->assertCreated();

        $rule = NotificationRule::findOrFail($response->json('data.id'));
        $this->assertFalse($rule->is_system);
        $this->assertNull($rule->key);
        $this->assertSame('course.progress_milestone', $rule->event_key);
        $this->assertSame($this->type('course_halfway')->id, $rule->notification_type_id);
        $this->assertSame($this->admin->id, $rule->created_by);
        $this->assertTrue(AuditLog::where('action', 'notification_rule.created')->where('target_id', $rule->id)->exists());
    }

    public function test_rule_definition_errors_have_paths_and_json_syntax_errors_a_position(): void
    {
        $response = $this->postJson('/api/admin/notification-rules', [
            'name' => 'Rozbité',
            'definition' => "{\n  \"when\": {\"event\": \"course.completed\"},\n  \"if\": [}\n}",
        ])->assertStatus(422);

        $error = $response->json('errors.definition.0');
        $this->assertSame('$', $error['path']);
        $this->assertSame(3, $error['line']);
        $this->assertArrayHasKey('column', $error);

        $response = $this->postJson('/api/admin/notification-rules', [
            'name' => 'Neznámé pole',
            'definition' => $this->customDefinition(['if' => [['field' => 'foo', 'op' => 'eq', 'value' => 1]]]),
        ])->assertStatus(422);

        $this->assertSame('if[0].field', $response->json('errors.definition.0.path'));
        $this->assertSame('Pole „foo“ událost course.progress_milestone nemá.', $response->json('errors.definition.0.message'));
        $this->assertSame(15, NotificationRule::count());
    }

    public function test_an_identical_active_twin_is_a_conflict_but_an_inactive_one_is_fine(): void
    {
        $twin = $this->customDefinition(['if' => [['field' => 'percent', 'op' => 'eq', 'value' => 50]]]);

        $response = $this->postJson('/api/admin/notification-rules', [
            'name' => 'Dvojče', 'is_active' => true, 'definition' => $twin,
        ])->assertStatus(422);
        $this->assertSame('conflict', $response->json('errors.definition.0.code'));

        $id = $this->postJson('/api/admin/notification-rules', [
            'name' => 'Dvojče', 'is_active' => false, 'definition' => $twin,
        ])->assertCreated()->json('data.id');

        // Activating it later hits the same guard.
        $this->putJson("/api/admin/notification-rules/{$id}", ['is_active' => true])
            ->assertStatus(422)
            ->assertJsonPath('errors.definition.0.code', 'conflict');
    }

    public function test_a_rule_on_an_inactive_type_cannot_be_active(): void
    {
        $this->type('course_halfway')->update(['is_active' => false]);

        $this->postJson('/api/admin/notification-rules', [
            'name' => 'Na vypnutý typ', 'is_active' => true, 'definition' => $this->customDefinition(),
        ])->assertStatus(422)->assertJsonPath('errors.definition.0.code', 'type_inactive');
    }

    public function test_a_system_rule_keeps_its_event_and_type_and_cannot_be_deleted(): void
    {
        $id = $this->ruleId('course_halfway');
        $definition = NotificationRule::find($id)->definition;

        $changed = $definition;
        $changed['when']['event'] = 'course.completed';
        $changed['if'] = [];
        $this->putJson("/api/admin/notification-rules/{$id}", ['definition' => $changed])
            ->assertStatus(422)
            ->assertJsonPath('errors.definition.0.path', 'when.event');

        $changed = $definition;
        $changed['if'] = [['field' => 'percent', 'op' => 'gte', 'value' => 60]];
        $this->putJson("/api/admin/notification-rules/{$id}", ['definition' => $changed])
            ->assertOk()
            ->assertJsonPath('data.definition.if.0.value', 60);

        $this->deleteJson("/api/admin/notification-rules/{$id}")->assertStatus(422);
        $this->assertNotNull(NotificationRule::find($id));
        $this->assertTrue(AuditLog::where('action', 'notification_rule.updated')->where('target_id', $id)->exists());
    }

    public function test_activation_changes_are_audited_and_custom_rules_deleted(): void
    {
        $id = $this->ruleId('topic_mastered');

        $this->putJson("/api/admin/notification-rules/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->putJson("/api/admin/notification-rules/{$id}", ['is_active' => true])->assertOk();

        $this->assertSame(
            ['notification_rule.deactivated', 'notification_rule.activated'],
            AuditLog::where('target_id', $id)->where('target_type', 'notification_rule')->orderBy('id')->pluck('action')->all(),
        );

        $custom = $this->postJson('/api/admin/notification-rules', [
            'name' => 'Vlastní', 'is_active' => false, 'definition' => $this->customDefinition(),
        ])->json('data.id');
        $this->deleteJson("/api/admin/notification-rules/{$custom}")->assertOk();
        $this->assertNull(NotificationRule::find($custom));

        $audit = $this->getJson("/api/admin/notification-rules/{$custom}/audit")->assertOk()->json('data');
        $this->assertSame(['notification_rule.deleted', 'notification_rule.created'], array_column($audit, 'action'));
    }

    public function test_validate_endpoint_reports_without_saving(): void
    {
        $this->postJson('/api/admin/notification-rules/validate', ['definition' => $this->customDefinition()])
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.errors', []);

        $data = $this->postJson('/api/admin/notification-rules/validate', [
            'definition' => $this->customDefinition(['then' => ['delay_minutes' => 99999]]),
        ])->assertOk()->json('data');

        $this->assertFalse($data['valid']);
        $this->assertSame('then.delay_minutes', $data['errors'][0]['path']);
    }

    public function test_rule_test_traces_conditions_renders_and_optionally_sends(): void
    {
        $id = $this->ruleId('course_halfway');
        $student = $this->student();
        $fields = ['course_id' => 7, 'course_name' => 'Zlomky', 'course_code' => 'ABC123', 'percent' => 50];

        $data = $this->postJson("/api/admin/notification-rules/{$id}/test", ['fields' => $fields])->assertOk()->json('data');
        $this->assertTrue($data['matched']);
        $this->assertSame([['field' => 'percent', 'op' => 'eq', 'value' => 50, 'actual' => 50, 'passed' => true]], $data['conditions']);
        $this->assertSame('V kurzu Zlomky máš hotovou polovinu. Jen tak dál!', $data['rendered']['feed_body']);
        $this->assertSame('/course/ABC123', $data['rendered']['deep_link']);
        $this->assertNull($data['notification_id']);
        $this->assertSame(0, UserNotification::count());

        $miss = $this->postJson("/api/admin/notification-rules/{$id}/test", [
            'fields' => ['percent' => 40] + $fields, 'user_id' => $student->id, 'send' => true,
        ])->assertOk();
        $this->assertFalse($miss->json('data.matched'));
        $this->assertFalse($miss->json('data.conditions.0.passed'));
        $this->assertNotNull($miss->json('message'));
        $this->assertSame(0, UserNotification::count());

        $sent = $this->postJson("/api/admin/notification-rules/{$id}/test", [
            'fields' => $fields, 'user_id' => $student->id, 'send' => true,
        ])->assertOk()->json('data');
        $notification = UserNotification::where('uuid', $sent['notification_id'])->sole();
        $this->assertTrue($notification->is_test);
        $this->assertSame($id, $notification->rule_id);

        $this->postJson("/api/admin/notification-rules/{$id}/test", ['fields' => $fields, 'send' => true])
            ->assertStatus(422)->assertJsonValidationErrors(['user_id']);
        $this->postJson("/api/admin/notification-rules/{$id}/test", ['fields' => ['course_name' => 'bez id']])
            ->assertStatus(422)->assertJsonValidationErrors(['fields']);
    }

    public function test_rule_test_judges_recipient_conditions_only_once_a_user_is_picked(): void
    {
        $id = $this->ruleId('course_halfway');
        $rule = NotificationRule::find($id);
        $definition = $rule->definition;
        $definition['if'][] = ['field' => 'user.xp', 'op' => 'lte', 'value' => 30];
        $rule->update(['definition' => $definition]);

        $fields = ['course_id' => 7, 'course_name' => 'Zlomky', 'course_code' => 'ABC123', 'percent' => 50];
        $newcomer = $this->student();
        $veteran = $this->student();
        UserStats::create(['user_id' => $veteran->id, 'xp_points' => 500]);

        // No user yet: the event side is answered, the XP side is still open.
        $open = $this->postJson("/api/admin/notification-rules/{$id}/test", ['fields' => $fields])
            ->assertOk()->json('data');
        $this->assertTrue($open['matched']);
        $this->assertTrue($open['recipient_pending']);
        $this->assertSame(['percent'], array_column($open['conditions'], 'field'));

        $hit = $this->postJson("/api/admin/notification-rules/{$id}/test", [
            'fields' => $fields, 'user_id' => $newcomer->id,
        ])->assertOk()->json('data');
        $this->assertTrue($hit['matched']);
        $this->assertFalse($hit['recipient_pending']);
        $this->assertSame(
            ['field' => 'user.xp', 'op' => 'lte', 'value' => 30, 'actual' => 0, 'passed' => true],
            $hit['conditions'][1],
        );

        // Out of the band: the test send is refused, as for any unmet condition.
        $miss = $this->postJson("/api/admin/notification-rules/{$id}/test", [
            'fields' => $fields, 'user_id' => $veteran->id, 'send' => true,
        ])->assertOk();
        $this->assertFalse($miss->json('data.matched'));
        $this->assertSame(500, $miss->json('data.conditions.1.actual'));
        $this->assertNotNull($miss->json('message'));
        $this->assertSame(0, UserNotification::count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Catalog & queue health
    // ─────────────────────────────────────────────────────────────────────────

    public function test_meta_lists_the_catalog_enums_limits_and_defaults(): void
    {
        $data = $this->getJson('/api/admin/notifications/meta')->assertOk()->json('data');

        $this->assertCount(11, $data['events']);
        $events = collect($data['events'])->keyBy('key');
        $this->assertSame('course_completed:{course_id}', $events['course.completed']['dedup']);
        $this->assertContains('percent', array_column($events['course.progress_milestone']['fields'], 'name'));

        $this->assertSame(
            [['name' => 'user.xp', 'type' => 'int', 'label' => 'Celkové XP příjemce', 'description' => 'Všechna dosud získaná XP, stejné číslo jako na profilu žáka. Účet bez XP má 0.', 'required' => true]],
            $data['recipient_fields'],
        );

        $this->assertSame(['int'], $data['operators']['gt']['types']);
        $this->assertTrue($data['operators']['in']['list']);
        $this->assertSame(['provoz', 'navyk', 'pokrok', 'aktivace'], array_column($data['categories'], 'key'));
        $this->assertSame(['student', 'teacher', 'all'], array_column($data['roles'], 'key'));
        $this->assertSame(['push', 'feed'], array_column($data['channels'], 'key'));
        $this->assertContains('/course/{{course_code}}', array_column($data['deep_links'], 'path'));
        $this->assertSame(65, $data['limits']['push_title']);
        $this->assertSame(1000, $data['limits']['feed_body']);
        $this->assertSame(200, $data['defaults']['broadcast']['wide_threshold']);
        $this->assertSame('Europe/Prague', $data['defaults']['timezone']);
    }

    public function test_queue_health_reports_every_figure(): void
    {
        $student = $this->student();
        $notification = $this->sendTo('school_important_message', [$student], ['title' => 'A', 'body' => 'B'])->delivered[0];
        $notification->forceFill(['push_status' => UserNotification::PUSH_DEFERRED])->save();

        $data = $this->getJson('/api/admin/notifications/queue-health')->assertOk()->json('data');

        $this->assertSame(['pending', 'pending_notifications', 'failed', 'oldest_pending_seconds', 'deferred_push'], array_keys($data));
        $this->assertSame(1, $data['deferred_push']);
        $this->assertSame(0, $data['failed']);
    }
}
