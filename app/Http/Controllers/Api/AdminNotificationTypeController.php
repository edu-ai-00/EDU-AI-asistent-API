<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\FormatsNotificationAdmin;
use App\Http\Controllers\Controller;
use App\Models\NotificationType;
use App\Models\UserNotification;
use App\Notifications\NotificationRequest;
use App\Notifications\NotificationService;
use App\Notifications\TemplateValidator;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Notification types in the admin (NOTIF-5, NOTIF-9, spec §5.2 "Types").
 *
 * Types are seeded and never created or deleted here; the admin edits their
 * texts, delivery limits and switches. Variable keys are fixed by the code
 * that fills them — only their label, sample, fallback and `required` are
 * editable. Every save is validated (templates rendered with the samples
 * against the length limits) and audited as notification_type.updated.
 */
class AdminNotificationTypeController extends Controller
{
    use FormatsNotificationAdmin;

    private const TEMPLATE_FIELDS = ['push_title', 'push_body', 'feed_title', 'feed_body', 'deep_link'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TemplateValidator $templates,
    ) {}

    /**
     * GET /api/admin/notification-types — every type with rules_count and
     * last_sent_at (last non-test notification).
     */
    public function index(): JsonResponse
    {
        $types = $this->withStats(NotificationType::query())->orderBy('id')->get();

        return response()->json([
            'data' => $types->map(fn (NotificationType $type) => $this->typeJson($type))->values(),
            'meta' => ['total' => $types->count()],
        ]);
    }

    /**
     * GET /api/admin/notification-types/{id}
     */
    public function show(int $id): JsonResponse
    {
        $type = $this->withStats(NotificationType::query())->whereKey($id)->firstOrFail();

        return response()->json(['data' => $this->typeJson($type)]);
    }

    /**
     * PUT /api/admin/notification-types/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $type = NotificationType::findOrFail($id);
        $validated = $request->validate($this->rules());

        $data = collect($validated)->except('variables')->all();

        if (array_key_exists('variables', $validated)) {
            $data['variables'] = $this->mergeVariables($type, $validated['variables']);
        }

        if (array_key_exists('channels', $data)) {
            $data['channels'] = array_values(array_intersect(NotificationType::CHANNELS, $data['channels']));
        }

        $limit = array_key_exists('frequency_limit', $data) ? $data['frequency_limit'] : $type->frequency_limit;
        $period = array_key_exists('frequency_period_hours', $data) ? $data['frequency_period_hours'] : $type->frequency_period_hours;
        if ($limit !== null && $period === null) {
            throw ValidationException::withMessages([
                'frequency_period_hours' => ['Při nastaveném frekvenčním limitu je potřeba zadat i období v hodinách.'],
            ]);
        }

        if (array_intersect(array_keys($data), [...self::TEMPLATE_FIELDS, 'variables', 'channels']) !== []) {
            $errors = $this->templates->validate(
                array_merge($type->templates(), array_intersect_key($data, array_flip(self::TEMPLATE_FIELDS))),
                $data['variables'] ?? (array) $type->variables,
                $data['channels'] ?? $type->channelList(),
            );

            if ($errors !== []) {
                return response()->json([
                    'message' => $errors[0]['message'],
                    'errors' => ['templates' => $errors],
                ], 422);
            }
        }

        $type->fill($data)->save();

        $diff = AuditLogger::changes($type);
        if ($diff !== []) {
            $this->audit->log('notification_type.updated', $type, diff: $diff, meta: ['key' => $type->key]);
        }

        $fresh = $this->withStats(NotificationType::query())->whereKey($type->id)->firstOrFail();

        return response()->json([
            'message' => 'Typ notifikace uložen.',
            'data' => $this->typeJson($fresh),
        ]);
    }

    /**
     * POST /api/admin/notification-types/{id}/preview — render (possibly
     * unsaved) templates with sample values. Saves nothing.
     */
    public function preview(Request $request, int $id): JsonResponse
    {
        $type = NotificationType::findOrFail($id);

        $validated = $request->validate([
            'templates' => 'sometimes|nullable|array',
            'templates.*' => 'nullable|string|max:5000',
            'sample' => 'sometimes|nullable|array',
            'variables' => 'sometimes|nullable|array',
            'variables.*' => 'array',
            'variables.*.key' => 'required|string',
            'channels' => 'sometimes|nullable|array|min:1',
            'channels.*' => ['string', Rule::in(NotificationType::CHANNELS)],
        ]);

        $templates = array_merge(
            $type->templates(),
            array_intersect_key((array) ($validated['templates'] ?? []), array_flip(self::TEMPLATE_FIELDS)),
        );

        $variables = isset($validated['variables'])
            ? $this->mergeVariables($type, $validated['variables'], strict: false)
            : (array) $type->variables;

        $preview = $this->templates->preview(
            $templates,
            $variables,
            $validated['channels'] ?? $type->channelList(),
            $this->scalarValues((array) ($validated['sample'] ?? [])),
        );

        return response()->json(['data' => $preview]);
    }

