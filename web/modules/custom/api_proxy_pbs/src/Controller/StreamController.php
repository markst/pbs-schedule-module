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
     * Retrieves the Omny slug and formatted program name that match the given initial slug.
     * This method is used when the initial attempt to fetch program data fails, 
     * indicating a possible mismatch between the provided slug and the Omny slug naming conventions.
     *
     * @param string $airnetSlug
     *   The initial program slug, typically from Airnet.
     *
     * @return array|false
     *   Returns an associative array with 'slug' and 'formattedName' if a match is found,
     *   otherwise false if no match is found.
     */
    public function getOmnySlug($airnetSlug)
    {
        try {
            // Fetch all programs from Omny for the organization
            $apiUrl = "https://api.omny.fm/orgs/1270a58a-2c51-457c-b8c6-aced0086cad6/programs";
            $response = file_get_contents($apiUrl);
            $omnyPrograms = json_decode($response, true);

            if (!$omnyPrograms || !isset($omnyPrograms['Programs'])) {
                \Drupal::logger('api_proxy_pbs')->error('Invalid or no response from Omny API while fetching programs.');
                throw new \Exception('Invalid response from API');
            }

            foreach ($omnyPrograms['Programs'] as $omnyProgram) {
                $slugPercent = 0;
                similar_text(strtolower($omnyProgram['Slug']), strtolower($airnetSlug), $slugPercent);

                if ($slugPercent > 80) { // Threshold can be adjusted based on specific needs
                    // Format the program name according to the specified rules
                    $formattedName = strtolower($omnyProgram['Name']);
                    $formattedName = str_replace(' ', '-', $formattedName);
                    $formattedName = preg_replace('/[^a-z0-9-]/', '', $formattedName);

                    \Drupal::logger('api_proxy_pbs')->info("Matching program found: {$omnyProgram['Slug']} with similarity {$slugPercent}%.");

                    // Return both slug and formatted program name
                    return [
                        'slug' => $omnyProgram['Slug'],
                        'formattedName' => $formattedName
                    ];
                }
            }

            \Drupal::logger('api_proxy_pbs')->notice("No matching program found for slug: {$airnetSlug}.");
            return false;
        } catch (\Exception $e) {
            \Drupal::logger('api_proxy_pbs')->error("Exception encountered while fetching programs: {$e->getMessage()}");
            return false;
        }
    }
}
