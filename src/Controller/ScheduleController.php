<?php

namespace Drupal\api_proxy_pbs\Controller;

use Drupal\api_proxy_pbs\Service\JsonApiClient;
use Drupal\api_proxy_pbs\Transformer\ScheduleTransformer;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

use DateTime;
use DateTimeZone;

/**
 * Controller for schedule endpoints.
 */
class ScheduleController extends ControllerBase
{
    protected $jsonApiClient;

    /**
     * Constructor.
     */
    public function __construct(JsonApiClient $jsonApiClient)
    {
        $this->jsonApiClient = $jsonApiClient;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container)
    {
        return new static(
            $container->get('api_proxy_pbs.jsonapi_client')
        );
    }

    /**
     * Main index.
     * @return CacheableJsonResponse
     */
    public function index()
    {
        try {
            $ttl = 1 * 60 * 60;
            $data = $this->getFortnightSchedule();

            $response = new CacheableJsonResponse($data);
            $response->setPublic();
            $response->setMaxAge($ttl); // Configurable `admin/config/development/performance`
            $response->setExpires(new \DateTime('@' . (\Drupal::time()->getRequestTime() + $ttl)));
            $response->headers->set(
                'Content-Type',
                'application/json; charset=utf-8'
            );

            // Module info:
            $response->headers->set(
                'Proxy-Version',
                \Drupal::service('extension.list.module')->getExtensionInfo(
                    'api_proxy_pbs'
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
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Get fortnight schedule from JSON:API.
     *
     * @return array
     *   Array of schedule entries.
     */
    public function getFortnightSchedule()
    {
        $now = new DateTime('now', new DateTimeZone('Australia/Melbourne'));

        try {
            $response = $this->jsonApiClient->getSchedule();

            return ScheduleTransformer::transformToSchedule(
                $response,
                $this->jsonApiClient->getBaseUrl(),
                $now
            );
        } catch (\Exception $e) {
            \Drupal::logger('api_proxy_pbs')->error('Failed to fetch fortnight schedule: @message', [
                '@message' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Failed to fetch fortnight schedule from backend.', 0, $e);
        }
    }

    /**
     * Handle Exceptions
     * @param \Exception $e
     * @return CacheableJsonResponse
     */
    protected function handleException(\Exception $e)
    {
        return new JsonResponse(
            ['error' => $e->getMessage()],
            502
        );
    }
}
