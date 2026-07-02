<?php

namespace Drupal\api_proxy_pbs\Transformer;

use Drupal\api_proxy_pbs\Mapping\FieldMap;

/**
 * Transforms JSON:API episode data to legacy format.
 */
class EpisodeTransformer
{
    /**
     * Transform a single episode from JSON:API to legacy format.
     *
     * @param array $jsonApiData
     *   JSON:API episode resource.
     * @param array|null $includedProgram
     *   The included program data if available.
     *
     * @return array
     *   Legacy format episode data.
     */
    public static function transform(array $jsonApiData, ?array $includedProgram = null): array
    {
        $attributes = $jsonApiData['attributes'] ?? [];
        $relationships = $jsonApiData['relationships'] ?? [];

        // Base transformation using FieldMap
        $episode = FieldMap::mapEpisodeAttributes($attributes);

        // Add title
        $episode[FieldMap::EPISODE_TITLE] = $attributes[FieldMap::EPISODE_TITLE] ?? '';

        // Add description/notes
        $episode['description'] = $attributes[FieldMap::EPISODE_BODY]['processed'] ?? 
                                  $attributes[FieldMap::EPISODE_BODY]['value'] ?? null;

        // Add image URLs from metatags
        $metatags = $attributes[FieldMap::IMAGE_METATAG] ?? [];
        $episode['imageUrl'] = FieldMap::extractImageFromMetatag($metatags, FieldMap::IMAGE_OG_IMAGE);
        $episode['smallImageUrl'] = $episode['imageUrl'];

        // Determine if this is the current episode
        $episode['currentEpisode'] = self::isCurrentEpisode($attributes[FieldMap::EPISODE_DATE_RANGE] ?? []);

        // Multiple episodes on day flag
        $episode['multipleEpsOnDay'] = false;

        // Build REST URLs if we have program info
        $programSlug = null;
        if ($includedProgram) {
            $programPath = $includedProgram['attributes'][FieldMap::PROGRAM_PATH]['alias'] ?? null;
            $programSlug = FieldMap::extractSlugFromPath($programPath);
        }

        if ($programSlug) {
            $dateFormatted = self::formatDateForUrl($episode[FieldMap::EPISODE_START]);
            $episode['episodeRestUrl'] = 'https://airnet.org.au/rest/stations/3pbs/programs/' . 
                                         $programSlug . '/episodes/' . $dateFormatted;
        }

        return $episode;
    }

    /**
     * Transform episode list for a program.
     *
     * @param array $jsonApiResponse
     *   Full JSON:API response.
     * @param array|null $programData
     *   Program data for building URLs.
     *
     * @return array
     *   Array of legacy format episodes.
     */
    public static function transformCollection(array $jsonApiResponse, ?array $programData = null): array
    {
        $episodes = [];
        
        foreach ($jsonApiResponse['data'] ?? [] as $episode) {
            // Try to resolve program from included if not provided
            $includedProgram = $programData;
            if (!$includedProgram && !empty($episode['relationships']['field_program']['data'])) {
                $programId = $episode['relationships']['field_program']['data']['id'] ?? null;
                if ($programId && !empty($jsonApiResponse['included'])) {
                    foreach ($jsonApiResponse['included'] as $included) {
                        if ($included['type'] === 'program' && $included['id'] === $programId) {
                            $includedProgram = $included;
                            break;
                        }
                    }
                }
            }
            
            $episodes[] = self::transform($episode, $includedProgram);
        }

        return $episodes;
    }

    /**
     * Check if episode is current/upcoming.
     */
    protected static function isCurrentEpisode(array $dateRange): bool
    {
        $startTime = $dateRange['value'] ?? null;
        if (!$startTime) {
            return false;
        }

        try {
            $start = new \DateTime($startTime);
            $now = new \DateTime('now', new \DateTimeZone('Australia/Melbourne'));
            
            // Consider current if starts within next 7 days
            $diff = $start->diff($now);
            return $diff->invert === 1 && $diff->days <= 7;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Format date for URL (e.g., "2025-11-29+02%3A00%3A00").
     */
    protected static function formatDateForUrl(?string $legacyDate): string
    {
        if (!$legacyDate) {
            return '';
        }

        // Legacy date is in "Y-m-d H:i:s" format
        // Convert to URL-encoded format: "Y-m-d+H%3Ai%3As"
        $parts = explode(' ', $legacyDate);
        if (count($parts) === 2) {
            $time = str_replace(':', '%3A', $parts[1]);
            return $parts[0] . '+' . $time;
        }

        return $legacyDate;
    }
}

