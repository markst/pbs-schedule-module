<?php

namespace Drupal\Tests\api_proxy_pbs\Kernel;

use Drupal\api_proxy_pbs\Service\JsonApiClient;
use Drupal\api_proxy_pbs\Service\SlugResolver;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests SlugResolver cache and JSON:API lookup behaviour.
 *
 * @group api_proxy_pbs
 */
class SlugResolverTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'api_proxy_pbs'];

  /**
   * The slug resolver under test.
   *
   * @var \Drupal\api_proxy_pbs\Service\SlugResolver
   */
  protected SlugResolver $slugResolver;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $mockClient = $this->createMock(JsonApiClient::class);
    $mockClient->method('getPrograms')->willReturnCallback(function (array $filters, array $includes = [], array $page = []) {
      $offset = $page['offset'] ?? 0;
      if ($offset > 0) {
        return [
          'data' => [
            [
              'id' => '22222222-2222-2222-2222-222222222222',
              'attributes' => [
                'path' => ['alias' => '/program/roots-of-rhythm'],
              ],
            ],
          ],
        ];
      }

      return [
        'data' => [
          [
            'id' => '11111111-1111-1111-1111-111111111111',
            'attributes' => [
              'path' => ['alias' => '/program/lullabies-to-anthems'],
            ],
          ],
        ],
        'links' => [
          'next' => ['href' => 'https://example.com/next'],
        ],
      ];
    });
    $mockClient->method('getEpisodes')->willReturnCallback(function (array $filters, array $includes = [], array $page = []) {
      $offset = $page['offset'] ?? 0;
      if ($offset > 0) {
        return ['data' => []];
      }

      return [
        'data' => [
          [
            'id' => '33333333-3333-3333-3333-333333333333',
            'attributes' => [
              'field_date_range' => [
                'value' => '2025-11-17T02:00:00+11:00',
              ],
            ],
          ],
          [
            'id' => '44444444-4444-4444-4444-444444444444',
            'attributes' => [
              'field_date_range' => [
                'value' => '2026-02-16T12:00:05+11:00',
              ],
            ],
          ],
        ],
      ];
    });

    $this->container->set('api_proxy_pbs.jsonapi_client', $mockClient);
    $this->slugResolver = new SlugResolver(
      $mockClient,
      $this->container->get('cache.default')
    );
  }

  /**
   * Tests program slug resolves to UUID and is cached.
   */
  public function testResolveProgramCachesUuid(): void {
    $uuid = $this->slugResolver->resolveProgram('lullabies-to-anthems');
    $this->assertSame('11111111-1111-1111-1111-111111111111', $uuid);

    $cached = $this->slugResolver->resolveProgram('lullabies-to-anthems');
    $this->assertSame($uuid, $cached);
  }

  /**
   * Tests slug resolution scans beyond the first page of programs.
   */
  public function testResolveProgramFindsSlugOnLaterPage(): void {
    $uuid = $this->slugResolver->resolveProgram('roots-of-rhythm');
    $this->assertSame('22222222-2222-2222-2222-222222222222', $uuid);
  }

  /**
   * Tests unknown program slug returns null.
   */
  public function testResolveProgramReturnsNullForUnknownSlug(): void {
    $this->assertNull($this->slugResolver->resolveProgram('unknown-program'));
  }

  /**
   * Tests episode resolution depends on program slug and date.
   */
  public function testResolveEpisode(): void {
    $uuid = $this->slugResolver->resolveEpisode('lullabies-to-anthems', '2025-11-17 02:00:00');
    $this->assertSame('33333333-3333-3333-3333-333333333333', $uuid);
  }

  /**
   * Tests episode resolution matches UTC-style legacy dates from older responses.
   */
  public function testResolveEpisodeMatchesUtcLegacyDate(): void {
    $uuid = $this->slugResolver->resolveEpisode('lullabies-to-anthems', '2026-02-16 01:00:05');
    $this->assertSame('44444444-4444-4444-4444-444444444444', $uuid);
  }

  /**
   * Tests episode resolution accepts URL-encoded Airnet date format.
   */
  public function testResolveEpisodeAcceptsUrlEncodedDate(): void {
    $uuid = $this->slugResolver->resolveEpisode('lullabies-to-anthems', '2026-02-16+12%3A00%3A05');
    $this->assertSame('44444444-4444-4444-4444-444444444444', $uuid);
  }

  /**
   * Tests resolving one date variant caches all legacy aliases.
   */
  public function testResolveEpisodeCachesAllDateVariants(): void {
    $uuid = $this->slugResolver->resolveEpisode('lullabies-to-anthems', '2026-02-16 01:00:05');
    $this->assertSame('44444444-4444-4444-4444-444444444444', $uuid);

    $cachedMelbourne = $this->slugResolver->resolveEpisode('lullabies-to-anthems', '2026-02-16 12:00:05');
    $this->assertSame($uuid, $cachedMelbourne);
  }

  /**
   * Tests cached misses are ignored and retried.
   */
  public function testResolveEpisodeIgnoresCachedMiss(): void {
    $cache = $this->container->get('cache.default');
    $cache->set('pbs_slug_episode:lullabies-to-anthems:2026-02-16 01:00:05', NULL, time() + 3600);

    $uuid = $this->slugResolver->resolveEpisode('lullabies-to-anthems', '2026-02-16 01:00:05');
    $this->assertSame('44444444-4444-4444-4444-444444444444', $uuid);
  }

  public function testBuildProgramCache(): void {
    $mockClient = $this->createMock(JsonApiClient::class);
    $mockClient->method('getPrograms')->willReturnCallback(function (array $filters, array $includes = [], array $page = []) {
      $offset = $page['offset'] ?? 0;
      if ($offset > 0) {
        return ['data' => []];
      }

      return [
        'data' => [
          [
            'id' => '11111111-1111-1111-1111-111111111111',
            'attributes' => [
              'path' => ['alias' => '/program/lullabies-to-anthems'],
            ],
          ],
        ],
      ];
    });

    $resolver = new SlugResolver($mockClient, $this->container->get('cache.default'));
    $count = $resolver->buildProgramCache();

    $this->assertSame(1, $count);
    $this->assertSame(
      '11111111-1111-1111-1111-111111111111',
      $resolver->resolveProgram('lullabies-to-anthems')
    );
  }

}
