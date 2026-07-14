<?php

namespace Drupal\api_proxy_pbs\Mapping;

/**
 * Central field mapping definitions for JSON:API to legacy format transformation.
 */
class FieldMap
{
    // Program field mappings
    const PROGRAM_TITLE = 'title';
    const PROGRAM_NAME = 'name';
    const PROGRAM_AUTHOR = 'field_author';
    const PROGRAM_BROADCASTERS = 'broadcasters';
    const PROGRAM_BODY = 'field_body';
    const PROGRAM_DESCRIPTION = 'description';
    const PROGRAM_SUMMARY = 'field_summary';
    const PROGRAM_GRID_DESCRIPTION = 'gridDescription';
    const PROGRAM_PATH = 'path';
    const PROGRAM_SLUG = 'slug';
    const PROGRAM_TAGLINE = 'field_tagline';
    const PROGRAM_TWITTER = 'field_link_x';
    const PROGRAM_FACEBOOK = 'field_link_facebook';
    const PROGRAM_INSTAGRAM = 'field_link_instagram';

    // Episode field mappings
    const EPISODE_DATE_RANGE = 'field_date_range';
    const EPISODE_START = 'start';
    const EPISODE_END = 'end';
    const EPISODE_DURATION = 'duration';
    const EPISODE_TITLE = 'title';
    const EPISODE_BODY = 'field_body';
    const EPISODE_NOTES = 'notes';
    const EPISODE_SUMMARY = 'field_summary';
    const EPISODE_AUDIO_URL = 'field_audio_url';

    // Track field mappings
    const TRACK_TITLE = 'field_title';
    const TRACK_ALBUM = 'field_album';
    const TRACK_RELEASE = 'release';
    const TRACK_MARKER = 'field_track_marker';
    const TRACK_TIME = 'time';
    const TRACK_ARTIST = 'field_artist';
    const TRACK_DESCRIPTORS = 'field_descriptors';
    const TRACK_BODY = 'field_body';

    // Content descriptor mappings
    const DESCRIPTOR_AUSTRALIAN = 'is_australian';
    const DESCRIPTOR_LOCAL = 'is_local';
    const DESCRIPTOR_FEMALE = 'is_woman';
    const DESCRIPTOR_GENDER_DIVERSE = 'is_gender_diverse';
    const DESCRIPTOR_INDIGENOUS = 'is_indigenous';
    const DESCRIPTOR_NEW = 'is_new';

    // Legacy descriptor field names
    const LEGACY_DESCRIPTOR_AUSTRALIAN = 'isAustralian';
    const LEGACY_DESCRIPTOR_LOCAL = 'isLocal';
    const LEGACY_DESCRIPTOR_FEMALE = 'isFemale';
    const LEGACY_DESCRIPTOR_GENDER_DIVERSE = 'isGenderNonConforming';
    const LEGACY_DESCRIPTOR_INDIGENOUS = 'isIndigenous';
    const LEGACY_DESCRIPTOR_NEW = 'isNew';

    // Image field mappings
    const IMAGE_METATAG = 'metatag';
    const IMAGE_OG_IMAGE = 'og:image';
    const IMAGE_TWITTER_IMAGE = 'twitter:image';
    const IMAGE_PROFILE = 'profileImageUrl';
    const IMAGE_PROFILE_SMALL = 'profileImageSmall';
    const IMAGE_BANNER = 'bannerImageUrl';
    const IMAGE_BANNER_SMALL = 'bannerImageSmall';

    // Schedule field mappings
    const SCHEDULE_GUIDE_ID = 'guideId';
    const SCHEDULE_DAY = 'day';
    const SCHEDULE_START = 'start';
    const SCHEDULE_START_TIME = 'startTime';

    // Time constants
    const MINUTES_TO_SECONDS = 60;
    const SECONDS_TO_MINUTES = 60;

    // Date format constants
    const DATE_FORMAT_LEGACY = 'Y-m-d H:i:s';
    const DATE_FORMAT_ISO8601 = 'c';
    const TIME_FORMAT_LEGACY = 'H:i:s';

    // Guide constants
    const GUIDE_FM = 'fm';

    /**
     * Map JSON:API program attributes to legacy format.
     */
    public static function mapProgramAttributes(array $attributes): array
    {
        return [
            self::PROGRAM_NAME => $attributes[self::PROGRAM_TITLE] ?? '',
            self::PROGRAM_BROADCASTERS => $attributes[self::PROGRAM_AUTHOR] ?? '',
            self::PROGRAM_DESCRIPTION => $attributes[self::PROGRAM_BODY]['processed'] ?? $attributes[self::PROGRAM_BODY]['value'] ?? '',
            self::PROGRAM_GRID_DESCRIPTION => $attributes[self::PROGRAM_SUMMARY] ?? '',
            'twitterHandle' => self::extractTwitterHandle($attributes[self::PROGRAM_TWITTER] ?? null),
            'facebookPage' => self::extractFacebookPage($attributes[self::PROGRAM_FACEBOOK] ?? null),
            'url' => $attributes[self::PROGRAM_PATH]['alias'] ?? null,
            'defaultFirstAiredGuide' => self::GUIDE_FM,
        ];
    }

