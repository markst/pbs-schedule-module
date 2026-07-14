<?php

namespace Drupal\api_proxy_pbs\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\api_proxy_pbs\Mapping\FieldMap;

/**
 * Service for resolving legacy slugs to UUIDs with caching.
 */
class SlugResolver
{
    const CACHE_BIN = 'default';
    const CACHE_PREFIX_PROGRAM = 'pbs_slug_program';
    const CACHE_PREFIX_EPISODE = 'pbs_slug_episode';
    const CACHE_TTL = 86400; // 24 hours

    protected $jsonApiClient;
    protected $cache;

    /**
     * Constructor.
     */
    public function __construct(JsonApiClient $jsonApiClient, CacheBackendInterface $cache)
    {
        $this->jsonApiClient = $jsonApiClient;
        $this->cache = $cache;
    }

    /**
     * Resolve program slug to UUID.
     *
     * @param string $slug
     *   The legacy program slug.
     *
     * @return string|null
     *   The program UUID or null if not found.
     */
    public function resolveProgram(string $slug): ?string
    {
        $cacheKey = $this->getProgramCacheKey($slug);
        
        // Check cache first (ignore cached misses so fixes propagate).
        $cached = $this->cache->get($cacheKey);
        if ($cached !== false && $cached->data !== null) {
            return $cached->data;
        }

        // Cache miss - scan published programs (path.alias filter is unreliable).
        try {
            $uuid = $this->findProgramUuidBySlug($slug);

            if ($uuid) {
                $this->cache->set($cacheKey, $uuid, time() + self::CACHE_TTL);
                return $uuid;
            }
        } catch (\Exception $e) {
            \Drupal::logger('api_proxy_pbs')->error('Slug resolution failed for program @slug: @message', [
                '@slug' => $slug,
                '@message' => $e->getMessage(),
            ]);
        }

        // Not found - do not cache misses; they may succeed after code or data changes.
        return null;
    }