    /**
     * POST /api/admin/notification-types/{id}/test — a real send to one user
     * through the pipeline, source=test (no dedup, limits or quiet hours).
     */
    public function test(Request $request, NotificationService $service, int $id): JsonResponse
    {
        $type = NotificationType::findOrFail($id);

        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'sample' => 'sometimes|nullable|array',
            'channels' => 'sometimes|nullable|array|min:1',
            'channels.*' => ['string', Rule::in(NotificationType::CHANNELS)],
        ]);

        // The type's own samples, overridden by the admin's — except `name`,
        // which the service fills from the recipient unless the admin set it.
        $payload = [];
        foreach ($type->variableMap() as $key => $variable) {
            if ($key !== 'name' && is_scalar($variable['sample'] ?? null)) {
                $payload[$key] = $variable['sample'];
            }
        }
        $payload = array_merge($payload, $this->scalarValues((array) ($validated['sample'] ?? [])));

        $result = $service->send(new NotificationRequest(
            typeKey: $type->key,
            recipientIds: [(int) $validated['user_id']],
            payload: $payload,
            channels: $validated['channels'] ?? null,
            source: UserNotification::SOURCE_TEST,
        ));

        return response()->json([
            'data' => [
                'notification_id' => $result->delivered[0]->uuid ?? null,
                'decisions' => $this->decisionRows($result),
            ],
        ]);
    }

    /**
     * GET /api/admin/notification-types/{id}/audit
     */
    public function audit(int $id): JsonResponse
    {
        NotificationType::findOrFail($id);

        return $this->auditFor('notification_type', $id);
    }

    // ═══════════════════════════════════════════════════════════════════════════

    /** rules_count and last_sent_at on a type query. */
    private function withStats(Builder $query): Builder
    {
        return $query->select('notification_types.*')
            ->withCount('rules')
            ->addSelect(['last_sent_at' => UserNotification::query()
                ->selectRaw('MAX(created_at)')
                ->whereColumn('user_notifications.notification_type_id', 'notification_types.id')
                ->where('is_test', false)]);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string|max:2000',
            'category' => ['sometimes', 'string', Rule::in(NotificationType::CATEGORIES)],
            'target_role' => ['sometimes', 'string', Rule::in(NotificationType::ROLES)],
            'is_active' => 'sometimes|boolean',
            'is_mandatory' => 'sometimes|boolean',
            'manual_allowed' => 'sometimes|boolean',
            'channels' => 'sometimes|array|min:1',
            'channels.*' => ['string', 'distinct', Rule::in(NotificationType::CHANNELS)],
            'respect_quiet_hours' => 'sometimes|boolean',
            'frequency_limit' => 'sometimes|nullable|integer|min:1|max:1000',
            'frequency_period_hours' => 'sometimes|nullable|integer|min:1|max:8760',
            'dedup_window_hours' => 'sometimes|integer|min:0|max:8760',
            'push_title' => 'sometimes|nullable|string|max:255',
            'push_body' => 'sometimes|nullable|string|max:2000',
            'feed_title' => 'sometimes|nullable|string|max:255',
            'feed_body' => 'sometimes|nullable|string|max:5000',
            'variables' => 'sometimes|array',
            'variables.*' => 'array',
            'variables.*.key' => 'required|string|max:64',
            'variables.*.label' => 'sometimes|nullable|string|max:255',
            'variables.*.sample' => 'sometimes|nullable',
            'variables.*.fallback' => 'sometimes|nullable|string|max:255',
            'variables.*.required' => 'sometimes|boolean',
            'deep_link' => 'sometimes|nullable|string|max:255',
        ];
    }

    /**
     * Apply edited label / sample / fallback / required onto the type's
     * declared variables. Keys are fixed: with $strict the submitted set
     * must be exactly the declared one.
     *
     * @param  array<int, array<string, mixed>>  $submitted
     * @return list<array<string, mixed>>
     */
    private function mergeVariables(NotificationType $type, array $submitted, bool $strict = true): array
    {
        $declared = $type->variableMap();
        $byKey = [];
        foreach ($submitted as $variable) {
            $byKey[(string) $variable['key']] = $variable;
        }

        if ($strict) {
            $unknown = array_values(array_diff(array_keys($byKey), array_keys($declared)));
            $missing = array_values(array_diff(array_keys($declared), array_keys($byKey)));

            if ($unknown !== [] || $missing !== []) {
                throw ValidationException::withMessages([
                    'variables' => [sprintf(
                        'Klíče proměnných nelze měnit (typ má: %s).',
                        implode(', ', array_keys($declared)),
                    )],
                ]);
            }
        }

        $merged = [];
        foreach ($declared as $key => $variable) {
            $edit = $byKey[$key] ?? [];

            foreach (['label', 'sample', 'fallback'] as $field) {
                if (array_key_exists($field, $edit)) {
                    $variable[$field] = $edit[$field];
                }
            }

            if (array_key_exists('required', $edit)) {
                $variable['required'] = (bool) $edit['required'];
            }

            $variable['key'] = $key;
            $merged[] = $variable;
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, scalar|null>
     */
    private function scalarValues(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $out[(string) $key] = $value;
            }
        }

        return $out;
    }
}
