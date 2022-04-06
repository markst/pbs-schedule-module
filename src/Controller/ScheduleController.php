<?php

namespace Drupal\api_proxy_pbs\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Url;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class ScheduleController extends ControllerBase implements
    ContainerInjectionInterface
{
    /**
     * Symfony\Component\HttpKernel\HttpKernelInterface definition.
     *
     * @var Symfony\Component\HttpKernel\HttpKernelInterface
     */
    protected $httpKernel;

    public function __construct(HttpKernelInterface $http_kernel)
    {
        $this->httpKernel = $http_kernel;
    }

    public static function create(ContainerInterface $container)
    {
        return new static($container->get('http_kernel.basic'));
    }

    /**
     * Main index.
     * @return CacheableJsonResponse
     */
    public function index()
    {
        try {
            $data = $this->getFortnightSchedule();

            // Add Cache settings for Max-age and URL context.
            $cache_metadata = [
                'max-age' => 86401,
                'contexts' => ['url', 'url.query_args:x'],
            ];

            $response = new CacheableJsonResponse($data);
            // Configurable`admin/config/development/performance`:
            $response->headers->set('Cache-Control', 'public, max-age=86402');
            $response->headers->set(
                'Content-Type',
                'application/json; charset=utf-8'
            );

            $response->headers->addCacheControlDirective('public');
            $response->addCacheableDependency(
                CacheableMetadata::createFromRenderArray($cache_metadata)
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
    public function getFortnightSchedule()
    {
        // Fetch schedule:
        $schedule = $this->subrequest('/rest/stations/3pbs/guides/fm');
        // Fetch programs:
        $programs = $this->subrequest('/rest/stations/3pbs/programs');
        // Get the contents of the JSON file:
        $insomnia_lookup = json_decode(
            file_get_contents(__DIR__ . '/../insomnia-lookup.json'),
            true
        );

        // Merge two weeks together:
        $two_week = array_merge(
            $schedule,
            // First append 7 to `day` of second weeks.
            array_map(function ($program) {
                $program['day'] = strval(((int) $program['day']) + 7);
                return $program;
            }, $schedule)
        );

        if ($two_week === null) {
            // if (count($programs) > 0) {
            // Fallback on original $schedule?
            return $schedule;
        }

        // Loop through the entire fortnight schedule:
        return array_map(function ($og_program) use (
            $programs,
            $insomnia_lookup
        ) {
            // global $insomnia_lookup, $programs;

            // Week 0 or 1:
            $week = ((int) $og_program['day']) > 7;

            // For each insomnia program:
            switch ($og_program['slug']) {
                case 'insomnia_monday':
                case 'insomnia_tuesday':
                case 'insomnia_wednesday':
                case 'insomnia_thursday':
                case 'insomnia_friday':
                case 'insomnia_sunday':
                    // do lookup on insomnialookup for correct slug:
                    $old_slug = $og_program['slug'];
                    $new_slug_info = $insomnia_lookup[$old_slug][$week];

                    $i = array_search(
                        $new_slug_info['slug'],
                        array_column($programs, 'slug')
                    );

                    $new_program = $programs[$i];

                    if ($new_program == null) {
                        return $og_program;
                    }

                    // Carry existing attributes:
                    $new_program['day'] = $og_program['day'];
                    $new_program['start'] = $og_program['start'];
                    $new_program['duration'] =
                        $og_program['duration'] == null
                            ? $og_program['duration']
                            : 7200;
                    $new_program['profileImage'] =
                        $new_slug_info['profileImage'];

                    // Remove stale `onairnow`:
                    unset($new_program['onairnow']);
                    return $new_program;
                    break;
                default:
                    // Remove unused attributes:
                    unset($og_program['onairnow']);
                    unset($og_program['bannerImageSmall']);
                    unset($og_program['profileImageSmall']);
                    unset($og_program['url']);
                    return $og_program;
                    break;
            }
        },
        $two_week);
    }

    /**
     * Airnet schedule
     * @return json array of scheduled programs
     */
    public function getSchedule()
    {
        return $this->subrequest('/rest/stations/3pbs/guides/fm');
    }

    /**
     * Airnet programs
     * @return json array of programs
     */
    public function getPrograms()
    {
        return $this->subrequest('/rest/stations/3pbs/programs');
    }

    /**
     * Perform subrequest request with uri
     * @return json object
     */
    protected function subrequest(string $uri)
    {
        $current_request = \Drupal::request();

        $path = Url::fromRoute(
            'api_proxy.forwarder',
            ['api_proxy' => 'airnet', '_api_proxy_uri' => $uri],
            []
        )
            ->toString(true)
            ->getGeneratedUrl();

        $sub_request = Request::create($path, 'GET', [
            'Host' => 'airnet.org.au',
        ]);

        // \Drupal::service('http_kernel')->handle($sub_request, HttpKernelInterface::SUB_REQUEST);
        // \Drupal::service('http_kernel.basic');

        $sub_response = $this->httpKernel->handle(
            $sub_request,
            HttpKernelInterface::SUB_REQUEST
        );

        $code = $sub_response->getStatusCode();

        // This hack is necessary, otherwise subsequent code will have the wrong route match!
        if (
            \Drupal::request()->getPathInfo() !==
            $current_request->getPathInfo()
        ) {
            \Drupal::requestStack()->pop();
        }

        if ($code == 200) {
            $content = $sub_response->getContent();
            return json_decode($content, true);
        }

        /*
        return [
            'data' => json_decode($sub_response->getContent(), true),
            'status' => $code,
        ];
        */

        return null;
    }

    /**
     * Perform request with url
     * @return json object
     */
    protected function getJSON(string $url)
    {
        $method = 'GET';
        $options = [];

        $client = \Drupal::httpClient();

        $response = $client->request($method, $url, $options);
        $code = $response->getStatusCode();

        if ($code == 200) {
            $body = $response->getBody()->getContents();
            return json_decode($body, true);
        }

        return null;
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
