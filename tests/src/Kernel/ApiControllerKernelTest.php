<?php

namespace Drupal\Tests\api_proxy_pbs\Kernel;

use Drupal\api_proxy_pbs\Controller\ApiController;
use Drupal\api_proxy_pbs\Controller\StreamController;
use Drupal\api_proxy_pbs\Service\JsonApiClient;
use Drupal\api_proxy_pbs\Service\SlugResolver;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\api_proxy_pbs\Traits\FakeJsonApiBackendTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests API controller responses against a fixture-backed JSON:API mock.
 *
 * @group api_proxy_pbs
 */
class ApiControllerKernelTest extends KernelTestBase {

  use FakeJsonApiBackendTrait;

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
   * Tests programs endpoint returns every program across backend pages.
   */
  public function testGetProgramsReturnsAllPages(): void {
    $response = $this->createController()->getPrograms();
    $data = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['black-wax', 'the-blend', 'down-by-the-river'], array_column($data, 'slug'));
    $this->assertLegacyContract($data[0], $this->programContractKeys());
  }

  /**
   * Tests unknown program is 404 but a backend failure is 5xx.
   */
  public function testGetProgramNotFoundVersusBackendFailure(): void {
    $this->assertSame(404, $this->createController()->getProgram('unknown-program')->getStatusCode());

    $failing = $this->createController($this->createFakeClient([
      'getPrograms' => fn () => throw new \RuntimeException('backend down'),
    ]));
    $this->assertSame(500, $failing->getProgram('black-wax')->getStatusCode());
  }

  /**
   * Tests channel endpoint returns static legacy config.
   */
  public function testGetChannelReturnsLegacyContract(): void {
    $data = json_decode($this->createController()->getChannel()->getContent(), TRUE);

    $this->assertLegacyContract($data, $this->channelContractKeys());
    $this->assertSame($this->loadFixture('legacy/channel.json'), $data);
  }

  /**
   * Tests Airnet date/numBefore/numAfter window semantics.
   *
   * @dataProvider episodeWindowProvider
   */
  public function testGetEpisodesWindow(array $query, array $expectedStarts): void {
    $response = $this->requestEpisodes($query);
    $data = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame($expectedStarts, array_column($data, 'start'));
    foreach ($data as $episode) {
      $this->assertLegacyContract($episode, $this->episodeContractKeys());
    }
  }

  /**
   * Window cases over the Black Wax fixture (27 Jul, 3 Aug, 10 Aug 2026).
   */
  public function episodeWindowProvider(): array {
    $jul27 = '2026-07-27 11:00:00';
    $aug03 = '2026-08-03 11:00:00';
    $aug10 = '2026-08-10 11:00:00';

    return [
      'no params: anchor is the latest aired episode' => [[], [$jul27, $aug03, $aug10]],
      'ios first page' => [['numBefore' => '11'], [$jul27, $aug03, $aug10]],
      'numBefore limits the window' => [['numBefore' => '1'], [$aug03, $aug10]],
      'ios next page ends with the anchor' => [
        ['date' => '2026-08-10T11:00:00', 'numBefore' => '1', 'numAfter' => '0'],
        [$aug03, $aug10],
      ],
      'before and after the anchor' => [
        ['date' => $aug03, 'numBefore' => '1', 'numAfter' => '1'],
        [$jul27, $aug03, $aug10],
      ],
      'date-only anchors on that day' => [['date' => '2026-08-03', 'numBefore' => '0', 'numAfter' => '0'], [$aug03]],
      'oldest anchor has nothing before' => [['date' => $jul27, 'numBefore' => '11', 'numAfter' => '0'], [$jul27]],
      'day without an episode' => [['date' => '2026-08-04T11:00:00', 'numBefore' => '11'], []],
      'unparseable date' => [['date' => 'garbage', 'numBefore' => '11'], []],
    ];
  }

  /**
   * Tests episodes for an unknown program is 404.
   */
  public function testGetEpisodesUnknownProgram(): void {
    $this->assertSame(404, $this->requestEpisodes([], 'unknown-program')->getStatusCode());
  }

  /**
   * Tests a single episode resolves from a legacy route date.
   */
  public function testGetEpisode(): void {
    $response = $this->createController()->getEpisode('black-wax', '2026-08-03+11%3A00%3A00');
    $data = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('2026-08-03 11:00:00', $data['start']);
    $this->assertSame(7200, $data['duration']);
    $this->assertSame(404, $this->createController()->getEpisode('black-wax', '2026-08-04 11:00:00')->getStatusCode());
  }

  /**
   * Tests playlists resolve artists, and backend failure is 5xx.
   */
  public function testGetPlaylists(): void {
    $response = $this->createController()->getPlaylists('black-wax', '2026-08-03 11:00:00');
    $data = json_decode($response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertLegacyContract($data[0], $this->trackContractKeys());
    $this->assertSame(['Screaming Trees', 'John Mayall & The Bluesbreakers'], array_column($data, 'artist'));

    $failing = $this->createController($this->createFakeClient([
      'getEpisodeTracks' => fn () => throw new \RuntimeException('backend down'),
    ]));
    $this->assertSame(500, $failing->getPlaylists('black-wax', '2026-08-03 11:00:00')->getStatusCode());
  }

  /**
   * Tests /stream redirects to the AOD audio URL for the iOS timestamp.
   */
  public function testStreamRedirectsToAodAudio(): void {
    $episode = $this->loadFixture('jsonapi/episode.json');
    $client = $this->createFakeClient(['getEpisodeByUuid' => fn () => $episode]);

    $response = $this->createStreamController($client)->getAudioUrl('black-wax', '202608031100');

    $this->assertSame(302, $response->getStatusCode());
    $this->assertSame($episode['data']['attributes']['field_api_aod_audio_url']['uri'], $response->headers->get('Location'));
  }

  /**
   * Tests /stream has no synthesized fallback: 404 without audio, 500 on error.
   */
  public function testStreamWithoutAudioOrBackend(): void {
    $this->assertSame(404, $this->createStreamController($this->createFakeClient())->getAudioUrl('black-wax', '202608031100')->getStatusCode());
    $this->assertSame(404, $this->createStreamController($this->createFakeClient())->getAudioUrl('black-wax', '202608041100')->getStatusCode());

    $failing = $this->createFakeClient(['getEpisodeByUuid' => fn () => throw new \RuntimeException('backend down')]);
    $this->assertSame(500, $this->createStreamController($failing)->getAudioUrl('black-wax', '202608031100')->getStatusCode());
  }

  /**
   * Calls the episodes endpoint with a query string.
   */
  protected function requestEpisodes(array $query, string $program = 'black-wax') {
    $request = Request::create('/api/programs/' . $program . '/episodes', 'GET', $query);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    return $this->createController()->getEpisodes($program);
  }

  /**
   * Creates an API controller backed by the fake backend.
   */
  protected function createController(?JsonApiClient $client = NULL): ApiController {
    $client ??= $this->createFakeClient();
    return new ApiController($client, $this->createResolver($client));
  }

  /**
   * Creates a stream controller backed by the given client.
   */
  protected function createStreamController(JsonApiClient $client): StreamController {
    return new StreamController($client, $this->createResolver($client), $this->container->get('logger.channel.default'));
  }

  /**
   * Creates a resolver for the given client.
   */
  protected function createResolver(JsonApiClient $client): SlugResolver {
    return new SlugResolver($client, $this->container->get('cache.default'), $this->container->get('config.factory'));
  }

}
