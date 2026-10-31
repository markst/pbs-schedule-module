<?php

namespace Drupal\api_proxy_pbs\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;

/**
 * Builds public, time-limited JSON responses for the legacy API.
 */
trait CachedJsonResponseTrait
{
    /**
     * Return `CacheableJsonResponse` with time to live value headers.
     * @param  mixed $data json serialisable data
     * @param  int $ttl time to live in seconds.
     * @param  array $cache_contexts array cache contexts.
     * @param  array $cache_tags cache tags; invalidating one drops the response.
     * @return CacheableJsonResponse
     */
    protected function cachedResponse(
        $data,
        $ttl = 3600,
        $cache_contexts = ['url'],
        array $cache_tags = []
    ) {
        $response = new CacheableJsonResponse($data);
        $response
            ->setPublic()
            ->setMaxAge($ttl)
            ->setExpires(new \DateTime('@' . (\Drupal::time()->getRequestTime() + $ttl)))
            ->headers->set('Content-Type', 'application/json; charset=utf-8');

        $cacheMetadata = CacheableMetadata::createFromRenderArray([
            '#cache' => [
                'max-age' => $ttl,
                'contexts' => $cache_contexts,
                'tags' => $cache_tags,
            ],
        ]);
        $response->addCacheableDependency($cacheMetadata);

        return $response;
    }
}
