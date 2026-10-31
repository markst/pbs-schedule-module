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
   * Tests a real backend program transforms to the documented legacy shape.
   */
  public function testTransformMatchesLegacyContract(): void {
    $actual = ProgramTransformer::transform($this->loadFixture('jsonapi/program.json'));
    $expected = $this->loadFixture('legacy/program.json');

    $this->assertLegacyContract($actual, $this->programContractKeys());
    $this->assertLegacyFieldsMatch($actual, $expected, array_keys($expected));
  }

  /**
   * Tests gridDescription falls back to the tagline when summary is empty.
   */
  public function testGridDescriptionFallsBackToTagline(): void {
    $program = $this->loadFixture('jsonapi/program.json');
    $this->assertNull($program['attributes']['field_summary']);
    $this->assertSame("Groovin' jazz", ProgramTransformer::transform($program)['gridDescription']);

    $program['attributes']['field_summary'] = 'Summary wins';
    $this->assertSame('Summary wins', ProgramTransformer::transform($program)['gridDescription']);
  }

  /**
   * Tests the AOD slug is the slug source, with the path alias as fallback.
   */
  public function testSlugPrefersAodSlugOverAlias(): void {
    $program = $this->loadFixture('jsonapi/program.json');
    $program['attributes']['path']['alias'] = '/program/black-wax-retitled';
    $this->assertSame('black-wax', ProgramTransformer::transform($program)['slug']);

    $program['attributes']['field_api_aod_slug'] = NULL;
    $this->assertSame('black-wax-retitled', ProgramTransformer::transform($program)['slug']);
  }

}
