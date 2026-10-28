<?php

namespace Drupal\api_proxy_pbs\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * JSON:API client for PBS Drupal backend.
 */
class JsonApiClient
{
    const DEFAULT_BASE_URL = 'https://nginx-php.project-migration.pbsfm.au2.amazee.io';
    const DEFAULT_AUTH_USERNAME = 'pbs';
    // Fallback credential accepted by the owner until per-environment config lands.
    const DEFAULT_AUTH_PASSWORD = 'pbs2025';
    // Upper bound on pages followed via links.next (50 resources per page).
    const MAX_COLLECTION_PAGES = 20;

    protected $httpClient;
    protected $config;
    protected $baseUrl;

    /**
     * Constructor.
     */
    public function __construct(ClientInterface $httpClient, ConfigFactoryInterface $configFactory)
    {
        $this->httpClient = $httpClient;
        $this->config = $configFactory->get('api_proxy_pbs.settings');
        $this->baseUrl = $this->config->get('jsonapi_base_url') ?? self::DEFAULT_BASE_URL;
    }

    /**
     * Get episodes with filtering.
     *
     * `field_date_range` is a smart_date field stored as Unix timestamps, so
     * date filters must use timestamps; ISO strings silently match nothing.
     *
     * @param array $filters
     *   Filter parameters, e.g. ['field_date_range.value' => 1785718800], or
     *   JSON:API condition groups such as
     *   ['before' => ['condition' => ['path' => ..., 'operator' => '<', 'value' => ...]]].
     * @param array $includes
     *   Relationships to include (e.g., ['field_program']).
     * @param array $page
     *   Pagination parameters (e.g., ['limit' => 10, 'offset' => 0]).
     * @param string|null $sort
     *   JSON:API sort expression (e.g., '-field_date_range.value').
     *
     * @return array
     *   JSON:API response decoded as array.
     */
    public function getEpisodes(array $filters = [], array $includes = [], array $page = [], ?string $sort = null): array
    {
        $query = $this->buildQuery($filters, $includes, $page, $sort);
        $url = $this->baseUrl . '/api/v1/episode?' . $query;

        return $this->request($url);
    }

    /**
     * Get programs with filtering.
     *
     * @param array $filters
     *   Filter parameters.
     * @param array $includes
     *   Relationships to include.
     *
     * @return array
     *   JSON:API response decoded as array.
     */
    public function getPrograms(array $filters = [], array $includes = [], array $page = []): array
    {
        $query = $this->buildQuery($filters, $includes, $page);
        $url = $this->baseUrl . '/api/v1/program?' . $query;
        
        return $this->request($url);
    }

    /**
     * Get every program matching the filters, following `links.next`.
     *
     * The backend pages collections at 50 resources; legacy `/api/programs`
     * must return the full list.
     *
     * @param array $filters
     *   Filter parameters.
     *
     * @return array
     *   All program resources (the merged `data` of every page).
     *
     * @throws \RuntimeException
     *   When the page bound is hit, so a truncated list is never served.
     */
    public function getAllPrograms(array $filters = ['status' => true]): array
    {
        $url = $this->baseUrl . '/api/v1/program?' . $this->buildQuery($filters);
        $programs = [];

        for ($page = 0; $url !== null && $page < self::MAX_COLLECTION_PAGES; $page++) {
            $response = $this->request($url);
            array_push($programs, ...($response['data'] ?? []));
            $url = $response['links']['next']['href'] ?? null;
        }

        if ($url !== null) {
            throw new \RuntimeException(sprintf(
                'Program collection exceeded %d pages; refusing to return a truncated list.',
                self::MAX_COLLECTION_PAGES
            ));
        }

        return $programs;
    }

    /**
     * Get single program by UUID.
     *
     * @param string $uuid
     *   The program UUID.
     * @param array $includes
     *   Relationships to include.
     *
     * @return array
     *   JSON:API response decoded as array.
     */
    public function getProgramByUuid(string $uuid, array $includes = []): array
    {
        $query = $this->buildQuery([], $includes);
        $url = $this->baseUrl . '/api/v1/program/' . $uuid;
        if ($query) {
            $url .= '?' . $query;
        }
        
        return $this->request($url);
    }

