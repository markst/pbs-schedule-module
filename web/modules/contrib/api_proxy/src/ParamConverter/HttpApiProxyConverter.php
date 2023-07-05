<?php

namespace Drupal\api_proxy\ParamConverter;

use Drupal\api_proxy\Plugin\HttpApiInterface;
use Drupal\api_proxy\Plugin\HttpApiPluginManager;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Http\Exception\CacheableNotFoundHttpException;
use Drupal\Core\ParamConverter\ParamConverterInterface;
use Symfony\Component\Routing\Route;

/**
 * Converts the parameter into the full object.
 */
final class HttpApiProxyConverter implements ParamConverterInterface {

  const PARAM_TYPE = 'api_proxy';

  /**
   * The plugin manager.
   *
   * @var \Drupal\api_proxy\Plugin\HttpApiPluginManager
   */
  private $pluginManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(HttpApiPluginManager $plugin_manager) {
    $this->pluginManager = $plugin_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function convert($value, $definition, $name, array $defaults) {
    $proxy = current($this->pluginManager->getHttpApis([$value]));
    if ($proxy instanceof HttpApiInterface) {
      return $proxy;
    }
    throw new CacheableNotFoundHttpException(
      (new CacheableMetadata())->addCacheContexts(['route']),
      sprintf('The API proxy for "%s" was not found.', $value)
    );
  }

  /**
   * {@inheritdoc}
   */
  public function applies($definition, $name, Route $route) {
    return (!empty($definition['type']) && $definition['type'] === static::PARAM_TYPE);
  }

}
