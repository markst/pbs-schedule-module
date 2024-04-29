<?php

namespace Drupal\api_proxy_pbs\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;

class StreamController extends ControllerBase
{
    /**
     * Fetches the audio URL from the API endpoint for the given slug and date.
     *
     * @param string $slug
     *   The program slug.
     * @param string $date
     *   The episode ID in the format YYYY-MM-DD HH:MM:SS.
     *
     * @return TrustedRedirectResponse|JsonResponse
     *   Redirect response to the audio URL or an error message.
     */
    public function getAudioUrl($slug, $date)
    {
        try {
            // Attempt to parse the date
            $dateTime = \DateTime::createFromFormat('Y-m-d H:i:s', $date);
            if (!$dateTime) {
                throw new \Exception('Invalid date format');
            }

            // Format the date
            $formattedDate = $dateTime->format('j-F-Y');

            // Generate the API URL based on the slug and formatted date
            $apiUrl = "https://omny.fm/api/programs/{$slug}/clips/{$slug}-{$formattedDate}";

            // Fetch data from the API endpoint
            $response = file_get_contents($apiUrl);

            // Check if the response is valid JSON
            $data = json_decode($response, true);

            if (!$data || !isset($data['PublishState'])) {
                // Fetch the show name
                $showName = $this->getShowName($slug);
                if (!$showName) {
                    throw new \Exception('Invalid response from API');
                }
                // Extract clip name from the show name and retry fetching data
                $clipName = str_replace(' ', '-', $showName) . '-' . $formattedDate;
                $apiUrl = "https://omny.fm/api/programs/{$slug}/clips/{$clipName}";

                // Retry fetching data with the updated API URL
                $response = file_get_contents($apiUrl);
                $data = json_decode($response, true);
                if (!$data || !isset($data['PublishState'])) {
                    throw new \Exception('Invalid response from API');
                }
            }

            // Check if PublishState is "Published"
            if ($data['PublishState'] === 'Published' && isset($data['AudioUrl'])) {
                // Redirect to the audio URL
                return new TrustedRedirectResponse($data['AudioUrl']);
            }

            // If PublishState is not "Published" or AudioUrl is not available, throw an error
            throw new \Exception('Audio is not published');
        } catch (\Exception $e) {
            // Return an error response with the API URL if an exception occurs
            return new JsonResponse(['error' => $e->getMessage(), 'api_url' => $apiUrl], 400);
        }
    }

    /**
     * Fetches the show name from the program slug.
     *
     * @param string $slug
     *   The program slug.
     *
     * @return string|false
     *   The show name if found, otherwise false.
     */
    public function getShowName($slug)
    {
        try {
            // Fetch program data from the API endpoint
            $programData = json_decode(file_get_contents("https://omny.fm/api/programs/{$slug}"), true);
            if (!$programData || !isset($programData['Name'])) {
                throw new \Exception('Invalid response from API');
            }

            // Extract and normalize the show name
            $showName = $programData['Name'];
            $showName = strtolower($showName); // Convert to lowercase
            $showName = preg_replace('/[^a-z0-9-]/', '', $showName); // Remove unexpected characters
            $showName = str_replace(' ', '-', $showName); // Replace spaces with hyphens

            return $showName;
        } catch (\Exception $e) {
            // Log or handle the exception as needed
            return false;
        }
    }

    /**
     * Fetches programs from the given URL and returns them as an associative array.
     *
     * @param string $url The URL to fetch the programs from.
     * @return array The list of programs as an associative array.
     */
    function fetchPrograms($url)
    {
        $response = file_get_contents($url);
        return json_decode($response, true);
    }

    /**
     * Match programs by slug from Airnet with their corresponding programs on Omny.
     *
     * @return JsonResponse
     *   A JSON response containing the mapping of Airnet programs to Omny programs.
     */
    public function getProgramMapping()
    {
        $airnetPrograms = $this->fetchPrograms('http://dev.schedule.pbsfm.org.au/api/fortnight');
        $omnyPrograms = $this->fetchPrograms('https://api.omny.fm/orgs/1270a58a-2c51-457c-b8c6-aced0086cad6/programs');

        // Filter out programs with slug as string "null" or empty string from Airnet
        $filteredAirnetPrograms = array_filter($airnetPrograms, function ($program) {
            return isset($program['slug']) && $program['slug'] !== "null" && $program['slug'] !== "";
        });

        $programMapping = [];

        // Match programs by slug
        foreach ($filteredAirnetPrograms as $airnetProgram) {
            $airnetSlug = $airnetProgram['slug'];
            $airnetName = $airnetProgram['name'];


            foreach ($omnyPrograms['Programs'] as $omnyProgram) {
                // Try matching by slug first
                similar_text($omnyProgram['Slug'], $airnetSlug, $slugPercent);
                if ($slugPercent > 99) {
                    // Add the mapping to the result
                    $programMapping[$airnetName] = [
                        'omny_program_id' => $omnyProgram['Id'],
                        'omny_program_name' => $omnyProgram['Name'],
                        'omny_program_slug' => $omnyProgram['Slug'],
                        'airnet_program_slug' => $airnetProgram['slug'],
                        'slug_match' => $slugPercent,
                    ];
                    break; // No need to continue if we've found a match
                }

                // Then match program
                similar_text($omnyProgram['Name'], $airnetName, $namePercent);
                if ($namePercent > 80) {
                    // Add the mapping to the result
                    $programMapping[$airnetName] = [
                        'omny_program_id' => $omnyProgram['Id'],
                        'omny_program_name' => $omnyProgram['Name'],
                        'omny_program_slug' => $omnyProgram['Slug'],
                        'airnet_program_slug' => $airnetProgram['slug'],
                        'program_match' => $namePercent,
                    ];
                    break; // No need to continue if we've found a match
                }
            }
        }

        return new JsonResponse($programMapping);
    }
}