    /**
     * Get single episode by UUID.
     *
     * @param string $uuid
     *   The episode UUID.
     * @param array $includes
     *   Relationships to include.
     *
     * @return array
     *   JSON:API response decoded as array.
     */
    public function getEpisodeByUuid(string $uuid, array $includes = []): array
    {
        $query = $this->buildQuery([], $includes);
        $url = $this->baseUrl . '/api/v1/episode/' . $uuid;
        if ($query) {
            $url .= '?' . $query;
        }
        
        return $this->request($url);
    }

    /**
     * Get the playlist tracks for an episode.
     *
     * Episodes carry two track relationships: `field_aod_tracks` (the
     * broadcast / audio-on-demand playlist) and `field_podcast_tracks`. The
     * legacy playlist is the broadcast one; podcast tracks are only used when
     * an episode has no broadcast playlist. Each track's `field_artist`
     * relationship is included so the artist name can be resolved.
     *
     * @param string $uuid
     *   The episode UUID.
     *
     * @return array
     *   JSON:API response with track resources and included artists.
     */
    public function getEpisodeTracks(string $uuid): array
    {
        $response = [];
        foreach (['field_aod_tracks', 'field_podcast_tracks'] as $relationship) {
            $url = $this->baseUrl . '/api/v1/episode/' . $uuid . '/' . $relationship . '?include=field_artist';
            $response = $this->request($url);
            if (!empty($response['data'])) {
                return $response;
            }
        }

        return $response;
    }

    /**
     * Get undated weekday schedule templates.
     *
     * Fortnight expansion onto days 1-14 is done by ScheduleTransformer.
     *
     * @return array
     *   JSON:API response decoded as array.
     */
    public function getSchedule(): array
    {
        return $this->request($this->baseUrl . '/api/v1/schedule');
    }

    /**
     * Get the configured JSON:API base URL.
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Resolve included relationship data.
     *
     * @param array $responseData
     *   The full JSON:API response data.
     * @param string $type
     *   The resource type to find (e.g., 'program').
     * @param string $id
     *   The UUID of the resource.
     *
     * @return array|null
     *   The included resource data or null if not found.
     */
    public function resolveIncluded(array $responseData, string $type, string $id): ?array
    {
        if (empty($responseData['included'])) {
            return null;
        }

        foreach ($responseData['included'] as $included) {
            if ($included['type'] === $type && $included['id'] === $id) {
                return $included;
            }
        }

        return null;
    }

    /**
     * Build query string from filters, includes, pagination and sort.
     *
     * Filters may be nested arrays (operators, condition groups); they are
     * flattened to `filter[a][b][c]=value` by http_build_query().
     */
    protected function buildQuery(array $filters = [], array $includes = [], array $page = [], ?string $sort = null): string
    {
        $params = [];

        if (!empty($filters)) {
            $params['filter'] = $filters;
        }
        if (!empty($includes)) {
            $params['include'] = implode(',', $includes);
        }
        $page = array_filter(array_intersect_key($page, ['limit' => true, 'offset' => true]));
        if (!empty($page)) {
            $params['page'] = $page;
        }
        if ($sort !== null && $sort !== '') {
            $params['sort'] = $sort;
        }

        return http_build_query($params);
    }

    /**
     * Build the Basic Auth header for the JSON:API backend.
     *
     * Single source of truth for backend credentials; every outbound request
     * (this client and the api_proxy plugin) must use this header.
     */
    public function getAuthorizationHeader(): string
    {
        $username = $this->config->get('jsonapi_auth_username') ?: self::DEFAULT_AUTH_USERNAME;
        $password = $this->config->get('jsonapi_auth_password') ?: self::DEFAULT_AUTH_PASSWORD;

        return 'Basic ' . base64_encode($username . ':' . $password);
    }

    /**
     * Make HTTP request with authentication.
     */
    protected function request(string $url): array
    {
        try {
            $options = [
                'headers' => [
                    'Accept' => 'application/vnd.api+json',
                    'Authorization' => $this->getAuthorizationHeader(),
                ],
                'timeout' => 30,
            ];

            $response = $this->httpClient->request('GET', $url, $options);
            $body = (string) $response->getBody();
            
            return json_decode($body, true) ?? [];
        } catch (RequestException $e) {
            \Drupal::logger('api_proxy_pbs')->error('JSON:API request failed: @url - @message', [
                '@url' => $url,
                '@message' => $e->getMessage(),
            ]);
            
            throw new \Exception('Failed to fetch data from JSON:API: ' . $e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            \Drupal::logger('api_proxy_pbs')->error('JSON:API client error: @message', [
                '@message' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }
}

