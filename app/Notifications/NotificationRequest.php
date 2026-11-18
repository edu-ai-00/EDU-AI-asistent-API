<?php

namespace App\Notifications;

use Carbon\CarbonImmutable;

/**
 * Everything NotificationService::send() needs to know about one send
 * (spec §2.1). Automatic (rule engine), manual (broadcast) and admin test
 * sends all build one of these — there is no other way in.
 *
 * $targetRole narrows the type's target_role for this send (a rule's
 * then.target_role may narrow `all` to one role); null = the type's.
 */
final class NotificationRequest
{
    /**
     * @param  array<int, int|string>  $recipientIds  duplicates are merged; empty → validation error
     * @param  array<string, scalar|null>  $payload  template variables
     * @param  list<string>|null  $channels  requested subset of the type's; null = all of the type's
     * @param  array{course_id?: int, classroom_id?: int, school_id?: int}|null  $context  permission context
     */
    public function __construct(
        public string $typeKey,
        public array $recipientIds,
        public array $payload = [],
        public ?array $channels = null,
        public ?string $dedupKey = null,
        public string $source = 'rule',
        public ?int $ruleId = null,
        public ?int $broadcastId = null,
        public ?array $context = null,
        public ?\DateTimeInterface $deliverAt = null,
        public ?string $targetRole = null,
    ) {}

    public function isTest(): bool
    {
        return $this->source === 'test';
    }

    /** The same request, to be sent now (what the delayed job replays). */
    public function withoutDeliverAt(): self
    {
        $copy = clone $this;
        $copy->deliverAt = null;

        return $copy;
    }

    /**
     * Plain-array form for queue payloads — survives serialisation without
     * dragging objects along.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type_key' => $this->typeKey,
            'recipient_ids' => array_values($this->recipientIds),
            'payload' => $this->payload,
            'channels' => $this->channels,
            'dedup_key' => $this->dedupKey,
            'source' => $this->source,
            'rule_id' => $this->ruleId,
            'broadcast_id' => $this->broadcastId,
            'context' => $this->context,
            'deliver_at' => $this->deliverAt?->format(\DateTimeInterface::ATOM),
            'target_role' => $this->targetRole,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            typeKey: (string) ($data['type_key'] ?? ''),
            recipientIds: (array) ($data['recipient_ids'] ?? []),
            payload: (array) ($data['payload'] ?? []),
            channels: isset($data['channels']) ? (array) $data['channels'] : null,
            dedupKey: isset($data['dedup_key']) ? (string) $data['dedup_key'] : null,
            source: (string) ($data['source'] ?? 'rule'),
            ruleId: isset($data['rule_id']) ? (int) $data['rule_id'] : null,
            broadcastId: isset($data['broadcast_id']) ? (int) $data['broadcast_id'] : null,
            context: isset($data['context']) ? (array) $data['context'] : null,
            deliverAt: isset($data['deliver_at']) ? CarbonImmutable::parse($data['deliver_at']) : null,
            targetRole: isset($data['target_role']) ? (string) $data['target_role'] : null,
        );
    }
}
