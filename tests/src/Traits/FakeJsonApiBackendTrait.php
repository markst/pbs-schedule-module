<?php

namespace Drupal\Tests\api_proxy_pbs\Traits;

use Drupal\api_proxy_pbs\Service\JsonApiClient;

/**
 * Mock JsonApiClient that evaluates queries against real backend fixtures.
 *
 * Episode filters/sort/limit are applied the way the backend applies them
 * (smart_date timestamps, condition groups), so window and resolution logic
 * is exercised rather than canned responses.
 */
trait FakeJsonApiBackendTrait {

  use LegacyContractTestTrait;

  /**
   * Arguments of every getEpisodes() call.
   */
  protected array $episodeQueries = [];

  /**
   * Number of getSchedule() calls.
   */
  protected int $scheduleRequests = 0;

  /**
   * Builds the mock client.
   *
   * @param array $overrides
   *   Method name => callable replacing the fixture-backed behaviour.
   */
  protected function createFakeClient(array $overrides = []): JsonApiClient {
    $episodes = $this->loadFixture('jsonapi/episodes_collection.json');
    $program = $this->loadFixture('jsonapi/program.json');
    $allPrograms = array_merge(
      $this->loadFixture('jsonapi/programs_collection.json')['data'],
      $this->loadFixture('jsonapi/programs_collection_page2.json')['data']
    );

    $behaviour = $overrides + [
      'getPrograms' => function (array $filters = []) use ($allPrograms) {
        $slug = $filters['field_api_aod_slug'] ?? NULL;
        return ['data' => array_values(array_filter(
          $allPrograms,
          fn ($p) => $slug === NULL || ($p['attributes']['field_api_aod_slug'] ?? NULL) === $slug
        ))];
      },
      'getAllPrograms' => fn () => $allPrograms,
      'getProgramByUuid' => fn () => ['data' => $program],
      'getEpisodes' => function (array $filters = [], array $includes = [], array $page = [], ?string $sort = NULL) use ($episodes) {
        $this->episodeQueries[] = [$filters, $includes, $page, $sort];
        return $this->queryEpisodes($episodes, $filters, $page, $sort);
      },
      'getEpisodeByUuid' => function (string $uuid) use ($episodes) {
        foreach ($episodes['data'] as $episode) {
          if ($episode['id'] === $uuid) {
            return ['data' => $episode, 'included' => $episodes['included']];
          }
        }
        return ['data' => NULL];
      },
      'getEpisodeTracks' => fn () => $this->loadFixture('jsonapi/tracks_collection.json'),
      'getSchedule' => function () {
        $this->scheduleRequests++;
        return $this->loadFixture('jsonapi/schedule_slots.json');
      },
    ];

    $client = $this->createMock(JsonApiClient::class);
    foreach ($behaviour as $method => $callback) {
      $client->method($method)->willReturnCallback($callback);
    }

    return $client;
  }

  /**
   * Applies JSON:API episode filters, sort and limit to a fixture.
   */
  protected function queryEpisodes(array $fixture, array $filters, array $page, ?string $sort): array {
    $matches = array_filter($fixture['data'], function (array $episode) use ($filters) {
      $start = strtotime($episode['attributes']['field_date_range']['value']);
      $end = strtotime($episode['attributes']['field_date_range']['end_value']);
      foreach ($filters as $key => $value) {
        if ($key === 'field_program.id' && $episode['relationships']['field_program']['data']['id'] !== $value) {
          return FALSE;
        }
        if ($key === 'field_date_range.value' && $start !== (int) $value) {
          return FALSE;
        }
        if (is_array($value) && isset($value['condition'])) {
          $target = (int) $value['condition']['value'];
          $subject = $value['condition']['path'] === 'field_date_range.end_value' ? $end : $start;
          $ok = match ($value['condition']['operator']) {
            '<' => $subject < $target,
            '<=' => $subject <= $target,
            '>' => $subject > $target,
            '>=' => $subject >= $target,
          };
          if (!$ok) {
            return FALSE;
          }
        }
      }
      return TRUE;
    });

    usort($matches, fn ($a, $b) => strcmp($a['attributes']['field_date_range']['value'], $b['attributes']['field_date_range']['value']));
    if ($sort !== NULL && str_starts_with($sort, '-')) {
      $matches = array_reverse($matches);
    }
    if (!empty($page['limit'])) {
      $matches = array_slice($matches, 0, $page['limit']);
    }

    return ['data' => array_values($matches), 'included' => $fixture['included']];
  }

}
