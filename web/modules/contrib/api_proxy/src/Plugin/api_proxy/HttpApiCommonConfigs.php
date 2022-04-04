<?php

namespace Drupal\api_proxy\Plugin\api_proxy;

trait HttpApiCommonConfigs {

  protected function authTokenConfigForm(array $configuration): array {
    return [
      '#type' => 'item',
      '#title' => $this->t('Authentication token (%status)', ['%status' => empty($configuration['auth_token']) ? $this->t('Not set') : $this->t('Successfully set')]),
      '#description' => $this->t(
        'The authentication token to access the %label proxy. <strong>IMPORTANT:</strong> do not export configuration to the repository with sensitive data, instead set <code>$config[\'api_proxy.settings\'][\'api_proxies\'][\'@id\'][\'auth_token\'] = \'YOUR-TOKEN\';</code> in your <code>settings.local.php</code> (or similar) to store your secret.',
        [
          '%label'=> $this->getPluginDefinition()['label'],
          '@id' => $this->getPluginId(),
        ]
      ),
    ];
  }

}
