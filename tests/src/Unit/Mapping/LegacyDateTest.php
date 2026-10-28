<?php

namespace Drupal\Tests\api_proxy_pbs\Unit\Mapping;

use Drupal\api_proxy_pbs\Mapping\LegacyDate;
use Drupal\Tests\UnitTestCase;

/**
 * Tests parsing of the date strings legacy clients send.
 *
 * @group api_proxy_pbs
 */
class LegacyDateTest extends UnitTestCase {

  /**
   * Tests every client spelling of one start parses to the same instant.
   *
   * @dataProvider legacySpellings
   */
  public function testParsesLegacySpellings(string $input): void {
    $parsed = LegacyDate::parse($input);

    $this->assertNotNull($parsed);
    $this->assertSame('2026-08-03T11:00:00+10:00', $parsed->format('c'));
  }

  /**
   * Spellings of 2026-08-03 11:00 Melbourne.
   */
  public function legacySpellings(): array {
    return [
      'airnet start' => ['2026-08-03 11:00:00'],
      'episodeRestUrl path' => ['2026-08-03+11:00:00'],
      'url-encoded path' => ['2026-08-03+11%3A00%3A00'],
      'percent-encoded space' => ['2026-08-03%2011:00:00'],
      'ios Date.toAirnet()' => ['2026-08-03T11:00:00'],
      'no seconds' => ['2026-08-03 11:00'],
      'utc offset' => ['2026-08-03T01:00:00Z'],
      'explicit offset' => ['2026-08-03T11:00:00+10:00'],
      'ios stream timestamp' => ['202608031100'],
    ];
  }

  /**
   * Tests date-only values are Melbourne midnight.
   */
  public function testDateOnly(): void {
    $this->assertSame('2026-08-03T00:00:00+10:00', LegacyDate::parse('2026-08-03')->format('c'));
    $this->assertSame('2026-08-03T00:00:00+10:00', LegacyDate::parse('20260803')->format('c'));
  }

  /**
   * Tests garbage and impossible dates are rejected.
   */
  public function testRejectsInvalid(): void {
    foreach (['', 'garbage', '2026-02-30 10:00:00', '2026-08-03 24:00:00', '2026-13-01', NULL] as $input) {
      $this->assertNull(LegacyDate::parse($input), var_export($input, TRUE));
    }
  }

}
