<?php

namespace Drupal\api_proxy_pbs\Transformer;

use Drupal\api_proxy_pbs\Mapping\FieldMap;

/**
 * Transforms JSON:API schedule templates to legacy fortnight schedule format.
 *
 * Backend slots are undated weekday templates (day + times). This BFF expands
 * each template onto both weeks of the 14-day fortnight grid, labelling days
 * the way develop's ScheduleController did: in an odd ISO week the current
 * week is days 1-7 and next week 8-14; in an even ISO week the current week
 * is days 8-14 and next week 1-7.
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
     *   "Now"; its ISO week parity decides which week is days 1-7.
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
        $dayDates = self::resolveFortnightDayDates($fortnightAnchor);
        if ($dayDates === null) {
            return [];
        }

        $schedule = [];
        foreach ($slots as $slot) {
            if (($slot['type'] ?? '') !== 'schedule_slot') {
                continue;
            }

            foreach (self::buildScheduleEntries($slot['attributes'] ?? [], $baseUrl, $dayDates) as $entry) {
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
     * Map fortnight day labels 1-14 to the Melbourne date they fall on.
     *
     * Dates are offsets from the anchor week's Monday, so Dec/Jan weeks that
     * belong to another ISO year need no special casing; parity comes from
     * the anchor's ISO week number ('W'), as in develop.
     *
     * @return array<int, \DateTimeImmutable>|null
     *   Midnight (Melbourne) of each day label, or NULL without an anchor.
     */
    protected static function resolveFortnightDayDates(?\DateTimeInterface $fortnightAnchor): ?array
    {
        if ($fortnightAnchor === null) {
            return null;
        }

        $anchor = \DateTimeImmutable::createFromInterface($fortnightAnchor)
            ->setTimezone(new \DateTimeZone('Australia/Melbourne'));
        $monday = $anchor->modify('monday this week')->setTime(0, 0, 0);
        $evenWeek = ((int) $anchor->format('W')) % 2 === 0;

        $dates = [];
        for ($day = 1; $day <= 14; $day++) {
            $offset = $day - 1;
            if ($evenWeek) {
                // Days 8-14 are this week, days 1-7 next week.
                $offset += $day <= 7 ? 7 : -7;
            }
            $dates[$day] = $monday->modify('+' . $offset . ' days');
        }

        return $dates;
    }

    /**
     * Expand a template slot onto both fortnight weeks (days 1-7 and 8-14).
     *
     * @param array<int, \DateTimeImmutable> $dayDates
     *   Day label => Melbourne midnight, from resolveFortnightDayDates().
     *
     * @return list<array>
     *   Zero, one, or two legacy schedule entries.
     */
    protected static function buildScheduleEntries(
        array $attributes,
        string $baseUrl,
        array $dayDates
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

        // program_slug mirrors the program's field_api_aod_slug; see
        // FieldMap::extractProgramSlug() for why that is the slug source.
        $slug = $attributes['program_slug'] ?? null;
        if (!is_string($slug) || $slug === '') {
            $programUrl = $attributes['program_url'] ?? null;
            $slug = FieldMap::extractSlugFromPath($programUrl !== null ? urldecode($programUrl) : null);
        }

        $entries = [];
        foreach ([0, 7] as $weekOffset) {
            $day = $weekday + $weekOffset;
            $slotDate = new \DateTime($dayDates[$day]->format('Y-m-d'), $timezone);
            // Wall-clock time, as develop's setTime(), so DST days stay on the hour.
            $slotDate->setTime(intdiv((int) $startMinutes, 60), (int) $startMinutes % 60);

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
