<?php

namespace Drupal\api_proxy_pbs\Controller;

use Drupal\api_proxy_pbs\Mapping\LegacyDate;
use Drupal\api_proxy_pbs\Service\ProgramLookup;

use Drupal\Core\Controller\ControllerBase;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for the "what is on at this time?" endpoints.
 *
 * Formerly the pbs_airnet_api module. Both endpoints answer a JSON string
 * (the program name) or `[]` when no program matches or the date cannot be
 * parsed, and `GET .../clear` drops the cached data.
 */
class LookupController extends ControllerBase
{
    use CachedJsonResponseTrait;

    protected $programLookup;

    /**
     * Constructor.
     */
    public function __construct(ProgramLookup $programLookup)
    {
        $this->programLookup = $programLookup;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container)
    {
        return new static(
            $container->get('api_proxy_pbs.program_lookup')
        );
    }

    /**
     * Program whose episode is broadcasting at the given time.
     *
     * @param string $date
     *   Melbourne time in any LegacyDate format (clients send YYYYMMDDHHMMSS),
     *   or `clear`.
     *
     * @return \Drupal\Core\Cache\CacheableJsonResponse|\Symfony\Component\HttpFoundation\JsonResponse
     */
    public function showname($date)
    {
        return $this->lookup($date, 'programOnAir');
    }

    /**
     * Program and presenters on air right now.
     *
     * @return \Drupal\Core\Cache\CacheableJsonResponse|\Symfony\Component\HttpFoundation\JsonResponse
     *   `{"name": ..., "broadcasters": ...}`, or `[]` when nothing is on air.
     */
    public function now()
    {
        try {
            $program = $this->programLookup->onAirProgram(new \DateTimeImmutable('now'));

            return $this->cachedResponse($program ?? [], 60, ['url.path'], [ProgramLookup::CACHE_TAG]);
        } catch (\Exception $e) {
            \Drupal::logger('api_proxy_pbs')->error('Failed to look up current program: @message', [
                '@message' => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => 'Failed to look up program'], 500);
        }
    }

    /**
     * Program in the weekly schedule grid at the given time.
     *
     * @param string $date
     *   Melbourne time in any LegacyDate format (clients send YYYYMMDDHHMMSS),
     *   or `clear`.
     *
     * @return \Drupal\Core\Cache\CacheableJsonResponse|\Symfony\Component\HttpFoundation\JsonResponse
     */
    public function schedule($date)
    {
        return $this->lookup($date, 'programScheduled');
    }

    /**
     * Answer a lookup, or clear the caches for `clear`.
     */
    protected function lookup(string $date, string $method)
    {
        try {
            if ($date === 'clear') {
                $this->programLookup->clear();

                return $this->cachedResponse('Cache Cleared.', 0, ['url.path']);
            }

            $when = LegacyDate::parse($date);
            $name = $when === null ? null : $this->programLookup->$method($when);

            return $this->cachedResponse($name ?? [], 3600, ['url.path'], [ProgramLookup::CACHE_TAG]);
        } catch (\Exception $e) {
            \Drupal::logger('api_proxy_pbs')->error('Failed to look up program for @date: @message', [
                '@date' => $date,
                '@message' => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => 'Failed to look up program'], 500);
        }
    }
}
