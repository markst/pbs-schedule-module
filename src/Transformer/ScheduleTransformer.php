<?php

namespace Drupal\api_proxy_pbs\Transformer;

use Drupal\api_proxy_pbs\Mapping\FieldMap;

/**
 * Transforms JSON:API episodes to legacy fortnight schedule format.
 */
class ScheduleTransformer
{
    /**
     * Transform episodes to fortnight schedule entries.
     *
     * @param array $jsonApiResponse
     *   Full JSON:API response with episodes and included programs.
     *
     * @return array
     *   Array of legacy schedule entries sorted by day and time.
     */
    public static function transformToSchedule(array $jsonApiResponse): array
    {
        $schedule = [];
        $episodes = $jsonApiResponse['data'] ?? [];
        $included = $jsonApiResponse['included'] ?? [];

        // Build a map of program UUIDs to program data
        $programsMap = [];
        foreach ($included as $item) {
            if ($item['type'] === 'program') {
                $programsMap[$item['id']] = $item;
            }
        }

        foreach ($episodes as $episode) {
            $attributes = $episode['attributes'] ?? [];
            $relationships = $episode['relationships'] ?? [];

            // Get program data
            $programId = $relationships['field_program']['data']['id'] ?? null;
            $programData = $programId ? ($programsMap[$programId] ?? null) : null;

            if (!$programData) {
                continue; // Skip episodes without program data
            }

            $entry = self::buildScheduleEntry($attributes, $programData);
            if ($entry) {
                $schedule[] = $entry;
            }
        }

        // Sort by day then start time
        usort($schedule, function ($a, $b) {
            $dayCompare = (int)$a[FieldMap::SCHEDULE_DAY] - (int)$b[FieldMap::SCHEDULE_DAY];
            if ($dayCompare !== 0) {
                return $dayCompare;
            }
            return strcmp($a[FieldMap::SCHEDULE_START], $b[FieldMap::SCHEDULE_START]);
        });

        return $schedule;
    }

    /**
     * Build a single schedule entry from episode and program data.
     */
    protected static function buildScheduleEntry(array $episodeAttributes, array $programData): ?array
    {
        $dateRange = $episodeAttributes[FieldMap::EPISODE_DATE_RANGE] ?? [];
        $programAttributes = $programData['attributes'] ?? [];

        // Parse start time
        $startTime = $dateRange['value'] ?? null;
        if (!$startTime) {
            return null;
        }

        try {
            $startDateTime = new \DateTime($startTime);
            $startDateTime->setTimezone(new \DateTimeZone('Australia/Melbourne'));
        } catch (\Exception $e) {
            return null;
        }

        // Calculate day number (1-14)
        $day = FieldMap::calculateFortnightDay($startDateTime);

        // Extract program slug
        $programPath = $programAttributes[FieldMap::PROGRAM_PATH]['alias'] ?? null;
        $slug = FieldMap::extractSlugFromPath($programPath);

        // Build schedule entry
        $entry = [
            FieldMap::SCHEDULE_GUIDE_ID => FieldMap::GUIDE_FM,
            FieldMap::SCHEDULE_DAY => (string) $day,
            FieldMap::SCHEDULE_START => $startDateTime->format(FieldMap::TIME_FORMAT_LEGACY),
            FieldMap::EPISODE_DURATION => ($dateRange[FieldMap::EPISODE_DURATION] ?? 0) * FieldMap::MINUTES_TO_SECONDS,
            FieldMap::PROGRAM_NAME => $programAttributes[FieldMap::PROGRAM_TITLE] ?? '',
            FieldMap::PROGRAM_BROADCASTERS => $programAttributes[FieldMap::PROGRAM_AUTHOR] ?? '',
            FieldMap::PROGRAM_GRID_DESCRIPTION => $programAttributes[FieldMap::PROGRAM_SUMMARY] ?? '',
            FieldMap::PROGRAM_SLUG => $slug,
            FieldMap::SCHEDULE_START_TIME => $startDateTime->format(FieldMap::DATE_FORMAT_ISO8601),
            'archived' => false,
        ];

        // Add images from program metatags
        $metatags = $programAttributes[FieldMap::IMAGE_METATAG] ?? [];
        $imageUrl = FieldMap::extractImageFromMetatag($metatags, FieldMap::IMAGE_OG_IMAGE);
        
        $entry['profileImage'] = $imageUrl;
        $entry['bannerImage'] = $imageUrl;

        // Add program REST URL
        if ($slug) {
            $entry['programRestUrl'] = 'https://airnet.org.au/rest/stations/3pbs/programs/' . $slug;
        }

        return $entry;
    }
}

