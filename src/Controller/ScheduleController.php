<?php

namespace Drupal\api_proxy_pbs\Controller;

use Drupal\api_proxy_pbs\Controller\SubRequestController;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;

use Symfony\Component\DependencyInjection\ContainerInterface;

class ScheduleController extends ControllerBase
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
    public function index()
    {
        try {
            $data = $this->getFortnightSchedule();

            $response = new CacheableJsonResponse($data);
            // Configurable`admin/config/development/performance`:
            $response->headers->set('Cache-Control', 'public, max-age=86402');
            $response->headers->set(
                'Content-Type',
                'application/json; charset=utf-8'
            );

            $response->headers->addCacheControlDirective('public');
            $response->addCacheableDependency(
                CacheableMetadata::createFromRenderArray([
                    // Add Cache settings for Max-age and URL context.
                    '#cache' => [
                        'max-age' => 86401,
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
    public function getFortnightSchedule()
    {
        // Fetch schedule:
        $schedule = $this->subRequestController->getJSONSubrequest(
            '/rest/stations/3pbs/guides/fm'
        );
        // Fetch programs:
        $programs = $this->subRequestController->getJSONSubrequest(
            '/rest/stations/3pbs/programs'
        );
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
