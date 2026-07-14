<?php

namespace Drupal\Tests\api_proxy_pbs\Kernel;

use Drupal\api_proxy_pbs\Controller\ApiController;
use Drupal\api_proxy_pbs\Service\JsonApiClient;
use Drupal\api_proxy_pbs\Service\SlugResolver;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\api_proxy_pbs\Traits\LegacyContractTestTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests API controller responses with mocked JSON:API backend.
 *
 * @group api_proxy_pbs
 */
class ApiControllerKernelTest extends KernelTestBase {

  use LegacyContractTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'api_proxy_pbs'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['api_proxy_pbs']);
  }

  /**
   * Tests programs endpoint returns legacy contract from mocked JSON:API.
   */
  public function testGetProgramsReturnsLegacyContract(): void {
    $controller = $this->createController();
    $response = $controller->getPrograms();
    $data = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertCount(1, $data);
    $this->assertLegacyContract($data[0], $this->programContractKeys());
    $this->assertSame('lullabies-to-anthems', $data[0]['slug']);
  }

  /**
   * Tests single program endpoint returns 404 for unknown slug.
   */
  public function testGetProgramReturns404ForUnknownSlug(): void {
    $mockClient = $this->createMock(JsonApiClient::class);
    $mockClient->method('getPrograms')->willReturn(['data' => []]);
    $mockClient->method('getProgramByUuid')->willReturn(['data' => []]);

    $resolver = new SlugResolver($mockClient, $this->container->get('cache.default'));
    $controller = new ApiController($mockClient, $resolver);

    $response = $controller->getProgram('unknown-program');
    $this->assertSame(404, $response->getStatusCode());
  }

  /**
   * Tests channel endpoint returns static legacy config.
   */
  public function testGetChannelReturnsLegacyContract(): void {
    $controller = $this->createController();
    $response = $controller->getChannel();
    $data = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertLegacyContract($data, $this->channelContractKeys());
    $this->assertSame($this->loadFixture('legacy/channel.json'), $data);
  }

  /**
   * Tests episodes endpoint returns legacy contract from mocked JSON:API.
   */
  public function testGetEpisodesReturnsLegacyContract(): void {
    $controller = $this->createController();
    $request = Request::create('/api/programs/lullabies-to-anthems/episodes');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    $response = $controller->getEpisodes('lullabies-to-anthems');
    $data = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertCount(1, $data);
    $this->assertLegacyContract($data[0], $this->episodeContractKeys());
  }

  /**
   * Tests playlist endpoint returns legacy track contract.
   */
  public function testGetPlaylistsReturnsLegacyContract(): void {
    $controller = $this->createController();
    $response = $controller->getPlaylists('lullabies-to-anthems', '2025-11-17 02:00:00');
    $data = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertCount(1, $data);
    $this->assertLegacyContract($data[0], $this->trackContractKeys());
  }

  /**
   * Creates a controller backed by fixture JSON:API responses.
   */
  protected function createController(): ApiController {
    $fixturesPath = dirname(__DIR__, 2) . '/fixtures/jsonapi/';

    $mockClient = $this->createMock(JsonApiClient::class);
    $mockClient->method('getPrograms')->willReturn(
      json_decode(file_get_contents($fixturesPath . 'programs_collection.json'), TRUE)
    );
    $mockClient->method('getProgramByUuid')->willReturn([
      'data' => json_decode(file_get_contents($fixturesPath . 'program.json'), TRUE),
    ]);
    $mockClient->method('getEpisodes')->willReturn(
      json_decode(file_get_contents($fixturesPath . 'episodes_collection.json'), TRUE)
    );
    $mockClient->method('getEpisodeByUuid')->willReturn([
      'data' => json_decode(file_get_contents($fixturesPath . 'episode.json'), TRUE),
      'included' => json_decode(file_get_contents($fixturesPath . 'episodes_collection.json'), TRUE)['included'],
    ]);
    $mockClient->method('getEpisodeTracks')->willReturn(
      json_decode(file_get_contents($fixturesPath . 'tracks_collection.json'), TRUE)
    );

    $resolver = new SlugResolver($mockClient, $this->container->get('cache.default'));
    $resolver->buildProgramCache();

    return new ApiController($mockClient, $resolver);
  }

}
