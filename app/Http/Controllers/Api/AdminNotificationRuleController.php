<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\FormatsNotificationAdmin;
use App\Http\Controllers\Controller;
use App\Models\NotificationRule;
use App\Models\NotificationType;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\EventCatalog;
use App\Notifications\NotificationService;
use App\Notifications\RecipientCatalog;
use App\Notifications\RuleDefinitionValidator;
use App\Notifications\RuleEngine;
use App\Notifications\TemplateRenderer;
use App\Services\AuditLogger;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Notification rules in the admin (NOTIF-7, NOTIF-9, spec §3.2, §5.2 "Rules").
 *
 * The definition is JSON the admin writes by hand; it is validated on every
 * save, with Czech `{path, message}` errors under errors.definition. An
 * active rule must also pass the activation guard (active type, no active
 * twin). System rules keep their event and type and cannot be deleted.
 * Every write is audited as notification_rule.created / updated / deleted /
 * activated / deactivated.
 */
class AdminNotificationRuleController extends Controller
{
    use FormatsNotificationAdmin;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RuleDefinitionValidator $validator,
    ) {}

    /**
     * GET /api/admin/notification-rules?event=&type=&active=
     * `type` is a type key, `active` 1 / 0.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event' => 'sometimes|nullable|string|max:64',
            'type' => 'sometimes|nullable|string|max:64',
            'active' => 'sometimes|nullable|boolean',
        ]);

        $query = NotificationRule::with('type:id,key,name')->orderByDesc('is_system')->orderBy('id');

        if (!empty($validated['event'])) {
            $query->where('event_key', $validated['event']);
        }

        if (!empty($validated['type'])) {
            $typeId = NotificationType::where('key', $validated['type'])->value('id');
            $query->where('notification_type_id', $typeId ?? 0);
        }

        if (isset($validated['active']) && $validated['active'] !== '') {
            $query->where('is_active', filter_var($validated['active'], FILTER_VALIDATE_BOOLEAN));
        }

        $rules = $query->get();

        return response()->json([
            'data' => $rules->map(fn (NotificationRule $rule) => $this->ruleJson($rule))->values(),
            'meta' => ['total' => $rules->count()],
        ]);
    }

    /**
     * GET /api/admin/notification-rules/{id}
     */
    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->ruleJson(NotificationRule::with('type:id,key,name')->findOrFail($id))]);
    }

    /**
     * POST /api/admin/notification-rules — a custom rule.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'sometimes|nullable|string|max:2000',
            'is_active' => 'sometimes|boolean',
            'definition' => 'required',
        ]);

        $active = (bool) ($validated['is_active'] ?? false);
        $definition = $this->checkedDefinition($validated['definition'], $active, null, null);

        $rule = new NotificationRule([
            'key' => null,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_system' => false,
            'is_active' => $active,
            'definition' => $definition,
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);
        $rule->syncDenormalised();
        $rule->save();

        $this->audit->log(
            'notification_rule.created',
            $rule,
            diff: AuditLogger::created($rule, ['name', 'description', 'is_active', 'event_key', 'notification_type_id', 'definition']),
        );

        return response()->json([
            'message' => 'Pravidlo vytvořeno.',
            'data' => $this->ruleJson($rule->load('type:id,key,name')),
        ], 201);
    }

    /**
     * PUT /api/admin/notification-rules/{id} — same fields as store, each
     * optional. A system rule keeps its event and type.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $rule = NotificationRule::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string|max:2000',
            'is_active' => 'sometimes|boolean',
            'definition' => 'sometimes|required',
        ]);

        $active = array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : (bool) $rule->is_active;
        $definition = $this->checkedDefinition(
            array_key_exists('definition', $validated) ? $validated['definition'] : $rule->definition,
            $active,
            $rule,
            $rule->id,
        );

        $wasActive = (bool) $rule->is_active;

        $rule->fill(array_intersect_key($validated, array_flip(['name', 'description'])));
        $rule->definition = $definition;
        $rule->is_active = $active;
        $rule->updated_by = $request->user()?->id;
        $rule->syncDenormalised();
        $rule->save();

        $diff = AuditLogger::changes($rule);
        unset($diff['updated_by'], $diff['is_active']);

        if ($diff !== []) {
            $this->audit->log('notification_rule.updated', $rule, diff: $diff, meta: ['key' => $rule->key]);
        }

        if ($wasActive !== $active) {
            $this->audit->log(
                $active ? 'notification_rule.activated' : 'notification_rule.deactivated',
                $rule,
                diff: ['is_active' => ['from' => $wasActive, 'to' => $active]],
                meta: ['key' => $rule->key],
            );
        }

        return response()->json([
            'message' => 'Pravidlo uloženo.',
            'data' => $this->ruleJson($rule->load('type:id,key,name')),
        ]);
    }

    /**
     * DELETE /api/admin/notification-rules/{id} — custom rules only.
     */
    public function destroy(int $id): JsonResponse
    {
        $rule = NotificationRule::findOrFail($id);

        if ($rule->is_system) {
            return response()->json([
                'message' => 'Systémové pravidlo nelze smazat, jen vypnout.',
                'errors' => ['rule' => ['Systémové pravidlo nelze smazat, jen vypnout.']],
            ], 422);
        }

        $this->audit->log('notification_rule.deleted', $rule, meta: [
            'name' => $rule->name,
            'event_key' => $rule->event_key,
            'definition' => $rule->definition,
        ]);

        $rule->delete();

        return response()->json(['message' => 'Pravidlo smazáno.', 'data' => true]);
    }

    /**
     * POST /api/admin/notification-rules/validate — {valid, errors}.
     */
    public function validateDefinition(Request $request): JsonResponse
    {
        $request->validate(['definition' => 'present']);

        $errors = $this->validator->errors($request->input('definition'));

        return response()->json([
            'data' => [
                'valid' => $errors === [],
                'errors' => $errors,
            ],
        ]);
    }

    /**
     * POST /api/admin/notification-rules/{id}/test — evaluate the rule's
     * conditions against sample event fields and render its type; with
     * send=true and user_id, a real test send (source=test) when it matches.
     *
     * The rule's user.* conditions are about the recipient, so they are
     * judged only once a user is given; without one the answer covers the
     * event alone and `recipient_pending` says so.
     */
    public function test(Request $request, RuleEngine $engine, NotificationService $service, TemplateRenderer $renderer, int $id): JsonResponse
    {
        $rule = NotificationRule::with('type')->findOrFail($id);

        $validated = $request->validate([
            'fields' => 'present|array',
            'user_id' => 'sometimes|nullable|integer|exists:users,id',
            'send' => 'sometimes|boolean',
        ]);

        $eventKey = (string) $rule->eventKey();
        [$fields, $fieldErrors] = EventCatalog::validateFields($eventKey, (array) $validated['fields']);

        if ($fieldErrors !== []) {
            throw ValidationException::withMessages([
                'fields' => array_map(fn (string $e) => 'Neplatná data události: ' . $e . '.', $fieldErrors),
            ]);
        }

        $send = (bool) ($validated['send'] ?? false);
        $userId = isset($validated['user_id']) ? (int) $validated['user_id'] : null;

        if ($send && $userId === null) {
            throw ValidationException::withMessages([
                'user_id' => ['Pro testovací odeslání vyberte uživatele.'],
            ]);
        }

        [$eventConditions, $recipientConditions] = RecipientCatalog::splitConditions($rule->conditions());

        $evaluation = $engine->evaluateConditions($eventKey, $eventConditions, $fields);

        // The rule's user.* conditions are about the recipient, so they can
        // only be answered once one is picked; until then the test reports
        // the event side and says the rest is still open.
        $recipientPending = $recipientConditions !== [] && $userId === null;
        if ($recipientConditions !== [] && $userId !== null) {
            $recipient = $engine->evaluateRecipientConditions($recipientConditions, $userId);
            $evaluation['matched'] = $evaluation['matched'] && $recipient['matched'];
            $evaluation['conditions'] = array_merge($evaluation['conditions'], $recipient['conditions']);
        }

        $rendered = null;
        if ($rule->type !== null) {
            $payload = RuleEngine::payloadFor($eventKey, $fields);
            if ($rule->type->declaresVariable('name') && !isset($payload['name'])) {
                $user = $userId !== null ? User::find($userId) : null;
                $payload['name'] = $user !== null
                    ? NotificationService::firstName($user)
                    : ($rule->type->variableMap()['name']['sample'] ?? null);
            }

            $full = $renderer->renderAll($rule->type, $payload);
            $limits = TemplateRenderer::limits();
            $rendered = [
                'push_title' => TemplateRenderer::truncate($full['push_title'], $limits['push_title']),
                'push_body' => TemplateRenderer::truncate($full['push_body'], $limits['push_body']),
                'feed_title' => TemplateRenderer::truncate($full['feed_title'], $limits['feed_title']),
                'feed_body' => TemplateRenderer::truncate($full['feed_body'], $limits['feed_body']),
                'deep_link' => $full['deep_link'],
            ];
        }

        $data = [
            'matched' => $evaluation['matched'],
            'conditions' => $evaluation['conditions'],
            'recipient_pending' => $recipientPending,
            'rendered' => $rendered,
            'notification_id' => null,
        ];
        $message = null;

        if ($send) {
            if (!$evaluation['matched']) {
                $message = 'Podmínky pravidla nejsou splněny, testovací notifikace se neodeslala.';
            } elseif ($rule->type === null) {
                $message = 'Typ pravidla neexistuje, testovací notifikace se neodeslala.';
            } else {
                $result = $service->send($engine->requestFor(
                    $rule,
                    $eventKey,
                    $fields,
                    [$userId],
                    EventCatalog::dedupKey($eventKey, $fields),
                    UserNotification::SOURCE_TEST,
                ));

                $data['notification_id'] = $result->delivered[0]->uuid ?? null;
                $data['decisions'] = $this->decisionRows($result);
            }
        }

        return response()->json(array_filter([
            'data' => $data,
            'message' => $message,
        ], fn ($v) => $v !== null));
    }

    /**
     * GET /api/admin/notification-rules/{id}/audit
     */
    public function audit(int $id): JsonResponse
    {
        // A deleted rule's history stays readable.
        return $this->auditFor('notification_rule', $id);
    }

    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * The parsed definition, or a 422 with errors.definition = [{path,
     * message}]: structure always, system lock for system rules, the
     * activation guard when the rule is (to be) active.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function checkedDefinition(mixed $definition, bool $active, ?NotificationRule $rule, ?int $ignoreRuleId): array
    {
        ['definition' => $parsed, 'errors' => $errors] = $this->validator->validate($definition);

        if ($parsed !== null && $errors === [] && $rule !== null) {
            $errors = $this->validator->systemLockErrors($rule, $parsed);
        }

        if ($parsed !== null && $errors === [] && $active) {
            $errors = $this->validator->activationErrors($parsed, $ignoreRuleId);
        }

        if ($parsed === null || $errors !== []) {
            $errors = $errors ?: [['path' => '$', 'message' => 'Neplatná definice pravidla.']];

            // Laravel's 422 shape, but each error is a {path, message} object
            // rather than a string (spec §5).
            throw new HttpResponseException(response()->json([
                'message' => $errors[0]['message'],
                'errors' => ['definition' => $errors],
            ], 422));
        }

        return $parsed;
    }
}
