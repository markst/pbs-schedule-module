<?php

namespace Drupal\api_proxy_pbs\Controller;

use Drupal\api_proxy_pbs\Mapping\FieldMap;
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
     * Redirect to an episode's recorded audio.
     *
     * Like develop's Omny lookup, this answers with a 302 to the published
     * clip; the clip URL now comes from the episode's
     * field_api_aod_audio_url (podcast audio as fallback). `{date}` accepts
     * the legacy formats plus the `YmdHi` timestamp iOS substitutes for
     * ???EPISODE??? in browserReplayUrlTemplate.
     *
     * @param string $slug
     *   The program slug.
     * @param string $date
     *   The episode start.
     *
     * @return TrustedRedirectResponse|JsonResponse
     *   Redirect to audio URL or error response.
     */
    public function getAudioUrl($slug, $date)
    {
        try {
            $uuid = $this->slugResolver->resolveEpisode($slug, $date);
            if (!$uuid) {
                return new JsonResponse(['error' => 'Episode not found'], 404);
            }

            $response = $this->jsonApiClient->getEpisodeByUuid($uuid);
            $audioUrl = FieldMap::extractEpisodeAudioUrl($response['data']['attributes'] ?? []);
            if (!$audioUrl) {
                return new JsonResponse(['error' => 'Audio not available'], 404);
            }

            return new TrustedRedirectResponse($audioUrl);
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch audio URL for @slug on @date: @message', [
                '@slug' => $slug,
                '@date' => $date,
                '@message' => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => 'Failed to fetch audio URL'], 500);
        }
    }
}
