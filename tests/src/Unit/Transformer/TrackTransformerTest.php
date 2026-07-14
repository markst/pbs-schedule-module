<?php

namespace Drupal\Tests\api_proxy_pbs\Unit\Transformer;

use Drupal\api_proxy_pbs\Transformer\TrackTransformer;
use Drupal\Tests\api_proxy_pbs\Traits\LegacyContractTestTrait;
use Drupal\Tests\UnitTestCase;

/**
 * Tests track/playlist transformation matches legacy Airnet contract.
 *
 * @group api_proxy_pbs
 */
class TrackTransformerTest extends UnitTestCase {

  use LegacyContractTestTrait;

  /**
   * Tests a playlist transforms to the documented legacy shape.
   */
  public function testTransformCollectionMatchesLegacyContract(): void {
    $response = $this->loadFixture('jsonapi/tracks_collection.json');
    $expected = $this->loadFixture('legacy/track.json');
    $tracks = TrackTransformer::transformCollection($response);

    $this->assertCount(1, $tracks);
    $actual = $tracks[0];

    $this->assertLegacyContract($actual, $this->trackContractKeys());
    $this->assertLegacyFieldsMatch($actual, $expected, [
      'type',
      'id',
      'artist',
      'title',
      'track',
      'release',
      'time',
      'notes',
      'wikipedia',
      'image',
      'video',
      'url',
      'approximateTime',
    ]);

    $this->assertLegacyContract($actual['contentDescriptors'], $this->contentDescriptorContractKeys());
    $this->assertTrue($actual['contentDescriptors']['isAustralian']);
    $this->assertTrue($actual['contentDescriptors']['isFemale']);
    $this->assertNull($actual['contentDescriptors']['isLocal']);
  }

}
