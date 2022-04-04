<?php

namespace Drupal\api_proxy\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Annotation class for the HTTP API proxy plugins.
 *
 * @Annotation
 */
final class HttpApi extends Plugin {

  /**
   * The plugin ID.
   *
   * @var string
   */
  public $id;

  /**
   * The human-readable name of the formatter type.
   *
   * @var \Drupal\Core\Annotation\Translation
   *
   * @ingroup plugin_translatable
   */
  public $label;

  /**
   * A short description of the formatter type.
   *
   * @var \Drupal\Core\Annotation\Translation
   *
   * @ingroup plugin_translatable
   */
  public $description;

  /**
   * The name of the field formatter class.
   *
   * This is not provided manually, it will be added by the discovery mechanism.
   *
   * @var string
   */
  public $class;

  /**
   * The service URL for the proxy.
   *
   * @var string
   */
  public $serviceUrl;

}
