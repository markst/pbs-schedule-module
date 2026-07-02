<?php

namespace Drupal\Tests\api_proxy_pbs\Unit\Transformer;

use Drupal\api_proxy_pbs\Transformer\ProgramTransformer;
use Drupal\Tests\api_proxy_pbs\Traits\LegacyContractTestTrait;
use Drupal\Tests\UnitTestCase;

/**
 * Tests program transformation matches legacy Airnet contract.
 *
 * @group api_proxy_pbs
 */
class ProgramTransformerTest extends UnitTestCase {

  use LegacyContractTestTrait;

  /**
   * Tests a single program transforms to the documented legacy shape.
   */
  public function testTransformMatchesLegacyContract(): void {
    $jsonApi = $this->loadFixture('jsonapi/program.json');
    $expected = $this->loadFixture('legacy/program.json');
    $actual = ProgramTransformer::transform($jsonApi);

    $this->assertLegacyContract($actual, $this->programContractKeys());
    $this->assertLegacyFieldsMatch($actual, $expected, [
      'name',
      'broadcasters',
      'description',
      'gridDescription',
      'slug',
      'url',
      'profileImageUrl',
      'bannerImageUrl',
      'twitterHandle',
      'facebookPage',
      'defaultFirstAiredGuide',
      'episodesRestUrl',
    ]);
    $this->assertStringContainsString('airnet.org.au/rest/stations/3pbs/programs/', $actual['episodesRestUrl']);
    $this->assertStringContainsString($actual['slug'], $actual['episodesRestUrl']);
  }

  /**
   * Tests program collection transformation.
   */
  public function testTransformCollection(): void {
    $response = $this->loadFixture('jsonapi/programs_collection.json');
    $programs = ProgramTransformer::transformCollection($response);

    $this->assertCount(1, $programs);
    $this->assertLegacyContract($programs[0], $this->programContractKeys());
    $this->assertSame('lullabies-to-anthems', $programs[0]['slug']);
  }

}
