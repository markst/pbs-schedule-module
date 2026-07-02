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
        } catch (Exception $e) {
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
        // Calculate date range for next 14 days
        $now = new DateTime('now', new DateTimeZone('Australia/Melbourne'));
        $startDate = $now->format('Y-m-d');
        
        $endDate = clone $now;
        $endDate->modify('+14 days');
        $endDateStr = $endDate->format('Y-m-d');

        // Query episodes for the next 14 days
        $filters = [
            'field_date_range.value' => [
                'operator' => '>=',
                'value' => $startDate,
            ],
            'status' => true,
        ];

        $includes = ['field_program'];
        $page = ['limit' => 500]; // Fetch enough for fortnight

        try {
            $response = $this->jsonApiClient->getEpisodes($filters, $includes, $page);
            
            // Transform to legacy schedule format
            return ScheduleTransformer::transformToSchedule($response);
        } catch (\Exception $e) {
            \Drupal::logger('api_proxy_pbs')->error('Failed to fetch fortnight schedule: @message', [
                '@message' => $e->getMessage(),
            ]);
            
            // Return empty schedule on error
            return [];
        }
    }

    /**
     * Handle Exceptions
     * @param  Exception $e the exception
     * @return CacheableJsonResponse
     */
    protected function handleException(Exception $e)
    {
        if ($e instanceof Rest404Exception) {
            return new JsonResponse(
                ['error' => $e->getMessage()],
                404
            );
        } elseif ($e instanceof Rest403Exception) {
            return new JsonResponse(
                ['error' => $e->getMessage()],
                403
            );
        }

        return new JsonResponse(
            ['error' => 'Internal server error.'],
            500
        );
    }
}
