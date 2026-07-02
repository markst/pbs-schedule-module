<?php

namespace Drupal\Tests\api_proxy_pbs\Unit\Transformer;

use Drupal\api_proxy_pbs\Transformer\EpisodeTransformer;
use Drupal\Tests\api_proxy_pbs\Traits\LegacyContractTestTrait;
use Drupal\Tests\UnitTestCase;

/**
 * Tests episode transformation matches legacy Airnet contract.
 *
 * @group api_proxy_pbs
 */
class EpisodeTransformerTest extends UnitTestCase {

  use LegacyContractTestTrait;

  /**
   * Tests a single episode transforms to the documented legacy shape.
   */
  public function testTransformMatchesLegacyContract(): void {
    $jsonApi = $this->loadFixture('jsonapi/episode.json');
    $included = $this->loadFixture('jsonapi/episodes_collection.json')['included'][0];
    $expected = $this->loadFixture('legacy/episode.json');
    $actual = EpisodeTransformer::transform($jsonApi, $included);

    $this->assertLegacyContract($actual, $this->episodeContractKeys());
    $this->assertLegacyFieldsMatch($actual, $expected, [
      'start',
      'end',
      'duration',
      'title',
      'description',
      'imageUrl',
      'smallImageUrl',
      'multipleEpsOnDay',
      'episodeRestUrl',
    ]);
    $this->assertIsBool($actual['currentEpisode']);
    $this->assertStringContainsString('airnet.org.au/rest/stations/3pbs/programs/', $actual['episodeRestUrl']);
    $this->assertStringContainsString('%3A', $actual['episodeRestUrl']);
  }

  /**
   * Tests episode collection transformation resolves included programs.
   */
  public function testTransformCollection(): void {
    $response = $this->loadFixture('jsonapi/episodes_collection.json');
    $episodes = EpisodeTransformer::transformCollection($response);

    $this->assertCount(1, $episodes);
    $this->assertLegacyContract($episodes[0], $this->episodeContractKeys());
    $this->assertNotEmpty($episodes[0]['episodeRestUrl']);
  }

}
