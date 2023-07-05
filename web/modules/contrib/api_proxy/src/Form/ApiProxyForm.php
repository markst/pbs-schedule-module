<?php

namespace Drupal\api_proxy\Form;

use Drupal\api_proxy\Plugin\HttpApiPluginManager;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A form to manually enqueue warming operations.
 */
final class ApiProxyForm extends FormBase {

  /**
   * The HTTP API proxy plugin manager.
   *
   * @var \Drupal\api_proxy\Plugin\HttpApiPluginManager
   */
  private $apiProxyManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    /** @var \Drupal\api_proxy\Form\ApiProxyForm $form_object */
    $form_object = parent::create($container);
    $form_object->setApiProxyManager($container->get(HttpApiPluginManager::class));

    return $form_object;
  }

  /**
   * Set the HTTP API proxy manager.
   *
   * @param \Drupal\api_proxy\Plugin\HttpApiPluginManager $api_proxy_manager
   *   The plugin manager.
   */
  public function setApiProxyManager(HttpApiPluginManager $api_proxy_manager): void {
    $this->apiProxyManager = $api_proxy_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'api_proxy.form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['help'] = [
      '#type' => 'item',
      '#description' => $this->t('This page allows you to enqueue cache warming operations manually. This will put the cache warming operations in a queue. If you want to actually execute them right away you can force processing the queue. A good way to do that is by installing the <a href=":url">Queue UI</a> module or using Drush. This module will provide a UI to process an entire queue.', [':url' => 'https://www.drupal.org/project/queue_ui']),
    ];
    $html = array_reduce($this->apiProxyManager->getDefinitions(), function ($carry, array $definition) {
      return $carry . '<dt>' . $definition['label'] . '</dt><dd>' . $definition['description'] . '</dd>';
    }, '');
    $form['apis'] = [
      '#type' => 'details',
      '#title' => $this->t('Installed APIs'),
      '#collapsible' => FALSE,
      '#open' => TRUE,
    ];
    $form['apis']['info'] = [
      '#type' => 'html_tag',
      '#tag' => 'dl',
      '#value' => $html,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

}
