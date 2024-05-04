<?php

namespace Drupal\api_proxy_pbs\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

class OmnyController extends ControllerBase
{
    /**
     * Fetches programs from the given URL and returns them as an associative array, using Drupal's cache when possible.
     *
     * @param string $url The URL to fetch the programs from.
     * @return array The list of programs as an associative array.
     */
    private function fetchPrograms($url)
    {
        $cid = 'programs_cache:' . md5($url); // Cache ID unique to the URL
        $cached_data = \Drupal::cache()->get($cid);

        if (!empty($cached_data)) {
            return $cached_data->data;
        } else {
            $response = file_get_contents($url);
            $data = json_decode($response, true);
            // Cache this data with a custom expiration time, e.g., 1 hour
            \Drupal::cache()->set($cid, $data, time() + 3600);
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

        // Attempt to retrieve from cache first
        $mappingCacheId = 'program_mapping_cache';
        $cachedMapping = \Drupal::cache()->get($mappingCacheId);

        if (!empty($cachedMapping)) {
            return new JsonResponse($cachedMapping->data);
        }

        $airnetPrograms = $this->fetchPrograms($airnetUrl);
        $omnyPrograms = $this->fetchPrograms($omnyUrl);

        $filteredAirnetPrograms = array_filter($airnetPrograms, function ($program) {
            return isset($program['slug']) && $program['slug'] !== "null" && $program['slug'] !== "";
        });

        $programMapping = [];

        foreach ($filteredAirnetPrograms as $airnetProgram) {
            $airnetSlug = $airnetProgram['slug'];
            $programMapping[$airnetSlug] = "no-match-found"; // Default to no match found

            foreach ($omnyPrograms['Programs'] as $omnyProgram) {
                similar_text($omnyProgram['Slug'], $airnetSlug, $slugPercent);
                if ($slugPercent > 80) {
                    $programMapping[$airnetSlug] = $omnyProgram['Slug'];
                    break; // Stop searching after finding a match
                }
            }
        }

        // Cache the final mapping result
        \Drupal::cache()->set($mappingCacheId, $programMapping, time() + 3600);

        return new JsonResponse($programMapping);
    }
}
