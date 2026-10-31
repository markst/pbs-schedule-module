<?php

namespace Drupal\Tests\api_proxy_pbs\Unit\Service;

use Drupal\api_proxy_pbs\Service\JsonApiClient;
use Drupal\Tests\api_proxy_pbs\Traits\LegacyContractTestTrait;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * Tests the requests JsonApiClient sends to the backend.
 *
 * @group api_proxy_pbs
 */
class JsonApiClientTest extends UnitTestCase {

  use LegacyContractTestTrait;

  /**
   * Requests captured by the Guzzle history middleware.
   */
  protected array $history = [];

  /**
   * Tests every page is fetched by following links.next.
   */
  public function testGetAllProgramsFollowsLinksNext(): void {
    $page1 = $this->loadFixture('jsonapi/programs_collection.json');
    $page2 = $this->loadFixture('jsonapi/programs_collection_page2.json');
    $client = $this->createClient([$page1, $page2]);

    $programs = $client->getAllPrograms();

    $this->assertCount(count($page1['data']) + count($page2['data']), $programs);
    $this->assertCount(2, $this->history);
    $this->assertSame($page1['links']['next']['href'], (string) $this->history[1]['request']->getUri());
  }

  /**
   * Tests a collection longer than the page bound fails instead of truncating.
   */
  public function testGetAllProgramsRefusesToTruncate(): void {
    $page = $this->loadFixture('jsonapi/programs_collection.json');
    $client = $this->createClient(array_fill(0, JsonApiClient::MAX_COLLECTION_PAGES, $page));

    $this->expectException(\RuntimeException::class);
    $client->getAllPrograms();
  }

  /**
   * Tests playlists come from field_aod_tracks with the artist included.
   */
  public function testGetEpisodeTracksUsesAodTracks(): void {
    $tracks = $this->loadFixture('jsonapi/tracks_collection.json');
    $client = $this->createClient([$tracks]);

    $this->assertSame($tracks, $client->getEpisodeTracks('episode-uuid'));
    $this->assertCount(1, $this->history);
    $uri = $this->history[0]['request']->getUri();
    $this->assertSame('/api/v1/episode/episode-uuid/field_aod_tracks', $uri->getPath());
    $this->assertSame('include=field_artist', $uri->getQuery());
  }

  /**
   * Tests podcast tracks are used only when there is no broadcast playlist.
   */
  public function testGetEpisodeTracksFallsBackToPodcastTracks(): void {
    $tracks = $this->loadFixture('jsonapi/tracks_collection.json');
    $client = $this->createClient([['data' => []], $tracks]);

    $this->assertSame($tracks, $client->getEpisodeTracks('episode-uuid'));
    $this->assertSame(
      '/api/v1/episode/episode-uuid/field_podcast_tracks',
      $this->history[1]['request']->getUri()->getPath()
    );
  }

  /**
   * Tests every request carries the single centrally built auth header.
   */
  public function testRequestsUseCentralAuthorizationHeader(): void {
    $client = $this->createClient([['data' => []]], ['jsonapi_auth_username' => 'user', 'jsonapi_auth_password' => 'secret']);
    $client->getSchedule();

    $this->assertSame('Basic ' . base64_encode('user:secret'), $client->getAuthorizationHeader());
    $this->assertSame($client->getAuthorizationHeader(), $this->history[0]['request']->getHeaderLine('Authorization'));
  }

  /**
   * Tests empty configured credentials use the accepted fallback.
   */
  public function testEmptyPasswordUsesFallback(): void {
    $client = $this->createClient([], ['jsonapi_auth_username' => 'pbs', 'jsonapi_auth_password' => '']);

    $this->assertSame(
      'Basic ' . base64_encode('pbs:' . JsonApiClient::DEFAULT_AUTH_PASSWORD),
      $client->getAuthorizationHeader()
    );
  }

  /**
   * Tests nested filters and sort are encoded as JSON:API query parameters.
   */
  public function testGetEpisodesEncodesConditionsAndSort(): void {
    $client = $this->createClient([['data' => []]]);
    $client->getEpisodes(
      [
        'status' => TRUE,
        'before' => ['condition' => ['path' => 'field_date_range.value', 'operator' => '<', 'value' => 1785718800]],
      ],
      ['field_program'],
      ['limit' => 11],
      '-field_date_range.value'
    );

    parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
    $this->assertSame([
      'filter' => [
        'status' => '1',
        'before' => ['condition' => ['path' => 'field_date_range.value', 'operator' => '<', 'value' => '1785718800']],
      ],
      'include' => 'field_program',
      'page' => ['limit' => '11'],
      'sort' => '-field_date_range.value',
    ], $query);
  }

  /**
   * Builds a client answering with the given JSON bodies in order.
   */
  protected function createClient(array $bodies, array $settings = []): JsonApiClient {
    $this->history = [];
    $stack = HandlerStack::create(new MockHandler(array_map(
      fn (array $body) => new Response(200, [], json_encode($body)),
      $bodies
    )));
    $stack->push(Middleware::history($this->history));

    $configFactory = $this->getConfigFactoryStub([
      'api_proxy_pbs.settings' => $settings + [
        'jsonapi_base_url' => 'https://nginx-php.project-migration.pbsfm.au2.amazee.io',
      ],
    ]);

    return new JsonApiClient(new Client(['handler' => $stack]), $configFactory);
  }

}
