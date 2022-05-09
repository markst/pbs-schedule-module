<?php

namespace Drupal\api_proxy_pbs\Plugin\api_proxy;

use Drupal\api_proxy\Plugin\api_proxy\HttpApiCommonConfigs;
use Drupal\api_proxy\Plugin\HttpApiPluginBase;
use Drupal\Core\Form\SubformStateInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Example API.
 *
 * @HttpApi(
 *   id = "airnet",
 *   label = @Translation("airnet.org.au fetcher"),
 *   description = @Translation("Proxies requests airnet.org.au."),
 *   serviceUrl = "https://airnet.org.au",
 * )
 */
final class AirnetProxy extends HttpApiPluginBase
{
    use HttpApiCommonConfigs;

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
        // TODO: Check if we're fetching schedule `rest/stations/3pbs/guides/fm`
        // $response->$request->getBasePath()
        return $response;
    }
}
