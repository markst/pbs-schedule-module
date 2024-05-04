<?php

namespace Drupal\api_proxy_pbs\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;

class StreamController extends ControllerBase
{
    /**
     * Fetches the audio URL from the API endpoint for the given slug and date, 
     * trying with the initial slug and then retrieving the Omny slug if the initial attempt fails.
     *
     * @param string $slug
     *   The Airnet program slug.
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

            // Generate the API URL based on the initial slug and formatted date
            $apiUrl = "https://omny.fm/api/programs/{$slug}/clips/{$slug}-{$formattedDate}";

            // Fetch data from the API endpoint
            $response = file_get_contents($apiUrl);
            $data = json_decode($response, true);

            if (!$data || !isset($data['PublishState'])) {
                // Fetch the Omny slug using the initial slug and name
                $omnySlug = $this->getOmnySlug($slug);
                if (!$omnySlug) {
                    throw new \Exception('Failed to fetch the Omny slug');
                }

                // Retry with the Omny slug
                $apiUrl = "https://omny.fm/api/programs/{$omnySlug}/clips/{$omnySlug}-{$formattedDate}";
                $response = file_get_contents($apiUrl);
                $data = json_decode($response, true);

                if (!$data || !isset($data['PublishState'])) {
                    throw new \Exception('Invalid response from API after retry');
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
            return new JsonResponse(['error' => $e->getMessage(), 'api_url' => isset($apiUrl) ? $apiUrl : 'N/A'], 400);
        }
    }

    /**
     * Retrieves the Omny slug that matches the given initial slug and program name. 
     * This method is used when the initial attempt to fetch program data fails, 
     * indicating a possible mismatch between Airnet and Omny slug naming conventions.
     *
     * @param string $airnetSlug
     *   The initial program slug, typically from Airnet.
     *
     * @return string|false
     *   Returns the matching Omny slug if found, otherwise false if no match is found.
     */
    public function getOmnySlug($airnetSlug)
    {
        try {
            // Fetch all programs from Omny for the organization
            $apiUrl = "https://api.omny.fm/orgs/1270a58a-2c51-457c-b8c6-aced0086cad6/programs";
            $response = file_get_contents($apiUrl);
            $omnyPrograms = json_decode($response, true);

            if (!$omnyPrograms || !isset($omnyPrograms['Programs'])) {
                throw new \Exception('Invalid response from API');
            }

            // Loop through each program to find the best match based on slug and name
            foreach ($omnyPrograms['Programs'] as $omnyProgram) {
                $slugPercent = 0;
                similar_text(strtolower($omnyProgram['Slug']), strtolower($airnetSlug), $slugPercent);
                // Check if the similarity for either slug or name is above the thresholds
                if ($slugPercent > 90) { // Threshold can be adjusted based on specific needs
                    return $omnyProgram['Slug'];
                }
            }

            // No suitable match found, return false
            return false;
        } catch (\Exception $e) {
            // Log the error or handle it as needed
            return false;
        }
    }
}
