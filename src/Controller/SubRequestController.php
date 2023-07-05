<?php

namespace Drupal\api_proxy_pbs\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Url;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;

/**
 * Class SubRequestController.
 *
 * @package Drupal\api_proxy_pbs\Controller
 */
class SubRequestController extends ControllerBase implements ContainerInjectionInterface
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
     * @var \Symfony\Component\HttpClient\HttpClient
     */
    protected $httpClient;

    /**
     * {@inheritdoc}
     */
    public function __construct(
        HttpKernelInterface $http_kernel,
        RequestStack $request_stack
    ) {
        $this->httpKernel = $http_kernel;
        $this->requestStack = $request_stack;
        $this->httpClient = HttpClient::create();
    }

    public static function create(ContainerInterface $container)
    {
        $httpKernel = \Drupal::service('http_kernel.basic');
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
     * @return Response
     *   The response Response.
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

        try {
            $sub_response = $this->httpKernel->handle(
                $sub_request,
                HttpKernelInterface::SUB_REQUEST,
                false
            );
            return $sub_response; // ->getContent();
        } catch (Throwable $t) {
            throw new \Exception($t->getMessage());
        } catch (\Exception | \Error $e) {
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * Performs an HTTP GET request to the external API at airnet.org.au and returns the JSON-decoded response.
     *
     * @param string $uri        The URI path for the API endpoint, relative to the base URL.
     * @param array  $parameters An associative array of query parameters to be included in the request.
     *
     * @return array The JSON-decoded response data if the request is successful.
     *
     * @throws \Exception In case of an error or non-200 response from the server.
     */
    public function getJSONSubrequest($uri, $parameters = [])
    {
        // Base URL for airnet.org.au API.
        $base_url = 'https://airnet.org.au';

        try {
            // Perform the HTTP request using the HttpClient instance created in the constructor.
            $response = $this->httpClient->request('GET', $base_url . $uri, [
                'query' => $parameters,
                'headers' => [
                    'Cache-Control' => 'no-cache, no-store, must-revalidate',
                    'Pragma' => 'no-cache',
                    'Expires' => '0'
                ]
            ]);

            // If the response code is 200, return the JSON-decoded content.
            if ($response->getStatusCode() == 200) {
                return json_decode($response->getContent(), true);
            } else {
                throw new \Exception("Error: " . $response->getReasonPhrase());
            }
        } catch (TransportExceptionInterface | ClientExceptionInterface $e) {
            // Handle exceptions.
            throw new \Exception("Error: " . $e->getMessage());
        }
    }
}
