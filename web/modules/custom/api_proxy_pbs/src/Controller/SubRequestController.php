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
     * Perform subrequest request with uri.
     * @return json object
     *   The response json.
     *
     * @throws \Exception
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

        try {
            $sub_response = $this->subRequest($path, 'GET', $parameters);
            $code = $sub_response->getStatusCode();
            $content = $sub_response->getContent();

            if ($code == 200) {
                return json_decode($content, true);
            } else {
                // throw new \NotFoundHttpException($content);
                throw new \Exception($content);
            }
        } catch (Throwable $t) {
            throw new \Exception($t->getMessage());
        } catch (\Exception | \Error $e) {
            throw new \Exception($e->getMessage());
        }
    }
}
