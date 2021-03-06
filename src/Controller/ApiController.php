<?php

namespace Drupal\api_proxy_pbs\Controller;

use Drupal\api_proxy_pbs\Controller\SubRequestController;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Url;

use Drupal\Component\Utility\UrlHelper;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

class ApiController extends ControllerBase
{
    protected $subRequestController;

    public function __construct(SubRequestController $sub_request_controller)
    {
        $this->subRequestController = $sub_request_controller;
    }

    public static function create(ContainerInterface $container)
    {
        // SubRequestController::create($container);
        $controller = new SubRequestController(
            \Drupal::service('http_kernel.basic'),
            \Drupal::requestStack()
        );
        return new static($controller);
    }

    /**
     * Perform subrequest request with uri
     * @return json object
     */
    protected function subRequest(string $uri, $params = [])
    {
        return $this->subRequestController->getJSONSubrequest($uri, $params);
    }

    /**
     * Return `CacheableJsonResponse` with time to live value headers.
     * @param  Object $data json object
     * @param  Int $ttl time to live in seconds.
     * @param  Array $cache_contexts array cache contexts.
     * @return CacheableJsonResponse
     */
    protected function cachedReponse(
        $data,
        $ttl = 3600,
        $cache_contexts = ['url']
    ) {
        $response = new CacheableJsonResponse($data);
        $response
            ->setPublic()
            ->setMaxAge($ttl)
            ->setExpires(new \DateTime('@' . (REQUEST_TIME + $ttl)));

        $response->headers->set(
            'Content-Type',
            'application/json; charset=utf-8'
        );

        $response->addCacheableDependency(
            CacheableMetadata::createFromRenderArray([
                // Add Cache settings for Max-age and URL context.
                '#cache' => [
                    'max-age' => $ttl,
                    'contexts' => $cache_contexts,
                ],
            ])
        );

        return $response;
    }

    /**
     * Airnet station info
     * @return json array of scheduled programs
     */
    public function getChannel()
    {
        return $this->cachedReponse(
            $this->subRequest('/rest/stations/3pbs/channels/fm')
        );
    }

    /**
     * Airnet vanilla one week schedule
     * @return json array of scheduled programs
     */
    public function getSchedule()
    {
        return $this->cachedReponse(
            $this->subRequest('/rest/stations/3pbs/guides/fm'),
            86400
        );
    }

    /**
     * Airnet programs
     * @return json array of programs
     */
    public function getPrograms()
    {
        return $this->cachedReponse(
            $this->subRequest('/rest/stations/3pbs/programs'),
            86400
        );
    }

    /**
     * Airnet program.
     * @return json array of programs
     */
    public function getProgram($program)
    {
        return $this->cachedReponse(
            $this->subRequest("/rest/stations/3pbs/programs/{$program}"),
            86400
        );
    }

    /**
     * Airnet episodes for a program.
     * @return json
     */
    public function getEpisodes($program)
    {
        $params = \Drupal::request()->query->all();
        return $this->cachedReponse(
            $this->subRequest(
                "/rest/stations/3pbs/programs/{$program}/episodes" .
                    '?' .
                    // URL encode params for `api_proxy`
                    UrlHelper::buildQuery($params),
                $params
            ),
            3600,
            [
                'url.path',
                'url.query_args',
                'url.query_args:date',
                'url.query_args:numBefore',
            ]
        );
    }

    /**
     * Airnet episode for a program.
     * @return json
     */
    public function getEpisode($program, $date)
    {
        try {
            $episode = $this->subRequest(
                "/rest/stations/3pbs/programs/{$program}/episodes/{$date}"
            );
            return $this->cachedReponse($episode, 3600);
        } catch (Throwable $e) {
            return new JsonResponse($e->getMessage());
        }
    }

    /**
     * Airnet playlists for a program.
     * @return json
     */
    public function getPlaylists($program, $date)
    {
        try {
            $playlist = $this->subRequest(
                "/rest/stations/3pbs/programs/{$program}/episodes/{$date}/playlists"
            );
            return $this->cachedReponse($playlist, 10);
        } catch (Throwable $e) {
            return new JsonResponse($e->getMessage());
        }
    }
}
