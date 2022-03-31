<?php

namespace Drupal\api_proxy_pbs\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;

class ScheduleController
{
    /**
     * @return JsonResponse
     */
    public function index()
    {
        return new JsonResponse($this->getFortnightSchedule());
    }

    /**
     * @return insomnia shows
     */
    public function getFortnightSchedule()
    {
        // Fetch schedule:
        $schedule = $this->getSchedule();
        // Fetch programs:
        $programs = $this->getPrograms();
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

        if ($two_week !== null) {
            // if (count($programs) > 0) {

            // Loop through the entire fortnight schedule:
            $two_week = array_map(function ($og_program) use (
                $programs,
                $insomnia_lookup
            ) {
                // global $insomnia_lookup, $programs;

                // Week 0 or 1:
                $week = ((int) $og_program) <= 7;
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

            return $two_week;
        } else {
            // Fallback on original $schedule?
            return null;
        }
    }

    /**
     * @return Airnet schedule
     */
    public function getSchedule()
    {
        return $this->getJSON(
            'http://airnet.org.au/rest/stations/3pbs/guides/fm'
        );
    }
    /**
     * @return Airnet programs
     */
    public function getPrograms()
    {
        return $this->getJSON(
            'http://airnet.org.au/rest/stations/3pbs/programs'
        );
    }

    /**
     * @return Perform curl request with url
     */
    function getJSON(string $url)
    {
        // Execute curl request for `url`:
        $handle = curl_init($url);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, true);
        $session = curl_exec($handle);
        curl_close($handle);

        if ($session !== false) {
            // json decode response if request was successful:
            return json_decode($session, true);
        } else {
            return null;
        }
    }
}
