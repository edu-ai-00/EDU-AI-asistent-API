<?php

namespace Tests\Feature\Notifications;

use App\Models\NotificationPreference;
use App\Models\NotificationType;
use App\Models\PushDevice;
use App\Models\User;
use App\Notifications\NotificationRequest;
use App\Notifications\NotificationResult;
use App\Notifications\NotificationService;
use App\Notifications\Push\PushSender;
use Database\Seeders\NotificationCatalogSeeder;
use Illuminate\Support\Str;

/**
 * Shared set-up for the notification tests: the seeded catalog, users,
 * devices and a fake push sender.
 */
trait NotificationTestHelpers
{
    protected function seedCatalog(): void
    {
        $this->seed(NotificationCatalogSeeder::class);
    }

    protected function fakePush(bool $configured = true): FakePushSender
    {
        $fake = new FakePushSender($configured);
        $this->app->instance(PushSender::class, $fake);

        return $fake;
    }

    protected function student(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['role' => 'student'], $overrides));
    }

    protected function teacher(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['role' => 'teacher'], $overrides));
    }

    protected function device(User $user, array $overrides = []): PushDevice
    {
        return PushDevice::create(array_merge([
            'user_id' => $user->id,
            'token' => 'fcm-' . Str::random(20),
            'platform' => 'android',
            'last_seen_at' => now(),
        ], $overrides));
    }

    protected function preferences(User $user, array $attributes): NotificationPreference
    {
        return NotificationPreference::updateOrCreate(['user_id' => $user->id], $attributes);
    }

    protected function type(string $key): NotificationType
    {
        return NotificationType::where('key', $key)->firstOrFail();
    }

    protected function service(): NotificationService
    {
        return app(NotificationService::class);
    }

    /** @param  array<int, User|int>  $users */
    protected function sendTo(string $typeKey, array $users, array $payload = [], array $extra = []): NotificationResult
    {
        $ids = array_map(fn ($u) => $u instanceof User ? $u->id : $u, $users);

        return $this->service()->send(new NotificationRequest(...array_merge([
            'typeKey' => $typeKey,
            'recipientIds' => $ids,
            'payload' => $payload,
        ], $extra)));
    }

    /** Authorization header for a fresh Sanctum token; returns [headers, token id]. */
    protected function bearer(User $user, string $name = 'test'): array
    {
        $token = $user->createToken($name);

        return [['Authorization' => 'Bearer ' . $token->plainTextToken], $token->accessToken->id];
    }
}
