<?php

namespace Drupal\Tests\api_proxy_pbs\Unit;

use Drupal\api_proxy_pbs\Transformer\EpisodeTransformer;
use Drupal\api_proxy_pbs\Transformer\ProgramTransformer;
use Drupal\api_proxy_pbs\Transformer\ScheduleTransformer;
use Drupal\api_proxy_pbs\Transformer\TrackTransformer;
use Drupal\Tests\api_proxy_pbs\Traits\LegacyContractTestTrait;
use Drupal\Tests\UnitTestCase;

/**
 * Cross-cutting migration contract tests for all transformers.
 *
 * Validates JSON:API → legacy Airnet shape documented in docs/API.md.
 *
 * @group api_proxy_pbs
 */
class LegacyMigrationContractTest extends UnitTestCase {

  use LegacyContractTestTrait;

  /**
   * Tests all fixture-based transformers produce Airnet-compatible output.
   *
   * @dataProvider transformerProvider
   */
  public function testTransformerOutputMatchesDocumentedContract(string $transformer, string $fixture, string $contractMethod, bool $collection = FALSE): void {
    $input = $this->loadFixture($fixture);

    switch ($transformer) {
      case ProgramTransformer::class:
        $output = $collection
          ? ProgramTransformer::transformCollection($input)[0]
          : ProgramTransformer::transform($input);
        break;

      case EpisodeTransformer::class:
        $output = $collection
          ? EpisodeTransformer::transformCollection($input)[0]
          : EpisodeTransformer::transform($input['data'], $input['included'][0]);
        break;

      case ScheduleTransformer::class:
        $output = ScheduleTransformer::transformToSchedule(
          $input,
          'https://example.com',
          new \DateTimeImmutable('2026-10-28', new \DateTimeZone('Australia/Melbourne'))
        )[0];
        break;

      case TrackTransformer::class:
        $output = TrackTransformer::transformCollection($input)[0];
        break;

      default:
        $this->fail('Unknown transformer: ' . $transformer);
    }

    $this->assertLegacyContract($output, $this->{$contractMethod}());

    if (isset($output['contentDescriptors'])) {
      $this->assertLegacyContract($output['contentDescriptors'], $this->contentDescriptorContractKeys());
    }
  }

  /**
   * Data provider for transformer contract tests.
   */
  public function transformerProvider(): array {
    return [
      'program' => [ProgramTransformer::class, 'jsonapi/program.json', 'programContractKeys'],
      'programs collection' => [ProgramTransformer::class, 'jsonapi/programs_collection.json', 'programContractKeys', TRUE],
      'episode' => [EpisodeTransformer::class, 'jsonapi/episode.json', 'episodeContractKeys'],
      'episodes collection' => [EpisodeTransformer::class, 'jsonapi/episodes_collection.json', 'episodeContractKeys', TRUE],
      'schedule' => [ScheduleTransformer::class, 'jsonapi/schedule_slots.json', 'scheduleEntryContractKeys'],
      'playlist' => [TrackTransformer::class, 'jsonapi/tracks_collection.json', 'trackContractKeys'],
    ];
  }

  /**
   * Tests legacy REST URL format is preserved for mobile app compatibility.
   */
  public function testLegacyRestUrlsPreserved(): void {
    $program = ProgramTransformer::transform($this->loadFixture('jsonapi/program.json'));
    $this->assertSame(
      'https://airnet.org.au/rest/stations/3pbs/programs/black-wax/episodes',
      $program['episodesRestUrl']
    );

    $episode = EpisodeTransformer::transformCollection($this->loadFixture('jsonapi/episodes_collection.json'))[0];
    $this->assertStringStartsWith(
      'https://airnet.org.au/rest/stations/3pbs/programs/black-wax/episodes/',
      $episode['episodeRestUrl']
    );

    $schedule = ScheduleTransformer::transformToSchedule(
      $this->loadFixture('jsonapi/schedule_slots.json'),
      'https://example.com',
      new \DateTimeImmutable('2026-10-28', new \DateTimeZone('Australia/Melbourne'))
    );
    $this->assertSame(
      'https://airnet.org.au/rest/stations/3pbs/programs/test-the-breakfast-spread',
      $schedule[0]['programRestUrl']
    );
  }

}
