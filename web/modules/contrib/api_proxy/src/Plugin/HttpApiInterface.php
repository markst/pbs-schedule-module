<?php

namespace Drupal\api_proxy\Plugin;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Form\SubformStateInterface;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defines a HttpApi plugin.
 */
interface HttpApiInterface extends PluginInspectionInterface {

  /**
   * Get the base URL for an HttpApi plugin, configured as the serviceUrl.
   *
   * @return string
   *   The base URL for the third party HTTP API.
   */
  public function getBaseUrl(): string;

  /**
   * Whether request headers should be forwarded on to the API.
   *
   * @return bool
   *   TRUE if we should forward request headers to the API, otherwise FALSE.
   */
  public function shouldForwardHeaders(): bool;

  /**
   * Get configured additional headers to send along to the API.
   *
   * @return array
   *   Array of headers, key being the name of the header, value being the value
   *   of the header.
   */
  public function getAdditionalHeaders(): array;

  /**
   * Should responses from the API be force cached?
   *
   * Responses are cached in Page Cache respecting the Cache-Control headers
   * from the 3rd party HTTP API by default. If caching is forced, cache in any
   * situation.
   *
   * @return int
   *   Value of one to indicate that caching should be forced, otherwise 0.
   */
  public function isCacheForced(): int;

  /**
   * Get the forced cache TTL.
   *
   * @return int
   *   Length of time to force cache the response from the API in seconds.
   */
  public function getForcedCacheTtl(): int;

  /**
   * Provides an opportunity to preprocess the incoming request.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $uri
   *   Request URI.
   * @param \Symfony\Component\HttpFoundation\HeaderBag $headers
   *   Request headers.
   * @param \Symfony\Component\HttpFoundation\ParameterBag $query
   *   Query parameters from the incoming request.
   *
   * @return array
   *   Array including any adjustments to method, uri, headers, and query in the
   *   same positions as the arguments.
   */
  public function preprocessIncoming(string $method, string $uri, HeaderBag $headers, ParameterBag $query): array;

  /**
   * Opportunity to preprocess the Guzzle $options argument for the API request.
   *
   * @param array $options
   *   The options array that will be passed to
   *   \GuzzleHttp\ClientInterface::request.
   *
   * @return array
   *   An $options array that will be passed along to
   *   \GuzzleHttp\ClientInterface::request.
   *
   * @see \GuzzleHttp\ClientInterface::request
   */
  public function preprocessOutgoingRequestOptions(array $options): array;

  /**
   * Opportunity to adjust the response that came back from the API.
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   The response that came back from the third party HTTP API.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   Adjusted reponse object that will be passed back to the end user.
   */
  public function postprocessOutgoing(Response $response): Response;

  /**
   * Forward an incoming request to the third party API endpoint at $uri.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Incoming request.
   * @param string $uri
   *   Requested uri on the third party HTTP API.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   Response back from the third party API.
   */
  public function forward(Request $request, string $uri): Response;

  /**
   * Send the CORS response to the given HTTP OPTIONS request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   An HTTP OPTION request for getting CORS headers.
   *
   * @return \Drupal\Core\Cache\CacheableResponse
   *   Response with all the pertinent CORS headers.
   */
  public function corsResponse(Request $request): CacheableResponse;

  /**
   * Adds additional form elements to the configuration form.
   *
   * @param array $form
   *   The configuration form to alter for the this plugin settings.
   * @param \Drupal\Core\Form\SubformStateInterface $form_state
   *   The form state for the plugin settings.
   *
   * @return array
   *   The form with additional elements.
   */
  public function addMoreConfigurationFormElements(array $form, SubformStateInterface $form_state): array;

  /**
   * Provides an array of permissions suitable for .permissions.yml files.
   *
   * A resource plugin can define a set of user permissions that are used on the
   * routes for this resource or for other purposes.
   *
   * It is not required for a resource plugin to specify permissions: if they
   * have their own access control mechanism, they can use that, and return the
   * empty array.
   *
   * @return array
   *   The permission array.
   */
  public function permissions(): array;

}
