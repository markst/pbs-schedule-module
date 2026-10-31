<?php

namespace Drupal\api_proxy_pbs\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\api_proxy_pbs\Mapping\FieldMap;
use Drupal\api_proxy_pbs\Mapping\LegacyDate;

/**
 * Service for resolving legacy slugs to UUIDs with caching.
 *
 * Only successful resolutions are cached. Backend/transport failures are not
 * caught here: they propagate so controllers can answer 5xx instead of
 * reporting a missing program or episode.
 */
class SlugResolver
{
    const CACHE_PREFIX_PROGRAM = 'pbs_slug_program';
    const CACHE_PREFIX_EPISODE = 'pbs_slug_episode';
    const DEFAULT_CACHE_TTL = 86400;

    protected $jsonApiClient;
    protected $cache;
    protected int $cacheTtl;

    /**
     * Constructor.
     */
    public function __construct(JsonApiClient $jsonApiClient, CacheBackendInterface $cache, ConfigFactoryInterface $configFactory)
    {
        $this->jsonApiClient = $jsonApiClient;
        $this->cache = $cache;
        $ttl = (int) $configFactory->get('api_proxy_pbs.settings')->get('slug_cache_ttl');
        $this->cacheTtl = $ttl > 0 ? $ttl : self::DEFAULT_CACHE_TTL;
    }

    /**
     * Resolve program slug to UUID.
     *
     * @param string $slug
     *   The legacy program slug.
     *
     * @return string|null
     *   The program UUID or null if no published program has this slug.
     *
     * @throws \Exception
     *   When the backend request fails.
     */
    public function resolveProgram(string $slug): ?string
    {
        $cacheKey = $this->getProgramCacheKey($slug);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== false && $cached->data !== null) {
            return $cached->data;
        }

        $uuid = $this->findProgramUuidBySlug($slug);
        if ($uuid) {
            $this->cache->set($cacheKey, $uuid, time() + $this->cacheTtl);
        }

        return $uuid;
    }

    /**
     * Resolve episode by program slug and legacy start date.
     *
     * @param string $slug
     *   The legacy program slug.
     * @param string $date
     *   The episode start in any format LegacyDate::parse() accepts.
     *
     * @return string|null
     *   The episode UUID or null if not found.
     *
     * @throws \Exception
     *   When the backend request fails.
     */
    public function resolveEpisode(string $slug, string $date): ?string
    {
        $start = LegacyDate::parse($date);
        if ($start === null) {
            return null;
        }

        $cacheKey = $this->getEpisodeCacheKey($slug, $start);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== false && $cached->data !== null) {
            return $cached->data;
        }

        $programUuid = $this->resolveProgram($slug);
        if (!$programUuid) {
            return null;
        }

        $response = $this->jsonApiClient->getEpisodes(
            [
                'field_program.id' => $programUuid,
                'status' => true,
                FieldMap::EPISODE_DATE_RANGE . '.value' => $start->getTimestamp(),
            ],
            [],
            ['limit' => 1]
        );

        $uuid = $response['data'][0]['id'] ?? null;
        if ($uuid) {
            $this->cache->set($cacheKey, $uuid, time() + $this->cacheTtl);
        }

        return $uuid;
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
            foreach ($this->jsonApiClient->getAllPrograms() as $program) {
                $uuid = $program['id'] ?? null;
                $slug = FieldMap::extractProgramSlug($program['attributes'] ?? []);
                if ($uuid && $slug) {
                    $this->cache->set($this->getProgramCacheKey($slug), $uuid, time() + $this->cacheTtl);
                    $count++;
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
        $this->cache->delete($this->getProgramCacheKey($slug));
    }

    /**
     * Invalidate cache for a specific episode.
     *
     * @param string $slug
     *   The program slug.
     * @param string $date
     *   The episode date in any legacy format.
     */
    public function invalidateEpisodeCache(string $slug, string $date): void
    {
        $start = LegacyDate::parse($date);
        if ($start !== null) {
            $this->cache->delete($this->getEpisodeCacheKey($slug, $start));
        }
    }

    /**
     * Find a published program UUID by legacy slug.
     */
    protected function findProgramUuidBySlug(string $slug): ?string
    {
        // Direct lookup on the AOD slug (the slug source, see
        // FieldMap::extractProgramSlug()).
        $response = $this->jsonApiClient->getPrograms(
            ['status' => true, FieldMap::PROGRAM_AOD_SLUG => $slug],
            [],
            ['limit' => 1]
        );
        if (!empty($response['data'][0]['id'])) {
            return $response['data'][0]['id'];
        }

        // Programs without an AOD slug are exposed under their alias slug.
        foreach ($this->jsonApiClient->getAllPrograms() as $program) {
            if (FieldMap::extractProgramSlug($program['attributes'] ?? []) === $slug) {
                return $program['id'] ?? null;
            }
        }

        return null;
    }

    /**
     * Get program cache key.
     */
    protected function getProgramCacheKey(string $slug): string
    {
        return self::CACHE_PREFIX_PROGRAM . ':' . $slug;
    }

    /**
     * Get episode cache key; keyed on the parsed instant so every legacy
     * spelling of the same start time shares one entry.
     */
    protected function getEpisodeCacheKey(string $slug, \DateTimeInterface $start): string
    {
        return self::CACHE_PREFIX_EPISODE . ':' . $slug . ':' . $start->getTimestamp();
    }
}
