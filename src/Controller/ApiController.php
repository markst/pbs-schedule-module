<?php

namespace Drupal\api_proxy_pbs\Controller;

use Drupal\api_proxy_pbs\Mapping\FieldMap;
use Drupal\api_proxy_pbs\Mapping\LegacyDate;
use Drupal\api_proxy_pbs\Service\JsonApiClient;
use Drupal\api_proxy_pbs\Service\SlugResolver;
use Drupal\api_proxy_pbs\Transformer\ProgramTransformer;
use Drupal\api_proxy_pbs\Transformer\EpisodeTransformer;
use Drupal\api_proxy_pbs\Transformer\TrackTransformer;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for program and episode API endpoints.
 */
class ApiController extends ControllerBase
{
    // Default numBefore/numAfter when omitted, and the cap (one JSON:API page).
    const EPISODE_WINDOW_DEFAULT = 10;
    const EPISODE_WINDOW_MAX = 50;
    const EPISODE_START_PATH = FieldMap::EPISODE_DATE_RANGE . '.value';

    protected $jsonApiClient;
    protected $slugResolver;

    /**
     * Constructor.
     */
    public function __construct(JsonApiClient $jsonApiClient, SlugResolver $slugResolver)
    {
        $this->jsonApiClient = $jsonApiClient;
        $this->slugResolver = $slugResolver;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container)
    {
        return new static(
            $container->get('api_proxy_pbs.jsonapi_client'),
            $container->get('api_proxy_pbs.slug_resolver')
        );
    }

