<?php

namespace App\Notifications;

use App\Models\NotificationPreference;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A recipient's quiet-hours window, in their own timezone (NOTIF-6 C).
 *
 * The window is [start, end) in local wall-clock time and may cross midnight
 * (20:00–07:00 is the default). start == end means no quiet hours at all.
 * Everything handed back is UTC, ready to compare with database timestamps.
 */
final class QuietHours
{
    private int $startMinute;

    private int $endMinute;

    public function __construct(
        public readonly string $start,
        public readonly string $end,
        public readonly string $timezone,
    ) {
        $this->startMinute = self::minuteOfDay($start);
        $this->endMinute = self::minuteOfDay($end);
    }

    public static function forPreference(NotificationPreference $preference): self
    {
        return new self(
            $preference->effectiveQuietHoursStart(),
            $preference->effectiveQuietHoursEnd(),
            self::safeTimezone($preference->effectiveTimezone()),
        );
    }

    public static function forUser(int $userId): self
    {
        return self::forPreference(NotificationPreference::forUser($userId));
    }

    /** Is the moment inside the quiet window (recipient-local)? */
    public function contains(CarbonInterface $moment): bool
    {
        if ($this->startMinute === $this->endMinute) {
            return false;
        }

        $local = CarbonImmutable::instance($moment)->setTimezone($this->timezone);
        $minute = $local->hour * 60 + $local->minute;

        if ($this->startMinute < $this->endMinute) {
            return $minute >= $this->startMinute && $minute < $this->endMinute;
        }

        // Crosses midnight: 20:00–07:00 is 20:00–24:00 plus 00:00–07:00.
        return $minute >= $this->startMinute || $minute < $this->endMinute;
    }

    /**
     * When the quiet window the moment falls into ends — the first local
     * `end` time strictly after the moment — in UTC.
     */
    public function endAfter(CarbonInterface $moment): CarbonImmutable
    {
        $local = CarbonImmutable::instance($moment)->setTimezone($this->timezone);

        [$hour, $minute] = [intdiv($this->endMinute, 60), $this->endMinute % 60];
        $candidate = $local->setTime($hour, $minute);

        if ($candidate->lessThanOrEqualTo($local)) {
            $candidate = $local->addDay()->setTime($hour, $minute);
        }

        return $candidate->utc();
    }

    /**
     * The recipient-local calendar day containing the moment, as a UTC
     * [start, end) pair (NOTIF-6 B daily caps).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function localDayBounds(CarbonInterface $moment, string $timezone): array
    {
        $local = CarbonImmutable::instance($moment)->setTimezone(self::safeTimezone($timezone));
        $start = $local->startOfDay();

        return [$start->utc(), $start->addDay()->utc()];
    }

    /** A stored timezone that PHP no longer knows must not break a send. */
    public static function safeTimezone(?string $timezone): string
    {
        if ($timezone !== null && $timezone !== '') {
            try {
                new \DateTimeZone($timezone);

                return $timezone;
            } catch (\Throwable) {
                // fall through to the default
            }
        }

        return NotificationPreference::defaultTimezone();
    }

    private static function minuteOfDay(string $time): int
    {
        $parts = explode(':', $time);

        return ((int) ($parts[0] ?? 0)) % 24 * 60 + ((int) ($parts[1] ?? 0)) % 60;
    }
}
