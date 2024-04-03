<?php

namespace Drupal\api_proxy_pbs\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;

class StreamController extends ControllerBase
{
    /**
     * Fetches the audio URL from the API endpoint for the given program and date.
     *
     * @param string $program
     *   The program slug.
     * @param string $date
     *   The episode ID in the format YYYY-MM-DD HH:MM:SS.
     *
     * @return TrustedRedirectResponse|JsonResponse
     *   Redirect response to the audio URL or an error message.
     */
    public function getAudioUrl($program, $date)
    {
        try {
            // Attempt to parse the date
            $dateTime = \DateTime::createFromFormat('Y-m-d H:i:s', $date);
            if (!$dateTime) {
                throw new \Exception('Invalid date format');
            }

            // Format the date
            $formattedDate = $dateTime->format('j-F-Y');

            // Generate the API URL based on the program and formatted date
            $apiUrl = "https://omny.fm/api/programs/{$program}/clips/{$program}-{$formattedDate}";

            // Fetch data from the API endpoint
            $response = file_get_contents($apiUrl);

            // Check if the response is valid JSON
            $data = json_decode($response, true);
            if (!$data || !isset($data['PublishState'])) {
                // Return an error response with the API URL
                throw new \Exception('Invalid response from API');
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
     * Fetches the show name from the program data.
     *
     * @param string $program
     *   The program slug.
     *
     * @return string|false
     *   The show name if found, otherwise false.
     */
    public function getShowName($program)
    {
        try {
            // Fetch program data from the API endpoint
            $programData = json_decode(file_get_contents("https://omny.fm/api/programs/{$program}"), true);
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
}
