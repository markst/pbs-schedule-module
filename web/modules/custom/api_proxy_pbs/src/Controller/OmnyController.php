<?php

namespace Drupal\api_proxy_pbs\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Cache\CacheableJsonResponse;

class OmnyController extends ControllerBase
{
    private function fetchPrograms($url)
    {
        $cid = 'programs_cache:' . md5($url);
        $cached_data = \Drupal::cache()->get($cid);

        if (!empty($cached_data)) {
            return $cached_data->data;
        } else {
            $response = file_get_contents($url);
            $data = json_decode($response, true);
            \Drupal::cache()->set($cid, $data, time() + 3600); // Adjust cache time as needed
            return $data;
        }
    }

    /**
     * Match programs by slug from Airnet with their corresponding programs on Omny.
     *
     * @return JsonResponse
     *   A JSON response containing the mapping of Airnet programs to Omny programs.
     */
    public function getProgramMapping()
    {
        $airnetUrl = 'http://dev.schedule.pbsfm.org.au/api/fortnight';
        $omnyUrl = 'https://api.omny.fm/orgs/1270a58a-2c51-457c-b8c6-aced0086cad6/programs';

        $overrides = [
            "tigerbeats" => "tiger-beats-elephant-grooves",
            "blackheartsrevue" => "bleeding-black-hearts-revue",
            "lca" => "lights-camera-action"
        ];

        $airnetPrograms = $this->fetchPrograms($airnetUrl);
        $omnyPrograms = $this->fetchPrograms($omnyUrl);

        $filteredAirnetPrograms = array_filter($airnetPrograms, function ($program) {
            return isset($program['slug']) && $program['slug'] !== "null" && $program['slug'] !== "";
        });

        $programMapping = [];

        foreach ($filteredAirnetPrograms as $airnetProgram) {
            $airnetSlug = $airnetProgram['slug'];

            foreach ($omnyPrograms['Programs'] as $omnyProgram) {
                similar_text($omnyProgram['Slug'], $airnetSlug, $slugPercent);
                if ($slugPercent > 80) {
                    $programMapping[$airnetSlug] = $omnyProgram['Slug'];
                    break;
                } else if (array_key_exists($airnetSlug, $overrides)) {
                    $programMapping[$airnetSlug] = $overrides[$airnetSlug];
                } else {
                    $programMapping[$airnetSlug] = "no-match-found";
                    \Drupal::logger('api_proxy_pbs')->error("Unable to find program on Omny: $airnetSlug");
                }
            }
        }

        $response = new CacheableJsonResponse($programMapping);
        $response->addCacheableDependency((object)['tags' => ['http_response']]);
        return $response;
    }
}
