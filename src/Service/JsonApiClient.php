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
    const DEFAULT_BASE_URL = 'https://nginx-php.pr-331.pbsfm.au2.amazee.io';
    const DEFAULT_AUTH_USERNAME = 'pbs';

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
     * @param array $filters
     *   Filter parameters (e.g., ['field_date_range.value' => '2025-11-29']).
     * @param array $includes
     *   Relationships to include (e.g., ['field_program', 'field_tracks']).
     * @param array $page
     *   Pagination parameters (e.g., ['limit' => 10, 'offset' => 0]).
     *
     * @return array
     *   JSON:API response decoded as array.
     */
    public function getEpisodes(array $filters = [], array $includes = [], array $page = []): array
    {
        $query = $this->buildQuery($filters, $includes, $page);
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
     * Get tracks for an episode.
     *
     * @param string $uuid
     *   The episode UUID.
     *
     * @return array
     *   JSON:API response with tracks.
     */
    public function getEpisodeTracks(string $uuid): array
    {
        $url = $this->baseUrl . '/api/v1/episode/' . $uuid . '/field_tracks?include=field_artist';
        
        return $this->request($url);
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
     * Build query string from filters, includes, and pagination.
     */
    protected function buildQuery(array $filters = [], array $includes = [], array $page = []): string
    {
        $params = [];

        // Add filters
        foreach ($filters as $field => $value) {
            if (is_array($value)) {
                // Handle complex filters (e.g., operator)
                foreach ($value as $key => $val) {
                    $params["filter[{$field}][{$key}]"] = $val;
                }
            } else {
                $params["filter[{$field}]"] = $value;
            }
        }

        // Add includes
        if (!empty($includes)) {
            $params['include'] = implode(',', $includes);
        }

        // Add pagination
        if (!empty($page['limit'])) {
            $params['page[limit]'] = $page['limit'];
        }
        if (!empty($page['offset'])) {
            $params['page[offset]'] = $page['offset'];
        }

        return http_build_query($params);
    }

    /**
     * Build Basic Auth header from site configuration.
     */
    protected function getAuthorizationHeader(): string
    {
        $username = $this->config->get('jsonapi_auth_username') ?? self::DEFAULT_AUTH_USERNAME;
        $password = $this->config->get('jsonapi_auth_password') ?? 'pbs2025';

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

