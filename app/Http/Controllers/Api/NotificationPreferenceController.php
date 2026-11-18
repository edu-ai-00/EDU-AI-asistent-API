<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Models\NotificationType;
use App\Models\User;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The app's notification settings page (NOTIF-3, NOTIF-6).
 *
 * GET returns the effective values (the user's own, else the defaults) plus
 * the defaults themselves, so "reset to default" can be shown, and the
 * mandatory types that arrive whatever the push toggle says. PUT takes any
 * subset; null resets a field to its default (reminder_time: null = off).
 * The row is created on the first PUT and read by the next send.
 */
class NotificationPreferenceController extends Controller
{
    private const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

    /**
     * GET /api/notification-preferences
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => $this->format(NotificationPreference::forUser($user->id), $user),
        ]);
    }

    /**
     * PUT /api/notification-preferences
     */
    public function update(Request $request): JsonResponse
    {
        $time = ['sometimes', 'nullable', 'string', 'regex:' . self::TIME_PATTERN];

        $validated = $request->validate([
            'push_enabled' => 'sometimes|boolean',
            'quiet_hours_start' => $time,
            'quiet_hours_end' => $time,
            'reminder_time' => $time,
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== null && !self::isIanaTimezone((string) $value)) {
                    $fail('Neznámé časové pásmo. Použijte název IANA, např. Europe/Prague.');
                }
            }],
        ], [
            'quiet_hours_start.regex' => 'Čas musí být ve tvaru HH:MM.',
            'quiet_hours_end.regex' => 'Čas musí být ve tvaru HH:MM.',
            'reminder_time.regex' => 'Čas musí být ve tvaru HH:MM.',
        ]);

        // Quiet hours are one window: both ends together, or neither.
        $hasStart = array_key_exists('quiet_hours_start', $validated);
        $hasEnd = array_key_exists('quiet_hours_end', $validated);
        if ($hasStart !== $hasEnd
            || ($hasStart && (($validated['quiet_hours_start'] === null) !== ($validated['quiet_hours_end'] === null)))) {
            throw ValidationException::withMessages([
                'quiet_hours_start' => ['Začátek i konec tichých hodin se nastavují společně (oba, nebo ani jeden).'],
            ]);
        }

        $user = $request->user();
        $preference = NotificationPreference::forUser($user->id);

        if (array_key_exists('push_enabled', $validated)) {
            $preference->push_enabled = (bool) $validated['push_enabled'];
        }

        foreach (['quiet_hours_start', 'quiet_hours_end', 'reminder_time'] as $field) {
            if (array_key_exists($field, $validated)) {
                $preference->{$field} = NotificationPreference::toStorageTime($validated[$field]);
            }
        }

        if (array_key_exists('timezone', $validated)) {
            $preference->timezone = $validated['timezone'];
        }

        $preference->save();

        return response()->json([
            'data' => $this->format($preference->fresh() ?? $preference, $user),
        ]);
    }

    /** @return array<string, mixed> */
    private function format(NotificationPreference $preference, User $user): array
    {
        $quiet = NotificationPreference::defaultQuietHours();

        return [
            'push_enabled' => (bool) ($preference->push_enabled ?? true),
            'quiet_hours_start' => $preference->effectiveQuietHoursStart(),
            'quiet_hours_end' => $preference->effectiveQuietHoursEnd(),
            'reminder_time' => NotificationPreference::hhmm($preference->reminder_time),
            'timezone' => $preference->effectiveTimezone(),
            'defaults' => [
                'quiet_hours_start' => $quiet['start'],
                'quiet_hours_end' => $quiet['end'],
                'timezone' => NotificationPreference::defaultTimezone(),
            ],
            'mandatory_types' => $this->mandatoryTypes($user),
        ];
    }

    /**
     * Mandatory, active types this user can receive — what the settings page
     * lists under "always arrives".
     *
     * @return list<array{key: string, name: string}>
     */
    private function mandatoryTypes(User $user): array
    {
        return NotificationType::where('is_mandatory', true)
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (NotificationType $type) => NotificationType::roleMatches($type->target_role, $user->role))
            ->map(fn (NotificationType $type) => ['key' => $type->key, 'name' => $type->name])
            ->values()
            ->all();
    }

    private static function isIanaTimezone(string $timezone): bool
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
    }
}