    /**
     * Map JSON:API episode attributes to legacy format.
     */
    public static function mapEpisodeAttributes(array $attributes): array
    {
        $dateRange = $attributes[self::EPISODE_DATE_RANGE] ?? [];
        
        return [
            self::EPISODE_START => self::formatDateLegacy($dateRange['value'] ?? ''),
            self::EPISODE_END => self::formatDateLegacy($dateRange['end_value'] ?? ''),
            self::EPISODE_DURATION => ($dateRange[self::EPISODE_DURATION] ?? 0) * self::MINUTES_TO_SECONDS,
            self::EPISODE_TITLE => $attributes[self::EPISODE_TITLE] ?? '',
            self::EPISODE_NOTES => $attributes[self::EPISODE_BODY]['processed'] ?? $attributes[self::EPISODE_BODY]['value'] ?? null,
            'url' => $attributes[self::EPISODE_AUDIO_URL]['uri'] ?? null,
        ];
    }

    /**
     * Map JSON:API track attributes to legacy format.
     */
    public static function mapTrackAttributes(array $attributes): array
    {
        return [
            'track' => $attributes[self::TRACK_TITLE] ?? '',
            self::EPISODE_TITLE => $attributes[self::TRACK_TITLE] ?? '',
            self::TRACK_RELEASE => $attributes[self::TRACK_ALBUM] ?? null,
            self::TRACK_TIME => self::formatTrackTime($attributes[self::TRACK_MARKER] ?? null),
            self::EPISODE_NOTES => $attributes[self::TRACK_BODY]['processed'] ?? $attributes[self::TRACK_BODY]['value'] ?? null,
        ];
    }

    /**
     * Map content descriptors from JSON:API to legacy format.
     */
    public static function mapContentDescriptors(array $descriptors): array
    {
        $mapped = [
            self::LEGACY_DESCRIPTOR_AUSTRALIAN => null,
            self::LEGACY_DESCRIPTOR_LOCAL => null,
            self::LEGACY_DESCRIPTOR_FEMALE => null,
            self::LEGACY_DESCRIPTOR_GENDER_DIVERSE => null,
            self::LEGACY_DESCRIPTOR_INDIGENOUS => null,
            self::LEGACY_DESCRIPTOR_NEW => null,
        ];

        foreach ($descriptors as $descriptor) {
            switch ($descriptor) {
                case self::DESCRIPTOR_AUSTRALIAN:
                    $mapped[self::LEGACY_DESCRIPTOR_AUSTRALIAN] = true;
                    break;
                case self::DESCRIPTOR_LOCAL:
                    $mapped[self::LEGACY_DESCRIPTOR_LOCAL] = true;
                    break;
                case self::DESCRIPTOR_FEMALE:
                    $mapped[self::LEGACY_DESCRIPTOR_FEMALE] = true;
                    break;
                case self::DESCRIPTOR_GENDER_DIVERSE:
                    $mapped[self::LEGACY_DESCRIPTOR_GENDER_DIVERSE] = true;
                    break;
                case self::DESCRIPTOR_INDIGENOUS:
                    $mapped[self::LEGACY_DESCRIPTOR_INDIGENOUS] = true;
                    break;
                case self::DESCRIPTOR_NEW:
                    $mapped[self::LEGACY_DESCRIPTOR_NEW] = true;
                    break;
            }
        }

        return $mapped;
    }

    /**
     * Extract slug from path alias.
     */
    public static function extractSlugFromPath(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }

        // Extract slug from "/program/slug" or "/program/slug/..." format
        if (preg_match('#^/program/([^/]+)#', $path, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract image URL from metatag array.
     */
    public static function extractImageFromMetatag(array $metatags, string $property = 'og:image'): ?string
    {
        foreach ($metatags as $tag) {
            if (isset($tag['attributes']['property']) && $tag['attributes']['property'] === $property) {
                return $tag['attributes']['content'] ?? null;
            }
            if (isset($tag['attributes']['name']) && $tag['attributes']['name'] === str_replace(':', ':', $property)) {
                return $tag['attributes']['content'] ?? null;
            }
        }

        return null;
    }

    /**
     * Extract a string value from a JSON:API link field or plain string.
     */
    public static function extractLinkString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_array($value) && isset($value['uri']) && $value['uri'] !== '') {
            return (string) $value['uri'];
        }

        return null;
    }

