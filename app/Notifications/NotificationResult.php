<?php

namespace App\Notifications;

use App\Models\UserNotification;
use Carbon\CarbonInterface;

/**
 * What NotificationService::send() did with each recipient.
 *
 * Every recipient gets exactly one decision:
 *   - deliver  — a user_notifications row was created (the push part may
 *                still have been deferred or skipped: see `push`)
 *   - suppress — duplicate, frequency_limit, category_daily_cap, no_channels
 *   - reject   — user_not_found, user_deactivated, user_merged,
 *                role_mismatch, no_*_access, type_inactive
 *
 * A request with a future deliverAt is only scheduled: no decisions yet,
 * `scheduled` is true and `scheduledFor` says when it will be replayed.
 */
final class NotificationResult
{
    public const DELIVER = 'deliver';
    public const SUPPRESS = 'suppress';
    public const REJECT = 'reject';

    /** @var list<UserNotification> */
    public array $delivered = [];

    /**
     * @var list<array{user_id: int, decision: string, reason: ?string, notification_id: ?string,
     *                 push: ?array{status: string, reason: ?string}}>
     */
    public array $decisions = [];

    public bool $scheduled = false;

    public ?CarbonInterface $scheduledFor = null;

    public static function scheduledFor(CarbonInterface $at): self
    {
        $result = new self();
        $result->scheduled = true;
        $result->scheduledFor = $at;

        return $result;
    }

    public function addDecision(int $userId, string $decision, ?string $reason = null, ?UserNotification $notification = null): void
    {
        $this->decisions[] = [
            'user_id' => $userId,
            'decision' => $decision,
            'reason' => $reason,
            'notification_id' => $notification?->uuid,
            'push' => null,
        ];

        if ($decision === self::DELIVER && $notification !== null) {
            $this->delivered[] = $notification;
        }
    }

    /** Record the push sub-decision on the recipient's (deliver) decision. */
    public function setPush(int $userId, string $status, ?string $reason = null): void
    {
        foreach ($this->decisions as $i => $decision) {
            if ($decision['user_id'] === $userId && $decision['decision'] === self::DELIVER) {
                $this->decisions[$i]['push'] = ['status' => $status, 'reason' => $reason];

                return;
            }
        }
    }

    public function deliveredCount(): int
    {
        return $this->countOf(self::DELIVER);
    }

    public function suppressedCount(): int
    {
        return $this->countOf(self::SUPPRESS);
    }

    public function rejectedCount(): int
    {
        return $this->countOf(self::REJECT);
    }

    /** Delivered recipients whose push was deferred (quiet hours, queue down). */
    public function deferredCount(): int
    {
        return count(array_filter(
            $this->decisions,
            fn (array $d) => ($d['push']['status'] ?? null) === UserNotification::PUSH_DEFERRED,
        ));
    }

    /** @return array{user_id: int, decision: string, reason: ?string, notification_id: ?string, push: ?array}|null */
    public function decisionFor(int $userId): ?array
    {
        foreach ($this->decisions as $decision) {
            if ($decision['user_id'] === $userId) {
                return $decision;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'scheduled' => $this->scheduled,
            'scheduled_for' => $this->scheduledFor?->toIso8601String(),
            'delivered' => $this->deliveredCount(),
            'suppressed' => $this->suppressedCount(),
            'rejected' => $this->rejectedCount(),
            'deferred' => $this->deferredCount(),
            'decisions' => $this->decisions,
        ];
    }

    private function countOf(string $decision): int
    {
        return count(array_filter($this->decisions, fn (array $d) => $d['decision'] === $decision));
    }
}
