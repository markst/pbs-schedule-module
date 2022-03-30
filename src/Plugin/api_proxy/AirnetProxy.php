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
        // If we're fetching schedule
        // $response->$request->getBasePath()
        // 'rest/stations/3pbs/guides/fm'

        // $schedule = json_decode($response->getContent(), TRUE);

        // Create a http_build_query for programs

        // Execute curl request for programs

        // Json decode response if request was successful

        // Create a $weektwo

        // Loop through the original $schedule

        // For each insomnia program:

        /***
        switch (program.slug) {
        case "insomnia_monday":
        case "insomnia_tuesday":
        case "insomnia_wednesday":
        case "insomnia_thursday":
        case "insomnia_friday":
        case "insomnia_sunday":

        // do lookup on insomnialookup for correct slug
        // array_search('zero', array_column(json_decode($json, true), 'name')); 

        // Add found program to $weektwo

        default:
        // Add existing program to $weektwo
        break;

        ***/

        // $data[] = $schedule + $weektwo;

        // Update response with new schedule:
        // $response->setContent(json_encode($data));
        return $response;
    }
}
