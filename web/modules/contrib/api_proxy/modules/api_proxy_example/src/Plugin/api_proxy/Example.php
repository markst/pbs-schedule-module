<?php

namespace Drupal\api_proxy_example\Plugin\api_proxy;

use Drupal\api_proxy\Plugin\api_proxy\HttpApiCommonConfigs;
use Drupal\api_proxy\Plugin\HttpApiPluginBase;
use Drupal\Core\Form\SubformStateInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Example API.
 *
 * @HttpApi(
 *   id = "api-slug",
 *   label = @Translation("Example API"),
 *   description = @Translation("Proxies requests to the Example API."),
 *   serviceUrl = "https://api.example.org/v1",
 * )
 */
final class Example extends HttpApiPluginBase {

  use HttpApiCommonConfigs;

  /**
   * {@inheritdoc}
   */
  public function addMoreConfigurationFormElements(array $form, SubformStateInterface $form_state): array {
    $form['auth_token'] = $this->authTokenConfigForm($this->configuration);
    $form['more_stuff'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Extra config'),
      '#default_value' => $this->configuration['more_stuff'] ?? [],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function calculateHeaders(array $headers): array {
    $default_headers = parent::calculateHeaders($headers);
    // Modify & add new headers. Here you can add the auth token. Refer to your
    // APIs documentation for expected auth format.
    return array_merge(
      $default_headers,
      [
        'authorization' => ['Basic ' . base64_encode($this->configuration['auth_token'] . ':' . $this->configuration['more_stuff'])],
        'accept' => ['application/json'],
        'content-type' => ['application/json'],
      ]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function postprocessOutgoing(Response $response): Response {
    // Modify the response from the API.
    // A common problem is to remove the Transfer-Encoding header.
    // $response->headers->remove('transfer-encoding');
    return $response;
  }

}
