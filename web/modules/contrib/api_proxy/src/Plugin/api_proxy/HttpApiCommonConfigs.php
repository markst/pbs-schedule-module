<?php

namespace Drupal\api_proxy\Plugin\api_proxy;

/**
 * Useful helpers for concrete HttpApi plugin implementations.
 */
trait HttpApiCommonConfigs {

  /**
   * Handy authentication token form element for use with HttpApi plugins.
   *
   * Use this to display important information to site administrators about
   * how to configure an authentication token for your API and keep it safe.
   * Also includes a status to communicate whether it's been set or not.
   *
   * @param array $configuration
   *   Current configuration.
   *
   * @return array
   *   Form item element to show the authentication token status to the user.
   */
  protected function authTokenConfigForm(array $configuration): array {
    return [
      '#type' => 'item',
      '#title' => $this->t('Authentication token (%status)', ['%status' => empty($configuration['auth_token']) ? $this->t('Not set') : $this->t('Successfully set')]),
      '#description' => $this->t(
        "The authentication token to access the %label proxy. <strong>IMPORTANT:</strong> do not export configuration to the repository with sensitive data, instead set <code>\$config['api_proxy.settings']['api_proxies']['@id']['auth_token'] = 'YOUR-TOKEN';</code> in your <code>settings.local.php</code> (or similar) to store your secret.",
        [
          '%label' => $this->getPluginDefinition()['label'],
          '@id' => $this->getPluginId(),
        ]
      ),
    ];
  }

}
