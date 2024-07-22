<?php

namespace Drupal\api_proxy_pbs\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;

class StreamController extends ControllerBase
{
    /**
     * Fetches the audio URL from the API endpoint for the given slug and date,
     * constructing the URL with the formatted program name if necessary.
     *
     * @param string $slug
     *   The initial program slug (typically from Airnet).
     * @param string $date
     *   The episode date in the format YYYY-MM-DD HH:MM:SS.
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

            // Format the date for the URL
            $formattedDate = $dateTime->format('j-F-Y');

            // Generate the initial API URL based on the slug
            $apiUrl = "https://omny.fm/api/programs/{$slug}/clips/{$slug}-{$formattedDate}";

            // Fetch data from the API endpoint
            $response = file_get_contents($apiUrl);
            $data = json_decode($response, true);

            if (!$data || !isset($data['PublishState'])) {
                // Fetch the Omny slug and formatted program name if initial attempt fails
                $slugInfo = $this->getOmnySlug($slug);
                if (!$slugInfo) {
                    throw new \Exception('Failed to fetch the Omny slug and formatted name');
                }

                // Construct a new API URL using both the slug and the formatted program name
                $apiUrl = "https://omny.fm/api/programs/{$slugInfo['slug']}/clips/{$slugInfo['formattedName']}-{$formattedDate}";
                $response = file_get_contents($apiUrl);
                $data = json_decode($response, true);

                if (!$data || !isset($data['PublishState'])) {
                    // TODO: Log error or rely on exception?
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
     * Fetches the Omny slug and formatted program name for a given Airnet slug.
     *
     * @param string $airnetSlug
     *   The Airnet slug.
     *
     * @return array|null
     *   An array containing the Omny slug and formatted program name, or null on failure.
     */
    public function getOmnySlug($airnetSlug)
    {
        try {
            $base_url = \Drupal::request()->getSchemeAndHttpHost();
            $apiUrl = $base_url . '/api/omny-programs';
            /*
            $apiUrl = $GLOBALS['base_url'] . '/api/omny-programs';
            */
            $response = file_get_contents($apiUrl); // TODO: Identify if we're safe to use `file_get_contents`
            $programMapping = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('JSON decode error: ' . json_last_error_msg());
            }

            if (isset($programMapping[$airnetSlug])) {
                $omnySlug = $programMapping[$airnetSlug];
                $formattedName = $omnySlug; // TODO: Formatted name different from omny slug?
                return [
                    'slug' => $omnySlug,
                    'formattedName' => $formattedName
                ];
            } else {
                throw new \Exception("No matching program found. $apiUrl");
            }
        } catch (\Exception $e) {
            \Drupal::logger('Error fetching Omny slug: @message', ['@message' => $e->getMessage()]);
            return null;
        }
    }
}
