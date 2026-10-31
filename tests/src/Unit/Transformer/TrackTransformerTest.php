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
   * Tests a real field_aod_tracks response transforms to the legacy shape.
   */
  public function testTransformCollectionMatchesLegacyContract(): void {
    $tracks = TrackTransformer::transformCollection($this->loadFixture('jsonapi/tracks_collection.json'));
    $expected = $this->loadFixture('legacy/track.json');

    $this->assertCount(2, $tracks);
    $this->assertLegacyContract($tracks[0], $this->trackContractKeys());
    $this->assertLegacyContract($tracks[0]['contentDescriptors'], $this->contentDescriptorContractKeys());
    $this->assertLegacyFieldsMatch($tracks[0], $expected, array_keys($expected));
  }

  /**
   * Tests artists are resolved from the included field_artist terms.
   */
  public function testArtistsResolvedFromIncludedTerms(): void {
    $tracks = TrackTransformer::transformCollection($this->loadFixture('jsonapi/tracks_collection.json'));

    $this->assertSame(['Screaming Trees', 'John Mayall & The Bluesbreakers'], array_column($tracks, 'artist'));
    $this->assertNull($tracks[1]['time']);
  }

}
