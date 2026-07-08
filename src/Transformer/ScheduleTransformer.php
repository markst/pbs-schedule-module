<?php

namespace Drupal\api_proxy_pbs\Transformer;

use Drupal\api_proxy_pbs\Mapping\FieldMap;

/**
 * Transforms JSON:API schedule slots to legacy fortnight schedule format.
 */
class ScheduleTransformer
{
    /**
     * Transform schedule slots to fortnight schedule entries.
     *
     * @param array $jsonApiResponse
     *   Full JSON:API response with schedule_slot resources.
     * @param string $baseUrl
     *   JSON:API base URL for absolutizing image paths.
     * @param \DateTimeInterface|null $fortnightAnchor
     *   Optional anchor date; fortnight starts on the Monday of this week.
     *
     * @return array
     *   Array of legacy schedule entries sorted by day and time.
     */
    public static function transformToSchedule(
        array $jsonApiResponse,
        string $baseUrl = '',
        ?\DateTimeInterface $fortnightAnchor = null
    ): array {
        $slots = $jsonApiResponse['data'] ?? [];
        $fortnightStart = self::resolveFortnightStart($slots, $fortnightAnchor);
        if ($fortnightStart === null) {
            return [];
        }

        $schedule = [];
        foreach ($slots as $slot) {
            if (($slot['type'] ?? '') !== 'schedule_slot') {
                continue;
            }

            $entry = self::buildScheduleEntry($slot['attributes'] ?? [], $baseUrl, $fortnightStart);
            if ($entry) {
                $schedule[] = $entry;
            }
        }

        usort($schedule, function ($a, $b) {
            $dayCompare = (int) $a[FieldMap::SCHEDULE_DAY] - (int) $b[FieldMap::SCHEDULE_DAY];
            if ($dayCompare !== 0) {
                return $dayCompare;
            }

            return strcmp($a[FieldMap::SCHEDULE_START], $b[FieldMap::SCHEDULE_START]);
        });

        return $schedule;
    }

    /**
     * Resolve the Monday that starts the fortnight window.
     */
    protected static function resolveFortnightStart(array $slots, ?\DateTimeInterface $fortnightAnchor): ?\DateTime
    {
        $timezone = new \DateTimeZone('Australia/Melbourne');
        $earliestDate = null;

        foreach ($slots as $slot) {
            $date = $slot['attributes']['date'] ?? null;
            if ($date === null) {
                continue;
            }
            if ($earliestDate === null || $date < $earliestDate) {
                $earliestDate = $date;
            }
        }

        if ($earliestDate !== null) {
            $fortnightStart = new \DateTime($earliestDate, $timezone);
            $fortnightStart->setTime(0, 0, 0);
            return $fortnightStart;
        }

        if ($fortnightAnchor === null) {
            return null;
        }

        $fortnightStart = \DateTime::createFromInterface($fortnightAnchor);
        $fortnightStart->setTimezone($timezone);
        $fortnightStart->modify('monday this week');
        $fortnightStart->setTime(0, 0, 0);

        return $fortnightStart;
    }

    /**
     * Build a single schedule entry from a schedule_slot.
     */
    protected static function buildScheduleEntry(
        array $attributes,
        string $baseUrl,
        \DateTimeInterface $fortnightStart
    ): ?array {
        $date = $attributes['date'] ?? null;
        $startMinutes = $attributes['start_time'] ?? null;
        $endMinutes = $attributes['end_time'] ?? null;

        if ($date === null || $startMinutes === null || $endMinutes === null) {
            return null;
        }

        $timezoneName = $attributes['timezone'] ?? 'Australia/Melbourne';

        try {
            $timezone = new \DateTimeZone($timezoneName);
            $startDateTime = new \DateTime($date, $timezone);
            $startDateTime->modify('+' . (int) $startMinutes . ' minutes');
        } catch (\Exception $e) {
            return null;
        }

        $day = FieldMap::calculateFortnightDayFromDate($date, $fortnightStart);
        if ($day < 1 || $day > 14) {
            return null;
        }

        $programUrl = $attributes['program_url'] ?? null;
        $slug = FieldMap::extractSlugFromPath($programUrl !== null ? urldecode($programUrl) : null);

        $entry = [
            FieldMap::SCHEDULE_GUIDE_ID => FieldMap::GUIDE_FM,
            FieldMap::SCHEDULE_DAY => (string) $day,
            FieldMap::SCHEDULE_START => FieldMap::formatMinutesAsTime((int) $startMinutes),
            FieldMap::EPISODE_DURATION => ((int) $endMinutes - (int) $startMinutes) * FieldMap::MINUTES_TO_SECONDS,
            FieldMap::PROGRAM_NAME => $attributes['program_title'] ?? '',
            FieldMap::PROGRAM_BROADCASTERS => $attributes['program_author'] ?? '',
            FieldMap::PROGRAM_GRID_DESCRIPTION => $attributes['program_tagline'] ?? '',
            FieldMap::PROGRAM_SLUG => $slug,
            FieldMap::SCHEDULE_START_TIME => $startDateTime->format(FieldMap::DATE_FORMAT_ISO8601),
            'profileImage' => FieldMap::makeAbsoluteUrl($baseUrl, $attributes['program_image_uri'] ?? null),
            'bannerImage' => FieldMap::makeAbsoluteUrl($baseUrl, $attributes['program_featured_image_uri'] ?? null),
            'archived' => false,
        ];

        if ($slug) {
            $entry['programRestUrl'] = 'https://airnet.org.au/rest/stations/3pbs/programs/' . $slug;
        }

        return $entry;
    }
}
