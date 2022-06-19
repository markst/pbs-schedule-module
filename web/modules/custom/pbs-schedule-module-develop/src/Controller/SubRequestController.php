<?php

namespace Drupal\api_proxy_pbs\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Url;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class SubRequest.
 *
 * @package Drupal\api_proxy_pbs\Controller
 */
class SubRequestController extends ControllerBase implements
    ContainerInjectionInterface
{
    /**
     * Symfony\Component\HttpKernel\HttpKernelInterface definition.
     *
     * @var Symfony\Component\HttpKernel\HttpKernelInterface
     */
    protected $httpKernel;

    /**
     * @var \Symfony\Component\HttpFoundation\RequestStack
     */
    protected $requestStack;

    /**
     * {@inheritdoc}
     */
    public function __construct(
        HttpKernelInterface $http_kernel,
        RequestStack $request_stack
    ) {
        $this->httpKernel = $http_kernel;
        $this->requestStack = $request_stack;
    }

    public static function create(ContainerInterface $container)
    {
        $httpKernel = \Drupal::service('http_kernel.basic'); // $container->get('http_kernel.basic'),
        $requestStack = \Drupal::requestStack();

        return new static($httpKernel, $requestStack);
    }

    /**
     * Performs a subrequest.
     *
     * @param string $path
     *   Path to use for subrequest.
     * @param string $method
     *   The HTTP method to use, eg. Get, Post.
     * @param array $parameters
     *   The query parameters.
     * @param string|resource|null $content
     *   The raw body data.
     *
     * @return string
     *   The response String.
     *
     * @throws \Exception
     */
    protected function subRequest(
        $path,
        $method = 'GET',
        array $parameters = [],
        $content = null
    ) {
        $sub_request = Request::create(
            $path,
            $method,
            $parameters,
            $cookies = [],
            $files = [],
            $server = [],
            $content
        );

        $sub_request->setSession(
            $this->requestStack->getCurrentRequest()->getSession()
        );

        // Confirm necessary `api_proxy`:
        $sub_request->headers->set('Host', 'airnet.org.au');

        $sub_response = $this->httpKernel->handle(
            $sub_request,
            HttpKernelInterface::SUB_REQUEST,
            false
        );

        return $sub_response; // ->getContent();
    }

    /**
     * Perform subrequest request with uri.
     * @return json object
     *   The response json.
     */
    public function getJSONSubrequest($uri, $parameters = [])
    {
        // Generate path from `api_proxy` route:
        $path = Url::fromRoute(
            'api_proxy.forwarder',
            [
                'api_proxy' => 'airnet',
                '_api_proxy_uri' => $uri,
            ],
            []
        )
            ->toString(true)
            ->getGeneratedUrl();

        $sub_response = $this->subRequest($path, 'GET', $parameters);
        $code = $sub_response->getStatusCode();

        if ($code == 200) {
            $content = $sub_response->getContent();
            return json_decode($content, true);
        } else {
            return [
                'data' => json_decode($sub_response->getContent(), true),
                'status' => $code,
            ];
        }
    }

    /**
     * Perform request with url
     * @return json object
     */
    protected function getJSON(string $url)
    {
        $method = 'GET';
        $options = [];

        $client = \Drupal::httpClient();

        $response = $client->request($method, $url, $options);
        $code = $response->getStatusCode();

        if ($code == 200) {
            $body = $response->getBody()->getContents();
            return json_decode($body, true);
        }

        return null;
    }
}
