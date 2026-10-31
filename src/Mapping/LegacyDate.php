<?php

namespace Drupal\api_proxy_pbs\Mapping;

/**
 * Parses the date strings legacy (Airnet) clients send.
 *
 * Shared by the episode route parameter, the episodes `date` query parameter
 * and the stream route so they all accept the same formats.
 */
final class LegacyDate
{
    const TIMEZONE = 'Australia/Melbourne';

    /**
     * Date[time] with optional offset: "2025-11-17 02:00:00",
     * "2025-11-17+02:00:00" (episodeRestUrl), "2020-07-24T13:00:00" (iOS
     * Date.toAirnet()), "2025-11-17 02:00", "2025-11-17T02:00:00+11:00",
     * "2025-11-17".
     */
    private const PATTERN_DASHED = '/^(\d{4})-(\d{2})-(\d{2})(?:[ T+](\d{2}):(\d{2})(?::(\d{2}))?(Z|[+-]\d{2}:?\d{2})?)?$/';

    /**
     * Compact timestamp: "202511170200" (iOS stream ???EPISODE??? value),
     * "20251117020000" or "20251117".
     */
    private const PATTERN_COMPACT = '/^(\d{4})(\d{2})(\d{2})(?:(\d{2})(\d{2})(\d{2})?)?$/';

    /**
     * Parse a legacy date string.
     *
     * Values without an offset are Melbourne local time, as Airnet emitted.
     * Percent-encoding is decoded first, so URL-encoded route values work.
     *
     * @return \DateTimeImmutable|null
     *   The instant in the Melbourne timezone, or NULL if unparseable.
     */
    public static function parse(?string $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        $value = trim(rawurldecode($value));
        if (!preg_match(self::PATTERN_DASHED, $value, $m) && !preg_match(self::PATTERN_COMPACT, $value, $m)) {
            return null;
        }

        [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        $hour = (int) ($m[4] ?? 0);
        $minute = (int) ($m[5] ?? 0);
        $second = (int) (($m[6] ?? '') === '' ? 0 : $m[6]);
        $offset = $m[7] ?? '';

        if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        $melbourne = new \DateTimeZone(self::TIMEZONE);
        $zone = $offset === '' ? $melbourne : new \DateTimeZone($offset === 'Z' ? 'UTC' : $offset);

        return (new \DateTimeImmutable('now', $zone))
            ->setDate($year, $month, $day)
            ->setTime($hour, $minute, $second)
            ->setTimezone($melbourne);
    }
}
