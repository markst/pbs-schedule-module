<?php

namespace Drupal\api_proxy\Form;

use Drupal\api_proxy\Plugin\HttpApiPluginBase;
use Drupal\api_proxy\Plugin\HttpApiPluginManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings form for the api_proxy module.
 *
 * This form aggregates the configuration of all the api_proxy plugins.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * The plugin manager for the HTTP API proxies.
   *
   * @var \Drupal\api_proxy\Plugin\HttpApiPluginManager
   */
  private $apiProxyManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(ConfigFactoryInterface $config_factory, HttpApiPluginManager $api_proxy_manager) {
    parent::__construct($config_factory);
    $this->apiProxyManager = $api_proxy_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get(HttpApiPluginManager::class)
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['api_proxy.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'api_proxy_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $form['help'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Configure the behaviors for the HTTP API proxies. Each proxy is a plugin that may contain specific settings. They are all configured here.'),
    ];
    $api_proxies = $this->apiProxyManager->getHttpApis();
    $form['api_proxies'] = [
      '#type' => 'vertical_tabs',
      '#title' => $this->t('HTTP API proxies'),
    ];
    $subform_state = SubformState::createForSubform(
      $form,
      $form,
      $form_state
    );
    $form += array_reduce(
      $api_proxies,
      function ($carry, HttpApiPluginBase $api_proxy) use ($subform_state) {
        return $api_proxy->buildConfigurationForm($carry, $subform_state) + $carry;
      },
      $form
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $api_proxies = $this->apiProxyManager->getHttpApis();
    array_map(function (HttpApiPluginBase $api_proxy) use (&$form, $form_state) {
      $id = $api_proxy->getPluginId();
      $subform_state = SubformState::createForSubform($form[$id], $form, $form_state);
      $api_proxy->submitConfigurationForm($form[$id], $subform_state);
    }, $api_proxies);
    $name = $this->getEditableConfigNames();
    $config_name = reset($name);
    $config = $this->configFactory()->getEditable($config_name);
    $api_proxy_configs = array_reduce($api_proxies, function ($carry, HttpApiPluginBase $api_proxy) {
      $carry[$api_proxy->getPluginId()] = $api_proxy->getConfiguration();
      return $carry;
    }, []);
    $config->set('api_proxies', $api_proxy_configs);
    $config->save();
    $message = $this->t('Settings saved for plugin(s): %names', [
      '%names' => implode(', ', array_map(function (HttpApiPluginBase $api_proxy) {
        return $api_proxy->getPluginDefinition()['label'];
      }, $api_proxies)),
    ]);
    $this->messenger()->addStatus($message);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    array_map(function (HttpApiPluginBase $api_proxy) use (&$form, $form_state) {
      $id = $api_proxy->getPluginId();
      $subform_state = SubformState::createForSubform($form[$id], $form, $form_state);
      $api_proxy->validateConfigurationForm($form[$id], $subform_state);
    }, $this->apiProxyManager->getHttpApis());
  }

}
