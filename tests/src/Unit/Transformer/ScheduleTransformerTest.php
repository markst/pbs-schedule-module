<?php

namespace Drupal\Tests\api_proxy_pbs\Unit\Transformer;

use Drupal\api_proxy_pbs\Transformer\ScheduleTransformer;
use Drupal\Tests\api_proxy_pbs\Traits\LegacyContractTestTrait;
use Drupal\Tests\UnitTestCase;

/**
 * Tests schedule transformation matches legacy Airnet fortnight contract.
 *
 * @group api_proxy_pbs
 */
class ScheduleTransformerTest extends UnitTestCase {

  use LegacyContractTestTrait;

  /**
   * Tests schedule entries match the legacy fortnight contract.
   */
  public function testTransformToScheduleMatchesLegacyContract(): void {
    $response = $this->loadFixture('jsonapi/schedule_episodes.json');
    $expected = $this->loadFixture('legacy/schedule_entry.json');
    $schedule = ScheduleTransformer::transformToSchedule($response);

    $this->assertNotEmpty($schedule);
    $entry = $schedule[0];

    $this->assertLegacyContract($entry, $this->scheduleEntryContractKeys());
    $this->assertSame('fm', $entry['guideId']);
    $this->assertSame('06:00:00', $entry['start']);
    $this->assertSame(7200, $entry['duration']);
    $this->assertSame('Lullabies to Anthems', $entry['name']);
    $this->assertSame('Jane Doe', $entry['broadcasters']);
    $this->assertSame('Music genre/style', $entry['gridDescription']);
    $this->assertSame('lullabies-to-anthems', $entry['slug']);
    $this->assertSame($expected['startTime'], $entry['startTime']);
    $this->assertSame($expected['profileImage'], $entry['profileImage']);
    $this->assertSame($expected['bannerImage'], $entry['bannerImage']);
    $this->assertSame($expected['programRestUrl'], $entry['programRestUrl']);
    $this->assertFalse($entry['archived']);
    $this->assertMatchesRegularExpression('/^[1-9]$|^1[0-4]$/', $entry['day']);
  }

  /**
   * Tests schedule entries are sorted by day then start time.
   */
  public function testScheduleIsSortedByDayAndStart(): void {
    $response = $this->loadFixture('jsonapi/schedule_episodes.json');
    $response['data'][] = [
      'type' => 'episode',
      'id' => '44444444-4444-4444-4444-444444444444',
      'attributes' => [
        'title' => 'Later Show',
        'field_date_range' => [
          'value' => '2025-12-01T08:00:00+11:00',
          'end_value' => '2025-12-01T10:00:00+11:00',
          'duration' => 120,
        ],
      ],
      'relationships' => [
        'field_program' => [
          'data' => [
            'type' => 'program',
            'id' => '11111111-1111-1111-1111-111111111111',
          ],
        ],
      ],
    ];

    $schedule = ScheduleTransformer::transformToSchedule($response);
    $this->assertGreaterThanOrEqual(2, count($schedule));

    for ($i = 1; $i < count($schedule); $i++) {
      $prevDay = (int) $schedule[$i - 1]['day'];
      $day = (int) $schedule[$i]['day'];
      $this->assertTrue(
        $day > $prevDay || ($day === $prevDay && $schedule[$i]['start'] >= $schedule[$i - 1]['start'])
      );
    }
  }

}
