<?php

namespace Drupal\api_proxy_pbs\Transformer;

use Drupal\api_proxy_pbs\Mapping\FieldMap;

/**
 * Transforms JSON:API schedule templates to legacy fortnight schedule format.
 *
 * Backend slots are undated weekday templates (day + times). This BFF expands
 * each template onto both weeks of the 14-day fortnight grid.
 */
class ScheduleTransformer
{
    /**
     * Transform schedule templates to fortnight schedule entries.
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
        $fortnightStart = self::resolveFortnightStart($fortnightAnchor);
        if ($fortnightStart === null) {
            return [];
        }

        $schedule = [];
        foreach ($slots as $slot) {
            if (($slot['type'] ?? '') !== 'schedule_slot') {
                continue;
            }

            foreach (self::buildScheduleEntries($slot['attributes'] ?? [], $baseUrl, $fortnightStart) as $entry) {
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
    protected static function resolveFortnightStart(?\DateTimeInterface $fortnightAnchor): ?\DateTime
    {
        if ($fortnightAnchor === null) {
            return null;
        }

        $fortnightStart = \DateTime::createFromInterface($fortnightAnchor);
        $fortnightStart->setTimezone(new \DateTimeZone('Australia/Melbourne'));
        $fortnightStart->modify('monday this week');
        $fortnightStart->setTime(0, 0, 0);

        return $fortnightStart;
    }

    /**
     * Expand a template slot onto both fortnight weeks (days 1-7 and 8-14).
     *
     * @return list<array>
     *   Zero, one, or two legacy schedule entries.
     */
    protected static function buildScheduleEntries(
        array $attributes,
        string $baseUrl,
        \DateTimeInterface $fortnightStart
    ): array {
        $dayCode = $attributes['day'] ?? null;
        $startMinutes = $attributes['start_time'] ?? null;
        $endMinutes = $attributes['end_time'] ?? null;

        if ($dayCode === null || $startMinutes === null || $endMinutes === null) {
            return [];
        }

        $weekday = FieldMap::weekdayCodeToIsoDay((string) $dayCode);
        if ($weekday === null) {
            return [];
        }

        $timezoneName = $attributes['timezone'] ?? 'Australia/Melbourne';
        try {
            $timezone = new \DateTimeZone($timezoneName);
        } catch (\Exception $e) {
            return [];
        }

        $programUrl = $attributes['program_url'] ?? null;
        $slug = FieldMap::extractSlugFromPath($programUrl !== null ? urldecode($programUrl) : null);

        $entries = [];
        foreach ([0, 7] as $weekOffset) {
            $day = $weekday + $weekOffset;
            $slotDate = \DateTime::createFromInterface($fortnightStart);
            $slotDate->setTimezone($timezone);
            $slotDate->modify('+' . ($day - 1) . ' days');
            $slotDate->setTime(0, 0, 0);
            $slotDate->modify('+' . (int) $startMinutes . ' minutes');

            $entry = [
                FieldMap::SCHEDULE_GUIDE_ID => FieldMap::GUIDE_FM,
                FieldMap::SCHEDULE_DAY => (string) $day,
                FieldMap::SCHEDULE_START => FieldMap::formatMinutesAsTime((int) $startMinutes),
                FieldMap::EPISODE_DURATION => ((int) $endMinutes - (int) $startMinutes) * FieldMap::MINUTES_TO_SECONDS,
                FieldMap::PROGRAM_NAME => $attributes['program_title'] ?? '',
                FieldMap::PROGRAM_BROADCASTERS => $attributes['program_author'] ?? '',
                FieldMap::PROGRAM_GRID_DESCRIPTION => $attributes['program_tagline'] ?? '',
                FieldMap::PROGRAM_SLUG => $slug,
                FieldMap::SCHEDULE_START_TIME => $slotDate->format(FieldMap::DATE_FORMAT_ISO8601),
                'profileImage' => FieldMap::makeAbsoluteUrl($baseUrl, $attributes['program_image_uri'] ?? null),
                'bannerImage' => FieldMap::makeAbsoluteUrl($baseUrl, $attributes['program_featured_image_uri'] ?? null),
                'archived' => false,
            ];

            if ($slug) {
                $entry['programRestUrl'] = 'https://airnet.org.au/rest/stations/3pbs/programs/' . $slug;
            }

            $entries[] = $entry;
        }

        return $entries;
    }
}
