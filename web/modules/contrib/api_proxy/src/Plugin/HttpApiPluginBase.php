<?php

namespace Drupal\api_proxy\Plugin;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\DependentPluginInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\Http\Exception\CacheableBadRequestHttpException;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Plugin\PluginFormInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ServerException;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base class for HTTP API plugins that implement settings forms.
 *
 * @see \Drupal\api_proxy\Annotation\HttpApi
 * @see \Drupal\api_proxy\Plugin\HttpApiPluginManager
 * @see \Drupal\api_proxy\Plugin\HttpApiInterface
 *
 * @see plugin_api
 */
abstract class HttpApiPluginBase extends PluginBase implements ContainerFactoryPluginInterface, PluginFormInterface, ConfigurableInterface, DependentPluginInterface, HttpApiInterface {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  private $client;

  /**
   * Translates between Symfony and PRS objects.
   *
   * @var \Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface
   */
  private $foundationFactory;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ClientInterface $client, HttpFoundationFactoryInterface $foundation_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->client = $client;
    $this->foundationFactory = $foundation_factory;
    if (empty($plugin_definition['serviceUrl']) || !UrlHelper::isValid($plugin_definition['serviceUrl'])) {
      throw new \InvalidArgumentException('Please ensure the serviceUrl annotation property is set with a valid URL in the plugin definition.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    $settings = $container->get('config.factory')
      ->get('api_proxy.settings')
      ->get('api_proxies');
    $plugin_settings = empty($settings[$plugin_id]) ? [] : $settings[$plugin_id];
    $configuration = array_merge($plugin_settings, $configuration);
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->get('psr7.http_foundation_factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration(): array {
    return [
      'id' => $this->getPluginId(),
    ] + $this->configuration + $this->defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): self {
    $this->configuration = $configuration + $this->defaultConfiguration();
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'forwardHeaders' => TRUE,
      'additionalHeaders' => [],
      'forceCache' => FALSE,
      'forcedCacheTtl' => 3 * 60,
      'cors' => [
        'origin' => [],
        'methods' => ['GET', 'OPTIONS'],
        'max_age' => 1 * 60 * 60,
        'headers' => '',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getBaseUrl(): string {
    return $this->getPluginDefinition()['serviceUrl'];
  }

  /**
   * {@inheritdoc}
   */
  public function shouldForwardHeaders(): bool {
    return $this->getConfiguration()['forwardHeaders'];
  }

  /**
   * {@inheritdoc}
   */
  public function getAdditionalHeaders(): array {
    return $this->getConfiguration()['additionalHeaders'];
  }

  /**
   * {@inheritdoc}
   */
  public function isCacheForced(): int {
    return $this->getConfiguration()['forceCache'];
  }

  /**
   * {@inheritdoc}
   */
  public function getForcedCacheTtl(): int {
    return $this->getConfiguration()['forcedCacheTtl'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    // @todo Write the validation for the headers.
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  final public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $configuration = $this->getConfiguration() + $this->defaultConfiguration();
    $plugin_id = $configuration['id'];
    $definition = $this->getPluginDefinition();
    $form[$plugin_id] = empty($form[$plugin_id]) ? [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => empty($definition['label']) ? $plugin_id : $definition['label'],
      '#group' => 'api_proxies',
      '#tree' => TRUE,
    ] : $form[$plugin_id];
    if (!empty($definition['description'])) {
      $form[$plugin_id]['description'] = [
        '#type' => 'html_tag',
        '#tag' => 'em',
        '#value' => $definition['description'],
      ];
    }
    $form[$plugin_id]['forwardHeaders'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Forward headers?'),
      '#description' => $this->t('Check this to send headers in the incoming request to the 3rd party API.'),
      '#default_value' => $this->shouldForwardHeaders(),
    ];
    $lines = [];
    foreach ($this->getAdditionalHeaders() as $name => $value) {
      $lines[] = sprintf('%s: %s', $name, $value);
    }
    $form[$plugin_id]['additionalHeaders'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Additional headers'),
      '#description' => $this->t('Additional headers to send to the 3rd party API. Add one header per line. Separate header name and value with a ":". Example: <code>Accept-Encoding: gzip</code>.'),
      '#default_value' => implode("\n", $lines),
    ];
    $form[$plugin_id]['forceCache'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Force response caching'),
      '#description' => $this->t('Responses are cached in Page Cache respecting the Cache-Control headers from the 3rd party HTTP API by default. Check this box to force caching in any situation.'),
      '#default_value' => $this->isCacheForced(),
    ];
    $form[$plugin_id]['forcedCacheTtl'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache TTL'),
      '#description' => $this->t('Forced cache TTL in seconds. Use <code>0</code> for skip caching. Use <code>-1</code> for permanent caching.'),
      '#default_value' => $this->getForcedCacheTtl(),
      '#states' => [
        'visible' => [
          'input[name="' . $plugin_id . '[forceCache]"]' => ['checked' => TRUE],
        ],
      ],
    ];
    $form[$plugin_id]['cors'] = [
      '#type' => 'details',
      '#title' => $this->t('CORS'),
      '#open' => TRUE,
      'origin' => [
        '#type' => 'textarea',
        '#title' => $this->t('Allowed Origins'),
        '#description' => $this->t('The candidates for contents of the <code>Access-Control-Allow-Origin</code> header. One per line. Note: you can use <code>*</code> here, but it is not recommended. Example: <pre><code>http://dev.example.com<br />https://example.com</code></pre>'),
        '#default_value' => implode("\n", $configuration['cors']['origin']),
      ],
      'methods' => [
        '#type' => 'checkboxes',
        '#title' => $this->t('Allowed methods'),
        '#description' => $this->t('The contents of the <code>Access-Control-Allow-Methods</code> header.'),
        '#options' => [
          'GET' => 'GET',
          'POST' => 'POST',
          'PUT' => 'PUT',
          'PATCH' => 'PATCH',
          'DELETE' => 'DELETE',
          'OPTIONS' => 'OPTIONS',
        ],
        '#default_value' => $configuration['cors']['methods'],
      ],
      'max_age' => [
        '#type' => 'number',
        '#title' => $this->t('Max age'),
        '#description' => $this->t('The contents of the <code>Access-Control-Max-Age</code> header.'),
        '#default_value' => $configuration['cors']['max_age'],
      ],
      'headers' => [
        '#type' => 'textfield',
        '#title' => $this->t('Allowed Headers'),
        '#description' => $this->t('List of coma-separated headers that are allowed. This will be set in the value of <code>Access-Control-Allow-Headers</code>.'),
        '#default_value' => $configuration['cors']['headers'],
      ],
    ];

    $subform_state = SubformState::createForSubform($form[$plugin_id], $form, $form_state);
    $form[$plugin_id] = $this->addMoreConfigurationFormElements($form[$plugin_id], $subform_state);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $values['additionalHeaders'] = $this->parseHeaders(
      $values['additionalHeaders']
    );
    $values['cors']['origin'] = $this->parseMultiline($values['cors']['origin']);
    $this->setConfiguration($values + $this->configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function preprocessIncoming(string $method, string $uri, HeaderBag $headers, ParameterBag $query): array {
    return [$method, $uri, $headers, $query];
  }

  /**
   * {@inheritdoc}
   */
  public function preprocessOutgoingRequestOptions(array $options): array {
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function postprocessOutgoing(Response $response): Response {
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function forward(Request $request, string $uri): Response {
    $parsed_uri = UrlHelper::parse($uri);
    $api_uri = rtrim($this->getBaseUrl(), '/') . '/' . ltrim($parsed_uri['path'], '/');
    [$api_method, $api_uri, $headers, $query_params] = $this->preprocessIncoming(
      $request->getMethod(),
      $api_uri,
      $request->headers,
      new ParameterBag($parsed_uri['query'] ?? [])
    );
    $options = [
      'query' => $query_params->all(),
      'headers' => $this->calculateHeaders($headers->all()),
      'version' => $request->getProtocolVersion(),
    ];
    if ($body = $request->getContent()) {
      $options['body'] = $body;
    }
    $options = $this->preprocessOutgoingRequestOptions($options);
    try {
      $cors_headers = $this->calculateCorsHeaders($request);
      $psr7_response = $this->client->request(
        $api_method,
        $api_uri,
        $options
      );
      $response = $this->foundationFactory->createResponse($psr7_response);
      $changed_response = $this->postprocessOutgoing($response);
      // Add CORS headers.
      $response->headers->add($cors_headers);
      return $this->maybeMakeResponseCacheable($changed_response);
    }
    catch (ClientException $exception) {
      watchdog_exception('api_proxy', $exception);
      return $this->foundationFactory->createResponse($exception->getResponse());
    }
    catch (ServerException $exception) {
      watchdog_exception('api_proxy', $exception);
      return $this->foundationFactory->createResponse($exception->getResponse());
    }
    catch (GuzzleException $exception) {
      watchdog_exception('api_proxy', $exception);
      $user = \Drupal::currentUser();
      $message = $user->hasPermission('administer site configuration')
        ? $exception->getTraceAsString()
        : $exception->getMessage();
      return new Response($message, 500);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function corsResponse(Request $request): CacheableResponse {
    $headers = $this->calculateCorsHeaders($request);
    $response = new CacheableResponse(NULL, 200, $headers);
    return $response
        ->setVary('Origin', FALSE)
        ->setCache([
          'max_age' => $headers['Access-Control-Max-Age'],
        ]);
  }

  /**
   * Calculate the CORS headers for the given request.
   *
   * Consults the configuration for this HTTP API plugin to come up with the
   * necessary CORS headers to sent in the response.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Incoming HTTP request.
   *
   * @return array
   *   Array of CORS headers to sent in the response.
   */
  private function calculateCorsHeaders(Request $request): array {
    $origin = $request->headers->get('Origin');
    if (empty($origin)) {
      // We don't need to add the headers.
      return [];
    }
    $cors_config = $this->getConfiguration()['cors'];
    $candidates = $cors_config['origin'] ?? [];
    $matched_origin = $this->matchedOrigin($origin, $candidates);
    if (!$matched_origin) {
      throw new CacheableBadRequestHttpException(
        (new CacheableMetadata())
          ->addCacheContexts(['headers:Origin', 'user.permissions'])
          ->addCacheableDependency(\Drupal::config('api_proxy.settings')),
        sprintf('The request comes from an unauthorized Origin (%s).', $origin)
      );
    }
    $ttl = $cors_config['max_age'];
    $methods = implode(', ', array_filter($cors_config['methods']));
    $headers = [
      'Allow' => $methods,
      'Access-Control-Allow-Methods' => $methods,
      'Access-Control-Max-Age' => $ttl,
      'Access-Control-Allow-Origin' => $matched_origin,
    ];
    if ($allowed_headers = $cors_config['headers']) {
      $headers['Access-Control-Allow-Headers'] = $allowed_headers;
    }
    return $headers;
  }

  /**
   * Decide if the response should be cacheable.
   *
   * If the response should be cacheable, wrap the response in a cacheable
   * response object.
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   Incoming HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   Potentially altered response. When the response should be cached, this
   *   will be a \Drupal\Core\Cache\CacheableResponse object.
   */
  private function maybeMakeResponseCacheable(Response $response): Response {
    $configured_ttl = $this->isCacheForced() ? $this->getForcedCacheTtl() : 0;
    $response_ttl = (int) $response->getMaxAge();
    $ttl = $configured_ttl > $response_ttl ? $configured_ttl : $response_ttl;
    if (!$ttl) {
      return $response;
    }
    $cacheable_response = new CacheableResponse(
      $response->getContent(),
      $response->getStatusCode(),
      $response->headers->all()
    );

    $cacheable_response->setCache([
      'max_age' => $ttl,
      'public' => TRUE,
      'etag' => $this->buildEtag($cacheable_response),
    ]);
    return $cacheable_response;
  }

  /**
   * Build an appropriate ETag for the given response.
   *
   * @param \Drupal\Core\Cache\CacheableResponse $cacheable_response
   *   Outgoing response.
   *
   * @return string
   *   Suitable hash of the given response that can be used as an Etag header.
   */
  private function buildEtag(CacheableResponse $cacheable_response) {
    $digest = array_reduce(
      $cacheable_response->headers->all(),
      function (string $carry, $header) {
        return $carry . (is_array($header) ? implode('', $header) : $header);
      },
      (string) $cacheable_response->getContent()
    );
    return Crypt::hashBase64($digest);
  }

  /**
   * Calculate the non-CORS related headers to include in the response.
   *
   * @param array $headers
   *   Headers from the request, which may or may not get included in the
   *   response.
   *
   * @return array
   *   Array of headers to include in the response. Includes the request headers
   *   if configured. Also includes any configured additional headers.
   */
  protected function calculateHeaders(array $headers): array {
    $new_headers = array_filter(array_diff_key($headers, ['host' => NULL]));
    $new_headers['x-forwarded-host'] = $headers['host'] ?? '';
    return array_merge(
      $this->shouldForwardHeaders() ? $new_headers : [],
      $this->getAdditionalHeaders()
    );
  }

  /**
   * Helper function to parse the additional headers configuration form value.
   *
   * @param string $input
   *   List of headers from the form.
   *
   * @return array
   *   Array of headers with the key being the header name and the value being
   *   the header value.
   */
  private function parseHeaders(string $input) {
    return array_filter(array_reduce(
      array_filter(explode("\n", $input)),
      function ($carry, $header) {
        [$name, $val] = array_map('trim', explode(':', $header, 2));
        return array_merge($carry, [$name => $val]);
      },
      []
    ));
  }

  /**
   * Helper function to parse a list of values, origins in our case.
   *
   * @param string $input
   *   List of values, one per line, separated by new lines.
   *
   * @return array
   *   Array of values trimmed.
   */
  private function parseMultiline(string $input) {
    return array_filter(array_map('trim', explode("\n", $input)));
  }

  /**
   * Match an origin in a list of candidate origins.
   *
   * If one of the candidates listed is '*', then '*' is the matched origin.
   * Otherwise, we look for a direct match in the list of candidates.
   *
   * @param string $origin
   *   Origin domain.
   * @param array $candidates
   *   List of candidate origins to match against.
   *
   * @return string|null
   *   The matched origin if found in the list of candidates, otherwise NULL.
   */
  private function matchedOrigin(string $origin, array $candidates): ?string {
    // Check if there is a '*' in the candidates.
    $has_star = array_reduce($candidates, function (bool $carry, string $candidate): bool {
      return $carry ?: $candidate === '*';
    }, FALSE);
    if ($has_star) {
      return '*';
    }
    return array_reduce($candidates, function (?string $carry, string $candidate) use ($origin): ?string {
      return $carry ?: ($candidate === $origin ? $candidate : NULL);
    }, NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function permissions(): array {
    $permission = sprintf('use %s api proxy', $this->getPluginId());
    $definition = $this->getPluginDefinition();
    $title = $this->t('Use the HTTP API proxy for %label', [
      '%label' => $definition['label'],
    ]);
    return [$permission => ['title' => $title]];
  }

}