    /**
     * Normalize Twitter/X link fields to a handle string for legacy clients.
     */
    public static function extractTwitterHandle(mixed $value): ?string
    {
        $link = self::extractLinkString($value);
        if ($link === null) {
            return null;
        }

        if (str_starts_with($link, '@')) {
            return substr($link, 1);
        }

        if (preg_match('#(?:https?://)?(?:www\.)?(?:twitter\.com|x\.com)/(@?[^/?]+)#i', $link, $matches)) {
            return ltrim($matches[1], '@');
        }

        return $link;
    }

    /**
     * Normalize Facebook link fields to a page slug for legacy clients.
     */
    public static function extractFacebookPage(mixed $value): ?string
    {
        $link = self::extractLinkString($value);
        if ($link === null) {
            return null;
        }

        if (preg_match('#(?:https?://)?(?:www\.)?facebook\.com/([^/?]+)#i', $link, $matches)) {
            return $matches[1];
        }

        return $link;
    }

    /**
     * Format ISO 8601 date to legacy format.
     */
    public static function formatDateLegacy(?string $isoDate): ?string
    {
        if (empty($isoDate)) {
            return null;
        }

        try {
            $date = new \DateTime($isoDate);
            $date->setTimezone(new \DateTimeZone('Australia/Melbourne'));
            return $date->format(self::DATE_FORMAT_LEGACY);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Format time in HH:mm:ss from ISO datetime.
     */
    public static function formatTimeLegacy(?string $isoDate): ?string
    {
        if (empty($isoDate)) {
            return null;
        }

        try {
            $date = new \DateTime($isoDate);
            return $date->format(self::TIME_FORMAT_LEGACY);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Format track marker (seconds) to HH:MM:SS.
     */
    public static function formatTrackTime($seconds): ?string
    {
        if ($seconds === null || $seconds === '') {
            return null;
        }

        $seconds = (int) $seconds;
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }

    /**
     * Format minutes from midnight as HH:MM:SS.
     */
    public static function formatMinutesAsTime(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        return sprintf('%02d:%02d:00', $hours, $mins);
    }

    /**
     * Map a schedule template weekday code to ISO-8601 day-of-week (1=Mon..7=Sun).
     */
    public static function weekdayCodeToIsoDay(string $dayCode): ?int
    {
        $map = [
            'MO' => 1,
            'TU' => 2,
            'WE' => 3,
            'TH' => 4,
            'FR' => 5,
            'SA' => 6,
            'SU' => 7,
        ];

        return $map[strtoupper($dayCode)] ?? null;
    }

    /**
     * Calculate day number (1-14) from a slot date within a fortnight.
     */
    public static function calculateFortnightDayFromDate(string $date, \DateTimeInterface $fortnightStart): int
    {
        $slotDate = new \DateTime($date, $fortnightStart->getTimezone());
        $start = \DateTime::createFromInterface($fortnightStart);
        $start->setTime(0, 0, 0);
        $slotDate->setTime(0, 0, 0);

        return (int) $start->diff($slotDate)->days + 1;
    }

    /**
     * Convert a relative URI to an absolute URL using the JSON:API base.
     */
    public static function makeAbsoluteUrl(string $baseUrl, ?string $uri): ?string
    {
        if ($uri === null || $uri === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $uri)) {
            return $uri;
        }

        return rtrim($baseUrl, '/') . '/' . ltrim($uri, '/');
    }

    /**
     * Calculate day number (1-14) for fortnight schedule.
     */
    public static function calculateFortnightDay(\DateTime $episodeDate): int
    {
        $now = new \DateTime('now', new \DateTimeZone('Australia/Melbourne'));
        $currentWeekNumber = (int) $now->format('W');
        $isEvenWeek = $currentWeekNumber % 2 === 0;

        $episodeWeekNumber = (int) $episodeDate->format('W');
        $episodeDayOfWeek = (int) $episodeDate->format('N'); // 1 (Monday) to 7 (Sunday)

        // Determine if episode is in current week or next week
        if ($episodeWeekNumber === $currentWeekNumber) {
            return $episodeDayOfWeek;
        } elseif ($episodeWeekNumber === $currentWeekNumber + 1 || 
                  ($currentWeekNumber === 52 && $episodeWeekNumber === 1)) {
            return $episodeDayOfWeek + 7;
        }

        // Fallback calculation based on date difference
        $diff = $episodeDate->diff($now);
        $daysDiff = $diff->days;
        
        if ($diff->invert) {
            return min(14, $daysDiff + 1);
        }

        return 1;
    }
}

