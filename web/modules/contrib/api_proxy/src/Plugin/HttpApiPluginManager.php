<?php

namespace Drupal\api_proxy\Plugin;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\api_proxy\Annotation\HttpApi;

/**
 * Manager for the HTTP API proxy plugins.
 */
final class HttpApiPluginManager extends DefaultPluginManager {

  /**
   * Constructs a new HookPluginManager object.
   *
   * @param \Traversable $namespaces
   *   An object that implements \Traversable which contains the root paths
   *   keyed by the corresponding namespace to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to use.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler to invoke the alter hook with.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    $this->alterInfo(FALSE);
    parent::__construct('Plugin/api_proxy', $namespaces, $module_handler, HttpApiInterface::class, HttpApi::class);
    $this->setCacheBackend($cache_backend, 'http_api_plugins');
  }

  /**
   * Instantiates all the HTTP API plugins.
   *
   * @return \Drupal\api_proxy\Plugin\HttpApiPluginBase[]
   *   The plugin instances.
   */
  public function getHttpApis($plugin_ids = NULL): array {
    if (!$plugin_ids) {
      $definitions = $this->getDefinitions();
      $plugin_ids = array_map(function ($definition) {
        return empty($definition) ? NULL : $definition['id'];
      }, $definitions);
      $plugin_ids = array_filter(array_values($plugin_ids));
    }
    $api_proxies = array_map(function ($plugin_id) {
      try {
        return $this->createInstance($plugin_id);
      }
      catch (PluginException $exception) {
        watchdog_exception('api_proxy', $exception);
        return NULL;
      }
    }, $plugin_ids);
    return array_filter($api_proxies, function ($api_proxy) {
      return $api_proxy instanceof HttpApiPluginBase;
    });
  }

}
