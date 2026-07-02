<?php

namespace Drupal\api_proxy_pbs\Controller;

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
            $filters = ['status' => true];
            $includes = [];
            
            $response = $this->jsonApiClient->getPrograms($filters, $includes);
            $programs = ProgramTransformer::transformCollection($response);
            
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
     * Get episodes for a program.
     *
     * @param string $program
     *   The program slug.
     *
     * @return CacheableJsonResponse|JsonResponse
     */
    public function getEpisodes($program)
    {
        try {
            // Resolve slug to UUID
            $uuid = $this->slugResolver->resolveProgram($program);
            
            if (!$uuid) {
                return new JsonResponse(['error' => 'Program not found'], 404);
            }
            
            // Get query parameters
            $params = \Drupal::request()->query->all();
            
            // Build filters
            $filters = [
                'field_program.id' => $uuid,
                'status' => true,
            ];
            
            // Add date filter if provided
            if (!empty($params['date'])) {
                $filters['field_date_range.value'] = $params['date'];
            }
            
            $includes = ['field_program'];
            $page = [];
            
            if (!empty($params['numBefore'])) {
                $page['limit'] = (int) $params['numBefore'];
            }
            
            $response = $this->jsonApiClient->getEpisodes($filters, $includes, $page);
            
            // Get program data for transformation
            $programData = null;
            if (!empty($response['included'])) {
                foreach ($response['included'] as $included) {
                    if ($included['type'] === 'program' && $included['id'] === $uuid) {
                        $programData = $included;
                        break;
                    }
                }
            }
            
            $episodes = EpisodeTransformer::transformCollection($response, $programData);
            
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
            
            return new JsonResponse(['error' => 'Episode not found'], 404);
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
            
            return new JsonResponse(['error' => 'Playlist not found'], 404);
        }
    }
}
