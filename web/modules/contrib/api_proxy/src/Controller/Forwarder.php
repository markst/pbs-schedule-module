<?php

namespace Drupal\api_proxy\Controller;

use Drupal\api_proxy\Plugin\HttpApiInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Http\Exception\CacheableBadRequestHttpException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Main controller to forward requests.
 */
final class Forwarder extends ControllerBase {

  /**
   * The name of the query string parameter containing the URI.
   *
   * @var string
   */
  private $uriParamName;

  /**
   * Forwarder constructor.
   *
   * @param string $uri_param_name
   *   The name of the query string parameter containing the URI.
   */
  public function __construct(string $uri_param_name) {
    $this->uriParamName = $uri_param_name;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->getParameter('api_proxy.uri_param_name'));
  }

  /**
   * Forwards incoming requests to the connected API.
   *
   * @param \Drupal\api_proxy\Plugin\HttpApiInterface $api_proxy
   *   The API proxy plugin.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response object.
   */
  public function forward(HttpApiInterface $api_proxy, Request $request): Response {
    // @todo This belongs to the routing system.
    $account = $this->currentUser();
    $cache_contexts = [
      'url.query_args:' . $this->uriParamName,
      'headers:Origin',
      'user.permissions',
    ];
    $cacheability = (new CacheableMetadata())
      ->addCacheContexts($cache_contexts)
      ->addCacheableDependency($this->config('api_proxy.settings'));
    if (!$account->hasPermission(key($api_proxy->permissions()))) {
      throw new CacheableAccessDeniedHttpException(
        $cacheability,
        'The current user does not have access to this proxy'
      );
    }
    $third_party_uri = $request->query->get($this->uriParamName);
    if (empty($third_party_uri)) {
      throw new CacheableBadRequestHttpException(
        $cacheability,
        sprintf('Unable to find a valid URI in the %s query parameter.', $this->uriParamName)
      );
    }
    $response = $api_proxy->forward($request, $third_party_uri);
    if ($response instanceof CacheableResponse) {
      $response->addCacheableDependency($cacheability);
    }
    $response->setVary('Origin', FALSE);
    return $response;
  }

}
