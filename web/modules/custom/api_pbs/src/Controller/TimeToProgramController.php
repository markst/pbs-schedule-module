<?php

namespace Drupal\api_pbs\Controller;

use Drupal\api_proxy_pbs\Controller\SubRequestController;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Symfony\Component\DependencyInjection\ContainerInterface;

class TimeToProgramController extends ControllerBase
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
     * Main index.
     * @return CacheableJsonResponse
     */
    public function lookup($date)
    {
        try {
            $ttl = 1 * 60 * 60;
            $data = $this->getSchedule();

            foreach ($data as $key => $program) {
              $slug = $program['slug'];

              $episodes = $this->subRequestController->getJSONSubrequest(
                '/rest/stations/3pbs/programs/' . $slug . '/episodes'
              );

              foreach ($episodes as $key => $episode) {
                $startdate = str_replace(' ', '', $episode['start']);
                $startdate = preg_replace('/[^A-Za-z0-9]/', '', $startdate);
                $enddate = str_replace(' ', '', $episode['end']);
                $enddate = preg_replace('/[^A-Za-z0-9]/', '', $enddate);


                if ($date >= $startdate && $date < $enddate) {
                  $data = $program['name'];
                  break 2;
                }
              }
              $data = NULL;
            }

            $response = new CacheableJsonResponse($data);
            $response->setPublic();
            $response->setMaxAge($ttl); // Configurable `admin/config/development/performance`
            $response->setExpires(new \DateTime('@' . (REQUEST_TIME + $ttl)));
            $response->headers->set(
                'Content-Type',
                'application/json; charset=utf-8'
            );

            // Module info:
            $response->headers->set(
                'Proxy-Version',
                \Drupal::service('extension.list.module')->getExtensionInfo(
                    'api_pbs'
                )['version']
            );

            $response->addCacheableDependency(
                CacheableMetadata::createFromRenderArray([
                    // Add Cache settings for Max-age and URL context.
                    '#cache' => [
                        'max-age' => $ttl,
                        'contexts' => ['url'],
                    ],
                ])
            );

            return $response;
        } catch (Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Concatenated schedule with `insomnia_` modifications based on `insomnia-lookup.json`
     * @return json array of scheduled programs
     */
    public function getSchedule()
    {
        // Fetch schedule:
        $schedule = $this->subRequestController->getJSONSubrequest(
            '/rest/stations/3pbs/guides/fm'
        );

      return $schedule;
    }

    /**
     * Handle Exceptions
     * @param  Exception $e the exception
     * @return CacheableJsonResponse
     */
    protected function handleException(Exception $e)
    {
        if ($e instanceof Rest404Exception) {
            return new CacheableJsonResponse(
                ['error' => $e->getMessage()],
                404
            );
        } elseif ($e instanceof Rest403Exception) {
            return new CacheableJsonResponse(
                ['error' => $e->getMessage()],
                403
            );
        }

        return new CacheableJsonResponse(
            ['error' => 'Internal server error.'],
            500
        );
    }
}
