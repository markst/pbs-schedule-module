<?php

/**
 * @file
 * Contains \Drupal\pbs_airnet_api\Controller\PBSAirnetController.
 */

namespace Drupal\pbs_airnet_api\Controller;

use DateInterval;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Controller\ControllerBase;
use \GuzzleHttp\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\api_proxy_pbs\Controller\ScheduleController;


/**
 * Cache demo main page.
 */
class PBSAirnetController extends ControllerBase {

  /**
   * @var CacheBackendInterface
   */
  protected $cacheBackend;

  /**
   * Class constructor.
   */
  public function __construct(CacheBackendInterface $cacheBackend) {
    $this->cacheBackend = $cacheBackend;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('cache.default')
    );
  }

  /**
   * API Function timestamp lookup returns Program Name.
   *
   * @return CacheableJsonResponse
   */
  public function showname($date) {
    try {
      // Clear cache
      if ($date == 'clear') {
        $this->clearJson('pbsapi_programs');
        $data = "Cache Cleared.";
      }

      // Lookup Program Name
      else {
        $time_offset = 1 * 60 * 60;
        $programs = $this->loadJson('https://schedule.pbsfm.org.au/api/fortnight', 'pbsapi_programs', $time_offset);
        $data = [];

        foreach ($programs['data'] as $program) {
          $slug = $program->slug;

          $episodes = $this->loadJson('https://airnet.org.au/rest/stations/3pbs/programs/' . $slug . '/episodes', 'pbsapi_' . $slug, $time_offset);

          foreach ($episodes['data'] as $episode) {
            $start_date = str_replace(' ', '', $episode->start);
            $start_date = preg_replace('/[^A-Za-z0-9]/', '', $start_date);
            $end_date = str_replace(' ', '', $episode->end);
            $end_date = preg_replace('/[^A-Za-z0-9]/', '', $end_date);

            if ($date >= $start_date && $date < $end_date) {
              $match = [
                'program' => $program->name,
                'start' => $start_date,
              ];
              $data[] = $match;
            }
          }
        }
        $data_sorted = $this->array_orderby($data, 'start', SORT_DESC);

        if (count($data_sorted) >= 1) {
          $data = $data_sorted[0]['program'];
        }
      }

      $ttl = 1 * 60 * 60;
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
          'pbs_airnet_api'
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
    }
    catch
    (Exception $e) {
      return $this->handleException($e);
    }
  }

  /**
   * API Function timestamp lookup returns Program Name based on an ongoing fortnightly schedule.
   *
   * @return CacheableJsonResponse
   */
  public function schedule($date) {
    try {
      // Clear cache
      if ($date == 'clear') {
        $this->clearJson('pbsapi_programs');
        $data = "Cache Cleared.";
      }

      // Lookup Program Name
      else {
        // Cache time.
        $time_offset = 12 * 60 * 60;

        // Specify a Monday date in the past.
        $base_date = date_create("20100104000000");

        // Convert Search query to date and time objects.
        $search_date = date_create($date);
        $search_day = substr($date, 0,8);

        // Load the fortnightly program data.
        $programs = $this->loadJson('https://schedule.pbsfm.org.au/api/fortnight', 'pbsapi_programs', $time_offset);

        foreach ($programs['data'] as $program) {
          // Find the Program's corresponding day of a fortnightly schedule.
          $diff_date = $base_date->diff($search_date);
          $day = ($diff_date->days % 14) + 1;

          // Match a Program day to the search day.
          if ($program->day == $day) {
            $search = $search_day . substr($program->startTime, 10);
            $start_date = date_create($search);
            $end_date = date_create($search);
            $end_date = $end_date->add(new DateInterval('PT' . $program->duration . 'S'));

            // Match the Program by the search time.
            if ($search_date >= $start_date  && $search_date < $end_date) {
              $data = $program->name;
              break;
            }
          }
        }
      }

      $ttl = 1 * 60 * 60;
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
          'pbs_airnet_api'
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
    }
    catch
    (Exception $e) {
      return $this->handleException($e);
    }
  }

  /**
   * Loads Json from cache or API.
   *
   * @return array
   */
  private function loadJson($request_uri, $cache_name, $time_offset) {
    if ($cache = $this->cacheBackend->get($cache_name)) {
      return [
        'data' => $cache->data,
        'means' => 'cache',
      ];
    }
    else {
      $guzzle = new Client();
      $response = $guzzle->get($request_uri);
      $json = json_decode($response->getBody());

      // Store the Json response in cache.
      $time_now = \Drupal::time()->getCurrentTime();
      $this->cacheBackend->set($cache_name, $json, $time_now + $time_offset);
      return [
        'data' => $json,
        'means' => 'API',
      ];
    }
  }

  /**
   * Clears the stored JSON from the cache.
   */
  function clearJson($cache_name) {
    $programs = $this->loadJson('https://airnet.org.au/rest/stations/3pbs/guides/fm', $cache_name .'_programs', CacheBackendInterface::CACHE_PERMANENT);

    foreach ($programs['data'] as $key => $program) {
      $slug = $program->slug;
      $this->cacheBackend->delete($cache_name . '_' . $slug);
    }
    $this->cacheBackend->delete($cache_name);
  }

  /**
   * Function to order array.
   */
  function array_orderby()
  {
    $args = func_get_args();
    $data = array_shift($args);
    foreach ($args as $n => $field) {
      if (is_string($field)) {
        $tmp = array();
        foreach ($data as $key => $row)
          $tmp[$key] = $row[$field];
        $args[$n] = $tmp;
      }
    }
    $args[] = &$data;
    call_user_func_array('array_multisort', $args);
    return array_pop($args);
  }
}