    /**
     * Return `CacheableJsonResponse` with time to live value headers.
     * @param  Object $data json object
     * @param  Int $ttl time to live in seconds.
     * @param  Array $cache_contexts array cache contexts.
     * @return CacheableJsonResponse
     */
    protected function cachedResponse(
        $data,
        $ttl = 3600,
        $cache_contexts = ['url']
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
            ],
        ]);
        $response->addCacheableDependency($cacheMetadata);

        return $response;
    }

    /**
     * Airnet station info
     * @return json object of the config
     */
    public function getChannel()
    {
        return $this->cachedResponse(
            $this->configuration()
        );
    }

    protected function configuration()
    {
        $config = \Drupal::config('api_proxy_pbs.settings');
        $body = $config->get('config');

        return json_decode(
            $body ?: file_get_contents(__DIR__ . '/../config.json'),
            true
        );
    }


    /**
     * Get all programs.
     *
     * @return CacheableJsonResponse
     */
    public function getPrograms()
    {
        try {
            $programs = ProgramTransformer::transformCollection([
                'data' => $this->jsonApiClient->getAllPrograms(),
            ]);

            return $this->cachedResponse($programs, 86400);
        } catch (\Exception $e) {
            \Drupal::logger('api_proxy_pbs')->error('Failed to fetch programs: @message', [
                '@message' => $e->getMessage(),
            ]);
            
            return new JsonResponse(['error' => 'Failed to fetch programs'], 500);
        }
    }

    /**
     * Get single program by slug.
     *
     * @param string $program
     *   The program slug.
     *
     * @return CacheableJsonResponse|JsonResponse
     */
    public function getProgram($program)
    {
        try {
            // Resolve slug to UUID
            $uuid = $this->slugResolver->resolveProgram($program);
            
            if (!$uuid) {
                return new JsonResponse(['error' => 'Program not found'], 404);
            }
            
            $response = $this->jsonApiClient->getProgramByUuid($uuid, []);
            $programData = ProgramTransformer::transform($response['data']);
            
            return $this->cachedResponse($programData, 86400);
        } catch (\Exception $e) {
            \Drupal::logger('api_proxy_pbs')->error('Failed to fetch program @slug: @message', [
                '@slug' => $program,
                '@message' => $e->getMessage(),
            ]);
            
            return new JsonResponse(['error' => 'Failed to fetch program'], 500);
        }
    }

    /**
     * Get episodes for a program using Airnet's window semantics.
     *
     * Query parameters (as sent by iOS and accepted by Airnet):
     * - `date`: anchor date in any LegacyDate format. The anchor is the
     *   episode broadcast on that Melbourne calendar day (exact start
     *   preferred); no episode that day, or an unparseable date, yields [].
     *   Without `date` the anchor is the latest episode that has started.
     * - `numBefore` / `numAfter`: episodes before / after the anchor.
     *
     * The response is ascending by start: numBefore episodes, the anchor,
     * then numAfter episodes. iOS pages backwards by sending the oldest
     * start it holds as `date` with `numAfter=0` and dropping the last
     * (anchor) element.
     *
     * @param string $program
     *   The program slug.
     *
     * @return CacheableJsonResponse|JsonResponse
     */
    public function getEpisodes($program)
    {
        try {
            $uuid = $this->slugResolver->resolveProgram($program);
            if (!$uuid) {
                return new JsonResponse(['error' => 'Program not found'], 404);
            }

            $query = \Drupal::request()->query;
            $rawDate = (string) $query->get('date', '');
            $date = $rawDate === '' ? null : LegacyDate::parse($rawDate);

            // Airnet answers [] for a date it cannot parse.
            $episodes = [];
            if ($rawDate === '' || $date !== null) {
                $episodes = EpisodeTransformer::transformCollection($this->fetchEpisodeWindow(
                    $uuid,
                    $date,
                    $this->windowSize($query->get('numBefore')),
                    $this->windowSize($query->get('numAfter'))
                ));
            }

            return $this->cachedResponse($episodes, 3600, [
                'url.path',
                'url.query_args',
            ]);
        } catch (\Exception $e) {
            \Drupal::logger('api_proxy_pbs')->error('Failed to fetch episodes for @slug: @message', [
                '@slug' => $program,
                '@message' => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => 'Failed to fetch episodes'], 500);
        }
    }

    /**
     * Fetch the anchor episode plus its before/after neighbours.
     *
     * @return array
     *   JSON:API-shaped ['data' => ascending episodes, 'included' => [...]].
     */
    protected function fetchEpisodeWindow(string $programUuid, ?\DateTimeImmutable $date, int $numBefore, int $numAfter): array
    {
        $base = ['field_program.id' => $programUuid, 'status' => true];
        $includes = ['field_program'];
        $ascending = self::EPISODE_START_PATH;
        $descending = '-' . self::EPISODE_START_PATH;
        $included = [];

        if ($date !== null) {
            $dayStart = $date->setTime(0, 0);
            $response = $this->jsonApiClient->getEpisodes(
                $base + [
                    'day_start' => $this->startCondition('>=', $dayStart->getTimestamp()),
                    'day_end' => $this->startCondition('<', $dayStart->modify('+1 day')->getTimestamp()),
                ],
                $includes,
                ['limit' => self::EPISODE_WINDOW_MAX],
                $ascending
            );
            $anchor = $response['data'][0] ?? null;
            foreach ($response['data'] ?? [] as $episode) {
                if ($this->episodeStart($episode) === $date->getTimestamp()) {
                    $anchor = $episode;
                    break;
                }
            }
        } else {
            $response = $this->jsonApiClient->getEpisodes(
                $base + ['started' => $this->startCondition('<=', \Drupal::time()->getCurrentTime())],
                $includes,
                ['limit' => 1],
                $descending
            );
            $anchor = $response['data'][0] ?? null;
        }
        array_push($included, ...($response['included'] ?? []));

        if ($anchor === null || ($anchorStart = $this->episodeStart($anchor)) === null) {
            return ['data' => [], 'included' => []];
        }

        $before = [];
        if ($numBefore > 0) {
            $response = $this->jsonApiClient->getEpisodes(
                $base + ['before' => $this->startCondition('<', $anchorStart)],
                $includes,
                ['limit' => $numBefore],
                $descending
            );
            $before = array_reverse($response['data'] ?? []);
            array_push($included, ...($response['included'] ?? []));
        }

        $after = [];
        if ($numAfter > 0) {
            $response = $this->jsonApiClient->getEpisodes(
                $base + ['after' => $this->startCondition('>', $anchorStart)],
                $includes,
                ['limit' => $numAfter],
                $ascending
            );
            $after = $response['data'] ?? [];
            array_push($included, ...($response['included'] ?? []));
        }

        return [
            'data' => array_merge($before, [$anchor], $after),
            'included' => $included,
        ];
    }

    /**
     * JSON:API condition on the episode start (smart_date timestamp).
     */
    protected function startCondition(string $operator, int $timestamp): array
    {
        return [
            'condition' => [
                'path' => self::EPISODE_START_PATH,
                'operator' => $operator,
                'value' => $timestamp,
            ],
        ];
    }

    /**
     * Episode start as a Unix timestamp.
     */
    protected function episodeStart(array $episode): ?int
    {
        $value = $episode['attributes'][FieldMap::EPISODE_DATE_RANGE]['value'] ?? null;
        $timestamp = $value ? strtotime($value) : false;

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * Clamp a numBefore/numAfter value; absent means the default.
     */
    protected function windowSize($value): int
    {
        if ($value === null || $value === '') {
            return self::EPISODE_WINDOW_DEFAULT;
        }

        return max(0, min(self::EPISODE_WINDOW_MAX, (int) $value));
    }

    /**
     * Get single episode.
     *
     * @param string $program
     *   The program slug.
     * @param string $date
     *   The episode date.
     *
     * @return CacheableJsonResponse|JsonResponse
     */
    public function getEpisode($program, $date)
    {
        try {
            // Resolve to episode UUID
            $uuid = $this->slugResolver->resolveEpisode($program, $date);
            
            if (!$uuid) {
                return new JsonResponse(['error' => 'Episode not found'], 404);
            }
            
            $response = $this->jsonApiClient->getEpisodeByUuid($uuid, ['field_program']);
            
            // Resolve program from included
            $programData = null;
            $programId = $response['data']['relationships']['field_program']['data']['id'] ?? null;
            if ($programId && !empty($response['included'])) {
                foreach ($response['included'] as $included) {
                    if ($included['type'] === 'program' && $included['id'] === $programId) {
                        $programData = $included;
                        break;
                    }
                }
            }
            
            $episode = EpisodeTransformer::transform($response['data'], $programData);
            
            return $this->cachedResponse($episode, 3600);
        } catch (\Exception $e) {
            \Drupal::logger('api_proxy_pbs')->error('Failed to fetch episode for @slug on @date: @message', [
                '@slug' => $program,
                '@date' => $date,
                '@message' => $e->getMessage(),
            ]);
            
            return new JsonResponse(['error' => 'Failed to fetch episode'], 500);
        }
    }

    /**
     * Get playlist for an episode.
     *
     * @param string $program
     *   The program slug.
     * @param string $date
     *   The episode date.
     *
     * @return CacheableJsonResponse|JsonResponse
     */
    public function getPlaylists($program, $date)
    {
        try {
            // Resolve to episode UUID
            $uuid = $this->slugResolver->resolveEpisode($program, $date);
            
            if (!$uuid) {
                return new JsonResponse(['error' => 'Episode not found'], 404);
            }
            
            $response = $this->jsonApiClient->getEpisodeTracks($uuid);
            $tracks = TrackTransformer::transformCollection($response);
            
            return $this->cachedResponse($tracks, 3600);
        } catch (\Exception $e) {
            \Drupal::logger('api_proxy_pbs')->error('Failed to fetch playlist for @slug on @date: @message', [
                '@slug' => $program,
                '@date' => $date,
                '@message' => $e->getMessage(),
            ]);
            
            return new JsonResponse(['error' => 'Failed to fetch playlist'], 500);
        }
    }
}
