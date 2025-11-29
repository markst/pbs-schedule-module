<?php

namespace Drupal\api_proxy_pbs\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;

use Psr\Log\LoggerInterface;

/**
 * Provides a StreamController for handling audio streaming via Omny Studio API.
 */
class StreamController extends ControllerBase
{
    private $baseUrl = 'https://api.omny.fm/';
    private $logger;

    /**
     * Constructs a StreamController object.
     *
     * @param LoggerInterface $logger
     *   A logger instance.
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Creates an instance of the StreamController.
     *
     * @param ContainerInterface $container
     *   The service container.
     *
     * @return StreamController
     *   An instance of StreamController.
     */
    public static function create(ContainerInterface $container)
    {
        return new static(
            $container->get('logger.channel.default')
        );
    }

    /**
     * Fetches the audio URL from the API endpoint for the given slug and date,
     * attempting three different methods to find the correct clip.
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
            // Force the time zone to AEST when creating the DateTime object
            $dateTime = \DateTime::createFromFormat('Y-m-d H:i:s', $date, new \DateTimeZone('Australia/Sydney'));
            if (!$dateTime) {
                throw new \Exception('Invalid date format');
            }

            // Format the date for the URL
            $formattedDate = $dateTime->format('j-F-Y');

            // First attempt: Try fetching the clip with the original slug
            $apiUrl = $this->baseUrl . "programs/{$slug}/clips/{$slug}-{$formattedDate}";
            $response = @file_get_contents($apiUrl);
            $data = $response ? json_decode($response, true) : null;

            if (!$data || !isset($data['PublishState'])) {
                // Second attempt: Try fetching the clip with the Omny slug
                $omnySlug = $this->getOmnySlug($slug);
                if (!$omnySlug) {
                    throw new \Exception('No matching Omny slug found for the provided Airnet slug.');
                }
                $apiUrl = $this->baseUrl . "programs/{$omnySlug}/clips/{$slug}-{$formattedDate}";
                $response = @file_get_contents($apiUrl);
                $data = $response ? json_decode($response, true) : null;

                // Attempt again with omnySlug / omnySlug slugs?
                // $apiUrl = $this->baseUrl . "programs/{$omnySlug}/clips/{$slug}-{$formattedDate}";

                if (!$data || !isset($data['PublishState'])) {
                    // Third attempt: Use findClipByDate to search through clips by date range
                    $data = $this->findClipByDate($omnySlug, $dateTime);

                    if (!$data || !isset($data['PublishState'])) {
                        throw new \Exception("Unable to find a clip for the date {$dateTime->format('Y-m-d')} with slug {$omnySlug} after multiple attempts.");
                    }
                }
            }

            // Check if PublishState is "Published"
            if ($data['PublishState'] === 'Published' && isset($data['AudioUrl'])) {
                // Redirect to the audio URL
                return new TrustedRedirectResponse($data['AudioUrl']);
            }

            // If PublishState is not "Published" or AudioUrl is not available, throw an error
            throw new \Exception('Audio is not published.');
        } catch (\Exception $e) {
            // Log the error
            $this->logger->error('Error in getAudioUrl: @message', ['@message' => $e->getMessage()]);

            // Return an error response with the API URL if an exception occurs
            return new JsonResponse(['error' => $e->getMessage(), 'api_url' => $apiUrl ?? 'N/A'], 400);
        }
    }

    /**
     * Fetches the Omny slug for a given Airnet slug.
     *
     * @param string $airnetSlug
     *   The Airnet slug.
     *
     * @return string|null
     *   The Omny slug, or null if not found.
     */
    function getOmnySlug($airnetSlug): ?string
    {
        try {
            /*
            Using localhost doesn't seem to work:
            $base_url = \Drupal::request()->getSchemeAndHttpHost();
            */
            $base_url = 'http://dev.schedule.pbsfm.org.au';
            $apiUrl = $base_url . '/api/omny-programs';
            $response = file_get_contents($apiUrl);

            $programMapping = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('JSON decode error: ' . json_last_error_msg());
            }

            if (isset($programMapping[$airnetSlug])) {
                return $programMapping[$airnetSlug];
            } else {
                return null; // Explicitly return null if no matching program is found
            }
        } catch (\Exception $e) {
            $this->logger->error('Error fetching Omny slug: @message', ['@message' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Searches for a clip by date within a program's clips using the Omny Studio API.
     *
     * This method fetches clips page by page from the Omny Studio API until a clip
     * with the specified target date is found. It utilizes pagination to efficiently
     * search through potentially large datasets by adjusting the page size after the
     * first request.
     *
     * @param string $programSlug
     *   The slug identifier for the program from which clips are fetched.
     * @param \DateTime $targetDate
     *   The target date to find the clip for, as a DateTime object.
     *
     * @return array|null
     *   An associative array representing the clip with the matching date if found,
     *   or null if no matching clip is found.
     *
     * @throws \Exception
     *   Throws an exception if the API call fails or returns an invalid response.
     */
    function findClipByDate(string $programSlug, \DateTime $targetDate): ?array
    {
        $pageSize = 10; // Fixed page size of 10 items
        $cursor = null;
        $foundClip = null;

        // Define a buffer in minutes
        $bufferInMinutes = 1;

        // Convert targetDate to UTC for comparison
        $targetDateUtc = clone $targetDate;
        $targetDateUtc->setTimezone(new \DateTimeZone('UTC'));

        do {
            $url = $this->buildUrl($programSlug, $pageSize, $cursor);
            $this->logger->info("Fetching clips with url $url");

            try {
                $response = file_get_contents($url);

                if ($response === false) {
                    throw new \Exception('Failed to fetch data from API.');
                }

                $responseData = json_decode($response, true);
                $clips = $responseData['Clips'] ?? [];
                $cursor = $responseData['Cursor'] ?? null;

                foreach ($clips as $clip) {
                    $recordingMetadata = $clip['RecordingMetadata'] ?? null;

                    if ($recordingMetadata) {
                        $captureStart = new \DateTime($recordingMetadata['CaptureStartUtc']);
                        $captureEnd = new \DateTime($recordingMetadata['CaptureEndUtc']);

                        // Apply buffer to the capture start and end times
                        $captureStart->modify("-{$bufferInMinutes} minutes");
                        $captureEnd->modify("+{$bufferInMinutes} minutes");

                        // Check if the target date (now in UTC) is within the modified capture start and end
                        if ($targetDateUtc >= $captureStart && $targetDateUtc <= $captureEnd) {
                            $foundClip = $clip;
                            break;
                        }
                    }
                }

                if ($foundClip) {
                    break;
                }
            } catch (\Exception $e) {
                $this->logger->error('Error in findClipByDate: @message', ['@message' => $e->getMessage()]);
                return null;
            }
        } while ($cursor);  // Continue looping as long as the cursor is not null

        return $foundClip;
    }

    /**
     * Constructs a URL for fetching clips with pagination support.
     *
     * @param string $programSlug
     *   The slug identifier for the program.
     * @param int $pageSize
     *   The number of clips to retrieve per page.
     * @param string|null $cursor
     *   The pagination cursor indicating the position for fetching the next set of results.
     *
     * @return string
     *   The constructed URL with query parameters.
     */
    private function buildUrl(string $programSlug, int $pageSize, ?string $cursor): string
    {
        $queryParams = http_build_query([
            'pageSize' => $pageSize,
            'cursor' => $cursor,
        ]);

        return $this->baseUrl . "programs/{$programSlug}/clips?" . $queryParams;
    }
}