    /**
     * Resolve episode by program slug and date.
     *
     * @param string $slug
     *   The legacy program slug.
     * @param string $date
     *   The episode date in various formats.
     *
     * @return string|null
     *   The episode UUID or null if not found.
     */
    public function resolveEpisode(string $slug, string $date): ?string
    {
        $cacheKey = $this->getEpisodeCacheKey($slug, $date);
        
        // Check cache first (ignore cached misses so fixes propagate).
        $cached = $this->cache->get($cacheKey);
        if ($cached !== false && $cached->data !== null) {
            return $cached->data;
        }

        // First resolve program UUID
        $programUuid = $this->resolveProgram($slug);
        if (!$programUuid) {
            return null;
        }

        // Parse date to standardized format
        $normalizedDate = $this->normalizeDateInput($date);
        if ($normalizedDate === '') {
            return null;
        }

        // Scan episodes (field_date_range.value filter is unreliable on JSON:API).
        try {
            $match = $this->findEpisodeMatchByProgramAndDate($programUuid, $normalizedDate);

            if ($match) {
                $this->cacheEpisodeResolution($slug, $match['uuid'], $match['iso_value']);
                return $match['uuid'];
            }
        } catch (\Exception $e) {
            \Drupal::logger('api_proxy_pbs')->error('Episode resolution failed for @slug on @date: @message', [
                '@slug' => $slug,
                '@date' => $date,
                '@message' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Build cache of all programs for warming.
     *
     * @return int
     *   Number of programs cached.
     */
    public function buildProgramCache(): int
    {
        $count = 0;
        
        try {
            foreach ($this->getAllPrograms() as $program) {
                $uuid = $program['id'] ?? null;
                $path = $program['attributes']['path']['alias'] ?? null;
                
                if ($uuid && $path) {
                    $slug = FieldMap::extractSlugFromPath($path);
                    if ($slug) {
                        $cacheKey = $this->getProgramCacheKey($slug);
                        $this->cache->set($cacheKey, $uuid, time() + self::CACHE_TTL);
                        $count++;
                    }
                }
            }
        } catch (\Exception $e) {
            \Drupal::logger('api_proxy_pbs')->error('Failed to build program cache: @message', [
                '@message' => $e->getMessage(),
            ]);
        }

        return $count;
    }

    /**
     * Invalidate cache for a specific program.
     *
     * @param string $slug
     *   The program slug.
     */
    public function invalidateProgramCache(string $slug): void
    {
        $cacheKey = $this->getProgramCacheKey($slug);
        $this->cache->delete($cacheKey);
    }

    /**
     * Invalidate cache for a specific episode.
     *
     * @param string $slug
     *   The program slug.
     * @param string $date
     *   The episode date.
     */
    public function invalidateEpisodeCache(string $slug, string $date): void
    {
        $cacheKey = $this->getEpisodeCacheKey($slug, $date);
        $this->cache->delete($cacheKey);
    }

    /**
     * Find a program UUID by legacy slug.
     */
    protected function findProgramUuidBySlug(string $slug): ?string
    {
        foreach ($this->getAllPrograms() as $program) {
            $path = $program['attributes']['path']['alias'] ?? null;
            if (FieldMap::extractSlugFromPath($path) === $slug) {
                return $program['id'] ?? null;
            }
        }

        return null;
    }

    /**
     * Fetch all published programs, following JSON:API pagination.
     */
    protected function getAllPrograms(): \Generator
    {
        $offset = 0;
        $limit = 50;

        do {
            $response = $this->jsonApiClient->getPrograms(
                ['status' => true],
                [],
                ['limit' => $limit, 'offset' => $offset]
            );

            $programs = $response['data'] ?? [];
            foreach ($programs as $program) {
                yield $program;
            }

            $offset += $limit;
            $hasMore = !empty($response['links']['next']) && !empty($programs);
        } while ($hasMore);
    }

    /**
     * Find an episode match by program and legacy date string.
     */
    protected function findEpisodeMatchByProgramAndDate(string $programUuid, string $normalizedDate): ?array
    {
        foreach ($this->getEpisodesForProgram($programUuid) as $episode) {
            $value = $episode['attributes']['field_date_range']['value'] ?? null;
            if (!$value) {
                continue;
            }

            if (in_array($normalizedDate, $this->getEpisodeLegacyDateVariants($value), TRUE)) {
                return [
                    'uuid' => $episode['id'] ?? null,
                    'iso_value' => $value,
                ];
            }
        }

        return null;
    }

    /**
     * Cache episode resolution for all legacy date variants.
     */
    protected function cacheEpisodeResolution(string $slug, ?string $uuid, string $isoValue): void
    {
        if (!$uuid) {
            return;
        }

        foreach ($this->getEpisodeLegacyDateVariants($isoValue) as $variant) {
            $this->cache->set(
                $this->getEpisodeCacheKey($slug, $variant),
                $uuid,
                time() + self::CACHE_TTL
            );
        }
    }

    /**
     * Legacy date strings that identify an episode start time.
     *
     * Includes Melbourne-local dates and the UTC-style dates previously emitted
     * before timezone normalization was added.
     */
    protected function getEpisodeLegacyDateVariants(?string $isoValue): array
    {
        if (empty($isoValue)) {
            return [];
        }

        $variants = [];

        if ($melbourne = FieldMap::formatDateLegacy($isoValue)) {
            $variants[] = $melbourne;
        }

        try {
            $utc = new \DateTime($isoValue);
            $utc->setTimezone(new \DateTimeZone('UTC'));
            $variants[] = $utc->format(FieldMap::DATE_FORMAT_LEGACY);
        } catch (\Exception $e) {
            // Ignore invalid dates.
        }

        return array_values(array_unique($variants));
    }

    /**
     * Fetch all published episodes for a program, following JSON:API pagination.
     */
    protected function getEpisodesForProgram(string $programUuid): \Generator
    {
        $offset = 0;
        $limit = 50;

        do {
            $response = $this->jsonApiClient->getEpisodes(
                [
                    'field_program.id' => $programUuid,
                    'status' => true,
                ],
                [],
                ['limit' => $limit, 'offset' => $offset]
            );

            $episodes = $response['data'] ?? [];
            foreach ($episodes as $episode) {
                yield $episode;
            }

            $offset += $limit;
            $hasMore = !empty($response['links']['next']) && !empty($episodes);
        } while ($hasMore);
    }

    /**
     * Normalize legacy episode date strings from route parameters.
     */
    protected function normalizeDateInput(string $date): string
    {
        $date = urldecode($date);
        $date = str_replace('+', ' ', $date);

        return trim($date);
    }

    /**
     * Get program cache key.
     */
    protected function getProgramCacheKey(string $slug): string
    {
        return self::CACHE_PREFIX_PROGRAM . ':' . $slug;
    }

    /**
     * Get episode cache key.
     */
    protected function getEpisodeCacheKey(string $slug, string $date): string
    {
        return self::CACHE_PREFIX_EPISODE . ':' . $slug . ':' . $date;
    }

    /**
     * Parse date string to ISO 8601 format for filtering.
     */
    protected function parseDate(string $date): ?string
    {
        // Try parsing various date formats
        $formats = [
            'Y-m-d H:i:s',
            'Y-m-d+H:i:s',
            'Y-m-d H-i-s',
            'Y-m-d',
            'Ymd',
        ];

        foreach ($formats as $format) {
            $parsed = \DateTime::createFromFormat($format, $date, new \DateTimeZone('Australia/Melbourne'));
            if ($parsed !== false) {
                return $parsed->format('Y-m-d\TH:i:s');
            }
        }

        // Try strtotime as fallback
        $timestamp = strtotime($date);
        if ($timestamp !== false) {
            $dt = new \DateTime('@' . $timestamp);
            $dt->setTimezone(new \DateTimeZone('Australia/Melbourne'));
            return $dt->format('Y-m-d\TH:i:s');
        }

        return null;
    }
}

