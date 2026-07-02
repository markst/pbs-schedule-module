<?php

namespace Drupal\api_proxy_pbs\Controller;

use Drupal\api_proxy_pbs\Service\JsonApiClient;
use Drupal\api_proxy_pbs\Service\SlugResolver;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;

use Psr\Log\LoggerInterface;

/**
 * Controller for audio streaming endpoints.
 */
class StreamController extends ControllerBase
{
    protected $jsonApiClient;
    protected $slugResolver;
    protected $logger;

    /**
     * Constructor.
     */
    public function __construct(JsonApiClient $jsonApiClient, SlugResolver $slugResolver, LoggerInterface $logger)
    {
        $this->jsonApiClient = $jsonApiClient;
        $this->slugResolver = $slugResolver;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container)
    {
        return new static(
            $container->get('api_proxy_pbs.jsonapi_client'),
            $container->get('api_proxy_pbs.slug_resolver'),
            $container->get('logger.channel.default')
        );
    }

    /**
     * Get audio URL for an episode and redirect.
     *
     * @param string $slug
     *   The program slug.
     * @param string $date
     *   The episode date.
     *
     * @return TrustedRedirectResponse|JsonResponse
     *   Redirect to audio URL or error response.
     */
    public function getAudioUrl($slug, $date)
    {
        try {
            // Resolve episode UUID
            $uuid = $this->slugResolver->resolveEpisode($slug, $date);
            
            if (!$uuid) {
                // Fallback to Omny Studio URL if episode not found
                $fallbackUrl = $this->buildOmnyFallbackUrl($slug, $date);
                $this->logger->warning('Episode not found, using Omny fallback URL for @slug on @date', [
                    '@slug' => $slug,
                    '@date' => $date,
                ]);
                return new TrustedRedirectResponse($fallbackUrl);
            }
            
            // Fetch episode data
            $response = $this->jsonApiClient->getEpisodeByUuid($uuid, []);
            $episodeData = $response['data'] ?? [];
            
            // Extract audio URL
            $audioUrl = $episodeData['attributes']['field_audio_url']['uri'] ?? null;
            
            if (!$audioUrl) {
                // Fallback to Omny Studio URL if audio URL not set
                $fallbackUrl = $this->buildOmnyFallbackUrl($slug, $date);
                $this->logger->info('Audio URL not available, using Omny fallback URL for @slug on @date', [
                    '@slug' => $slug,
                    '@date' => $date,
                ]);
                return new TrustedRedirectResponse($fallbackUrl);
            }
            
            // Redirect to audio URL
            return new TrustedRedirectResponse($audioUrl);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch audio URL for @slug on @date: @message', [
                '@slug' => $slug,
                '@date' => $date,
                '@message' => $e->getMessage(),
            ]);
            
            // Try Omny fallback on error
            try {
                $fallbackUrl = $this->buildOmnyFallbackUrl($slug, $date);
                return new TrustedRedirectResponse($fallbackUrl);
            } catch (\Exception $fallbackError) {
                return new JsonResponse([
                    'error' => 'Failed to fetch audio URL',
                    'message' => $e->getMessage(),
                ], 500);
            }
        }
    }

    /**
     * Build Omny Studio fallback URL.
     *
     * @param string $slug
     *   The program slug.
     * @param string $date
     *   The episode date.
     *
     * @return string
     *   The Omny Studio URL.
     */
    protected function buildOmnyFallbackUrl(string $slug, string $date): string
    {
        // Parse date to extract datetime components
        // Expected formats: "YYYY-MM-DD HH:MM:SS" or "YYYY-MM-DD+HH:MM:SS" or "YYYY-MM-DD+HH%3AMM%3ASS"
        $cleanDate = str_replace(['+', '%3A'], [' ', ':'], $date);
        
        try {
            $dateTime = new \DateTime($cleanDate, new \DateTimeZone('Australia/Melbourne'));
            // Format as YYYYMMDDHHMM
            $formattedDate = $dateTime->format('YmdHi');
        } catch (\Exception $e) {
            // Fallback: try to extract numbers and construct date
            $numbers = preg_replace('/[^0-9]/', '', $date);
            if (strlen($numbers) >= 12) {
                $formattedDate = substr($numbers, 0, 12); // YYYYMMDDHHMM
            } else {
                $formattedDate = date('YmdHi'); // Use current time as last resort
            }
        }
        
        // Construct Omny Studio URL
        // Format: https://airnet.org.au/omnystudio/3pbs/{slug}/{datetime}/aac_mid.m4a
        return sprintf(
            'https://airnet.org.au/omnystudio/3pbs/%s/%s/aac_mid.m4a',
            $slug,
            $formattedDate
        );
    }
}
