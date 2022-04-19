<?php

namespace Drupal\api_proxy_pbs\Controller;

use Drupal\api_proxy_pbs\Controller\SubRequestController;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

use DateTime;
use DateTimeZone;

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
            $ttl = 43200;
            $data = $this->getFortnightSchedule();

            $response = new CacheableJsonResponse($data);
            $response->setPublic();
            $response->setMaxAge($ttl); // Configurable `admin/config/development/performance`
            $response->setExpires(new \DateTime('@' . (REQUEST_TIME + $ttl)));
            $response->headers->set(
                'Content-Type',
                'application/json; charset=utf-8'
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
        // Get the insomnia lookup:
        $insomnia_lookup = $this->insomniaMap();

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

                    if ($i == false) {
                        return $og_program;
                    }

                    $new_program = $programs[$i];

                    // Carry existing attributes:
                    $new_program['day'] = $og_program['day'];
                    $new_program['start'] = $og_program['start'];
                    $new_program['duration'] =
                        $og_program['duration'] != null
                            ? $og_program['duration']
                            : 14400;
                    $new_program['profileImage'] =
                        $new_slug_info['profileImage'];

                    // Remove stale `onairnow`:
                    unset($new_program['onairnow']);
                    // Set the ISO 8601 date;
                    $new_program['startTime'] = $this->startDate($og_program);

                    return $new_program;
                    break;
                default:
                    // Remove unused attributes:
                    unset($og_program['onairnow']);
                    unset($og_program['bannerImageSmall']);
                    unset($og_program['profileImageSmall']);
                    unset($og_program['url']);
                    // Set the ISO 8601 date;
                    $og_program['startTime'] = $this->startDate($og_program);

                    return $og_program;
                    break;
            }
        },
        $two_week);
    }

    /**
     * Insomnia lookup
     * @return json lookup table
     */
    public function getInsomniaMap()
    {
        return new JsonResponse($this->insomniaMap());
    }

    protected function insomniaMap()
    {
        $config = \Drupal::config('api_proxy_pbs.settings');
        $body = $config->get('insomnia_lookup');

        return json_decode(
            $body ?: file_get_contents(__DIR__ . '/../insomnia-lookup.json'),
            true
        );
    }

    /**
     * Format date from components
     * @return string ISO 8601 date string.
     */
    protected function startDate($program)
    {
        $times = explode(':', $program['start']);
        $dt = new DateTime('now', new DateTimeZone('Australia/Melbourne'));
        return $dt
            ->setISODate(
                date('Y'),
                date('W') - (date('W') % 2),
                $program['day']
            )
            ->setTime($times[0], $times[1])
            ->format('c');
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
