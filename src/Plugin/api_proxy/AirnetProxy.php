<?php

namespace Drupal\api_proxy_pbs\Plugin\api_proxy;

use Drupal\api_proxy\Plugin\api_proxy\HttpApiCommonConfigs;
use Drupal\api_proxy\Plugin\HttpApiPluginBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * PBS JSON:API Proxy.
 *
 * @HttpApi(
 *   id = "pbs_jsonapi",
 *   label = @Translation("PBS JSON:API fetcher"),
 *   description = @Translation("Proxies requests to PBS Drupal JSON:API backend."),
 *   serviceUrl = "https://nginx-php.project-migration.pbsfm.au2.amazee.io",
 * )
 */
final class AirnetProxy extends HttpApiPluginBase implements ContainerFactoryPluginInterface
{
    use HttpApiCommonConfigs;

    /**
     * Module settings config.
     *
     * @var \Drupal\Core\Config\ImmutableConfig
     */
    protected $moduleConfig;

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
    {
        $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
        $instance->moduleConfig = $container->get('config.factory')->get('api_proxy_pbs.settings');

        return $instance;
    }

    /**
     * {@inheritdoc}
     */
    public function addMoreConfigurationFormElements(
        array $form,
        SubformStateInterface $form_state
    ): array {
        return $form;
    }

    /**
     * {@inheritdoc}
     */
    protected function calculateHeaders(array $headers): array
    {
        $username = $this->moduleConfig->get('jsonapi_auth_username') ?? 'pbs';
        $password = $this->moduleConfig->get('jsonapi_auth_password') ?? '';

        // Add Basic Auth for JSON:API.
        $headers['Authorization'] = 'Basic ' . base64_encode($username . ':' . $password);
        $headers['Accept'] = 'application/vnd.api+json';
        
        return $headers;
    }

    /**
     * {@inheritdoc}
     */
    public function preprocessOutgoingRequestOptions(array $options): array
    {
        return $options;
    }

    /**
     * {@inheritdoc}
     */
    public function postprocessOutgoing(Response $response): Response
    {
        return $response;
    }
}
