<?php

namespace Drupal\api_proxy;

use Drupal\api_proxy\Plugin\HttpApiInterface;
use Drupal\api_proxy\Plugin\HttpApiPluginManager;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides API Proxy module permissions.
 */
final class ApiProxyPermissions implements ContainerInjectionInterface {

  /**
   * The API Proxy resource plugin manager.
   *
   * @var \Drupal\api_proxy\Plugin\HttpApiPluginManager
   */
  private $proxyPluginManager;

  /**
   * Constructs a new ApiProxyPermissions instance.
   *
   * @param \Drupal\api_proxy\Plugin\HttpApiPluginManager $proxy_plugin_manager
   *   The HTTP API proxy plugin manager.
   */
  public function __construct(HttpApiPluginManager $proxy_plugin_manager) {
    $this->proxyPluginManager = $proxy_plugin_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get(HttpApiPluginManager::class));
  }

  /**
   * Returns an array of API Proxy permissions.
   *
   * @return array
   *   The permissions structured array.
   */
  public function permissions() {
    return array_reduce(
      $this->proxyPluginManager->getHttpApis(),
      function ($permissions, HttpApiInterface $proxy) {
        return array_merge($permissions, $proxy->permissions());
      },
      []
    );
  }

}
