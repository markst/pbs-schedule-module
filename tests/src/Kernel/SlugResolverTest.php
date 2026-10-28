<?php

namespace Drupal\Tests\api_proxy_pbs\Kernel;

use Drupal\api_proxy_pbs\Service\JsonApiClient;
use Drupal\api_proxy_pbs\Service\SlugResolver;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\api_proxy_pbs\Traits\FakeJsonApiBackendTrait;

/**
 * Tests SlugResolver cache and JSON:API lookup behaviour.
 *
 * @group api_proxy_pbs
 */
class SlugResolverTest extends KernelTestBase {

  use FakeJsonApiBackendTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'api_proxy_pbs'];

  /**
   * Black Wax program UUID in the fixtures.
   */
  protected const BLACK_WAX = 'a9b8a842-4add-49ae-bc9e-c16b690689c4';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['api_proxy_pbs']);
  }

  /**
   * Tests program slug resolves via field_api_aod_slug and is cached.
   */
  public function testResolveProgramByAodSlugIsCached(): void {
    $calls = 0;
    $client = $this->createFakeClient([
      'getPrograms' => function (array $filters) use (&$calls) {
        $calls++;
        $this->assertSame('black-wax', $filters['field_api_aod_slug']);
        return ['data' => [$this->loadFixture('jsonapi/program.json')]];
      },
    ]);
    $resolver = $this->createResolver($client);

    $this->assertSame(self::BLACK_WAX, $resolver->resolveProgram('black-wax'));
    $this->assertSame(self::BLACK_WAX, $resolver->resolveProgram('black-wax'));
    $this->assertSame(1, $calls);
  }

  /**
   * Tests programs without an AOD slug resolve by their alias slug.
   */
  public function testResolveProgramFallsBackToAliasSlug(): void {
    $program = $this->loadFixture('jsonapi/program.json');
    $program['attributes']['field_api_aod_slug'] = NULL;
    $program['attributes']['path']['alias'] = '/program/alias-only';
    $client = $this->createFakeClient([
      'getPrograms' => fn () => ['data' => []],
      'getAllPrograms' => fn () => [$program],
    ]);

    $this->assertSame(self::BLACK_WAX, $this->createResolver($client)->resolveProgram('alias-only'));
  }

  /**
   * Tests an unknown slug is a miss (null), not an error.
   */
  public function testResolveProgramReturnsNullForUnknownSlug(): void {
    $this->assertNull($this->createResolver($this->createFakeClient())->resolveProgram('unknown-program'));
  }

  /**
   * Tests backend failures propagate instead of reading as "not found".
   */
  public function testBackendFailurePropagates(): void {
    $client = $this->createFakeClient([
      'getPrograms' => fn () => throw new \RuntimeException('backend down'),
    ]);

    $this->expectException(\RuntimeException::class);
    $this->createResolver($client)->resolveProgram('black-wax');
  }

  /**
   * Tests episodes resolve with a timestamp filter on the start.
   */
  public function testResolveEpisodeFiltersOnStartTimestamp(): void {
    $resolver = $this->createResolver($this->createFakeClient());

    $this->assertSame('4b2d3db6-5023-4ee6-b8eb-e2b5f2804aec', $resolver->resolveEpisode('black-wax', '2026-08-03 11:00:00'));
    $this->assertSame(
      strtotime('2026-08-03T01:00:00+00:00'),
      $this->episodeQueries[0][0]['field_date_range.value']
    );
    $this->assertNull($resolver->resolveEpisode('black-wax', '2026-08-03 12:00:00'));
    $this->assertNull($resolver->resolveEpisode('black-wax', 'not-a-date'));
  }

  /**
   * Tests every legacy spelling of one start hits the same cache entry.
   */
  public function testEquivalentDateSpellingsShareCache(): void {
    $resolver = $this->createResolver($this->createFakeClient());

    foreach (['2026-08-03+11%3A00%3A00', '2026-08-03 11:00:00', '2026-08-03T11:00:00', '202608031100'] as $date) {
      $this->assertSame('4b2d3db6-5023-4ee6-b8eb-e2b5f2804aec', $resolver->resolveEpisode('black-wax', $date), $date);
    }
    $this->assertCount(1, $this->episodeQueries);
  }

  /**
   * Tests the cache TTL comes from slug_cache_ttl.
   */
  public function testCacheTtlFromConfig(): void {
    $this->config('api_proxy_pbs.settings')->set('slug_cache_ttl', 120)->save();
    $before = time();
    $this->createResolver($this->createFakeClient())->resolveProgram('black-wax');

    $item = $this->container->get('cache.default')->get('pbs_slug_program:black-wax');
    $this->assertGreaterThanOrEqual($before + 120, $item->expire);
    $this->assertLessThanOrEqual(time() + 120, $item->expire);
  }

  /**
   * Tests cache warming uses the slug source for every program.
   */
  public function testBuildProgramCache(): void {
    $resolver = $this->createResolver($this->createFakeClient());

    $this->assertSame(3, $resolver->buildProgramCache());
    $this->assertNotFalse($this->container->get('cache.default')->get('pbs_slug_program:down-by-the-river'));
  }

  /**
   * Creates a resolver backed by the given client.
   */
  protected function createResolver(JsonApiClient $client): SlugResolver {
    return new SlugResolver($client, $this->container->get('cache.default'), $this->container->get('config.factory'));
  }

}
