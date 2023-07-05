<?php

namespace Drupal\api_proxy\EventSubscriber;

use Drupal\api_proxy\Plugin\HttpApiPluginBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Routing\RouteProviderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Handles options requests.
 */
class OptionsRequestSubscriber implements EventSubscriberInterface {

  const ROUTE_NAME = 'api_proxy.forwarder';

  /**
   * The route provider.
   *
   * @var \Drupal\Core\Routing\RouteProviderInterface
   */
  protected $routeProvider;

  /**
   * The decorated service.
   *
   * @var \Symfony\Component\EventDispatcher\EventSubscriberInterface
   */
  protected $subject;

  /**
   * The name of the query string parameter containing the URI.
   *
   * @var string
   */
  private $uriParamName;

  /**
   * Creates a new OptionsRequestSubscriber instance.
   *
   * @param \Drupal\Core\Routing\RouteProviderInterface $route_provider
   *   The route provider.
   * @param \Symfony\Component\EventDispatcher\EventSubscriberInterface $subject
   *   The decorated service.
   * @param string $uri_param_name
   *   The name of the query string parameter containing the URI.
   */
  public function __construct(RouteProviderInterface $route_provider, EventSubscriberInterface $subject, string $uri_param_name) {
    $this->routeProvider = $route_provider;
    $this->subject = $subject;
    $this->uriParamName = $uri_param_name;
  }

  /**
   * Tries to handle the options request.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onRequest(RequestEvent $event) {
    $request = $event->getRequest();
    $routes = $this->routeProvider->getRouteCollectionForRequest($event->getRequest());
    if ($request->getMethod() !== 'OPTIONS') {
      return;
    }
    $route_name = current(array_filter(
      array_keys($routes->all()),
      function ($route_name) {
        return $route_name === static::ROUTE_NAME;
      }
    ));
    if (!$route_name) {
      $this->subject->onRequest($event);
      return;
    }
    $param_name = key($routes->get($route_name)->getOption('parameters'));
    $proxy = $request->attributes->get($param_name);
    if (!$proxy instanceof HttpApiPluginBase) {
      $cacheability = (new CacheableMetadata())->addCacheTags(['route']);
      $response = new CacheableResponse('', 404);
      $response->addCacheableDependency($cacheability);
      $event->setResponse($response);
      return;
    }
    $response = $proxy->corsResponse($request);
    $cache_contexts = [
      'url.query_args:' . $this->uriParamName,
      'headers:Origin',
    ];
    $cacheability = (new CacheableMetadata())
      ->addCacheContexts($cache_contexts)
      ->addCacheableDependency($this->config('api_proxy.settings'));
    $response->addCacheableDependency($cacheability);
    $event->setResponse($response);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // Set a high priority so it is executed before routing.
    $events[KernelEvents::REQUEST][] = ['onRequest', 31];
    return $events;
  }

  /**
   * Gets a configuration object.
   *
   * @param string $config_id
   *   The config ID.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The immutable configuration object.
   *
   * @todo use dependency injection to pass the configFactory.
   */
  private function config(string $config_id): ImmutableConfig {
    return \Drupal::config($config_id);
  }

}
