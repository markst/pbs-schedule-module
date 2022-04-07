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
     * Airnet vanilla one week schedule
     * @return json array of scheduled programs
     */
    public function getSchedule()
    {
        return new JsonResponse(
            $this->subRequest('/rest/stations/3pbs/guides/fm')
        );
    }

    /**
     * Airnet programs
     * @return json array of programs
     */
    public function getPrograms()
    {
        return new JsonResponse(
            $this->subRequest('/rest/stations/3pbs/programs')
        );
    }

    /**
     * Airnet program.
     * @return json array of programs
     */
    public function getProgram($program)
    {
        return new JsonResponse(
            $this->subRequest("/rest/stations/3pbs/programs/{$program}")
        );
    }

    /**
     * Airnet episodes for a program.
     * @return json
     */
    public function getEpisodes($program)
    {
        $params = \Drupal::request()->query->all();

        return (new CacheableJsonResponse(
            $this->subRequest(
                "/rest/stations/3pbs/programs/{$program}/episodes" .
                    '?' .
                    // URL encode params for `api_proxy`
                    UrlHelper::buildQuery($params),
                $params
            ),
            200
        ))->addCacheableDependency(
            CacheableMetadata::createFromRenderArray([
                '#cache' => [
                    'contexts' => [
                        'url.path',
                        'url.query_args',
                        'url.query_args:date',
                        'url.query_args:numBefore',
                    ],
                ],
            ])
        );
    }

    /**
     * Airnet episode for a program.
     * @return json
     */
    public function getEpisode($program, $date)
    {
        return new JsonResponse(
            $this->subRequest(
                "/rest/stations/3pbs/programs/{$program}/episodes/{$date}"
            )
        );
    }

    /**
     * Airnet playlists for a program.
     * @return json
     */
    public function getPlaylists($program, $date)
    {
        return (new CacheableJsonResponse(
            $this->subRequest(
                "/rest/stations/3pbs/programs/{$program}/episodes/{$date}/playlists"
            ),
            200
        ))->addCacheableDependency(
            CacheableMetadata::createFromRenderArray([
                '#cache' => [
                    'max-age' => 60,
                    'contexts' => ['url'],
                ],
            ])
        );
    }
}
