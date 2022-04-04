<?php

namespace Drupal\api_proxy\Plugin;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Form\SubformStateInterface;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

interface HttpApiInterface extends PluginInspectionInterface {

  public function getBaseUrl(): string;
  public function shouldForwardHeaders(): bool;
  public function getAdditionalHeaders(): array;
  public function isCacheForced(): int;
  public function getForcedCacheTtl(): int;
  public function preprocessIncoming(string $method, string $uri, HeaderBag $headers, ParameterBag $query): array;
  public function preprocessOutgoingRequestOptions(array $options): array;
  public function postprocessOutgoing(Response $response): Response;
  public function forward(Request $request, string $uri): Response;
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
