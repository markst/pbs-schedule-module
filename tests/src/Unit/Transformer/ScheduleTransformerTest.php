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
   * Base URL used to absolutize schedule slot image paths in tests.
   */
  protected const TEST_BASE_URL = 'https://example.com';

  /**
   * Monday fortnight anchor used by schedule fixtures (AEDT).
   */
  protected function fortnightAnchor(): \DateTimeImmutable {
    return new \DateTimeImmutable('2025-12-01', new \DateTimeZone('Australia/Melbourne'));
  }

  /**
   * Tests schedule entries match the legacy fortnight contract.
   */
  public function testTransformToScheduleMatchesLegacyContract(): void {
    $response = $this->loadFixture('jsonapi/schedule_slots.json');
    $expected = $this->loadFixture('legacy/schedule_entry.json');
    $schedule = ScheduleTransformer::transformToSchedule(
      $response,
      self::TEST_BASE_URL,
      $this->fortnightAnchor()
    );

    $this->assertCount(2, $schedule);
    $entry = $schedule[0];

    $this->assertLegacyContract($entry, $this->scheduleEntryContractKeys());
    $this->assertSame('fm', $entry['guideId']);
    $this->assertSame('1', $entry['day']);
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

    $this->assertSame('8', $schedule[1]['day']);
    $this->assertSame('2025-12-08T06:00:00+11:00', $schedule[1]['startTime']);
  }

  /**
   * Tests schedule entries are sorted by day then start time.
   */
  public function testScheduleIsSortedByDayAndStart(): void {
    $response = $this->loadFixture('jsonapi/schedule_slots.json');
    $response['data'][] = [
      'type' => 'schedule_slot',
      'id' => '2-MO-480',
      'attributes' => [
        'day' => 'MO',
        'start_time' => 480,
        'end_time' => 600,
        'program_id' => 2,
        'program_title' => 'Later Show',
        'program_author' => 'Jane Doe',
        'program_url' => '/program/later-show',
        'program_image_uri' => '/sites/default/files/profile.jpg',
        'program_tagline' => 'Evening music',
        'program_featured_image_uri' => '/sites/default/files/banner.jpg',
        'timezone' => 'Australia/Melbourne',
      ],
    ];
    $response['data'][] = [
      'type' => 'schedule_slot',
      'id' => '3-TU-360',
      'attributes' => [
        'day' => 'TU',
        'start_time' => 360,
        'end_time' => 480,
        'program_id' => 3,
        'program_title' => 'Tuesday Show',
        'program_author' => 'Jane Doe',
        'program_url' => '/program/tuesday-show',
        'program_image_uri' => '/sites/default/files/profile.jpg',
        'program_tagline' => 'Tuesday music',
        'program_featured_image_uri' => '/sites/default/files/banner.jpg',
        'timezone' => 'Australia/Melbourne',
      ],
    ];

    $schedule = ScheduleTransformer::transformToSchedule(
      $response,
      self::TEST_BASE_URL,
      $this->fortnightAnchor()
    );
    $this->assertGreaterThanOrEqual(4, count($schedule));

    for ($i = 1; $i < count($schedule); $i++) {
      $prevDay = (int) $schedule[$i - 1]['day'];
      $day = (int) $schedule[$i]['day'];
      $this->assertTrue(
        $day > $prevDay || ($day === $prevDay && $schedule[$i]['start'] >= $schedule[$i - 1]['start'])
      );
    }
  }

  /**
   * Tests missing weekday codes produce no entries.
   */
  public function testInvalidDayCodeIsSkipped(): void {
    $response = [
      'data' => [
        [
          'type' => 'schedule_slot',
          'id' => 'bad',
          'attributes' => [
            'day' => 'XX',
            'start_time' => 360,
            'end_time' => 480,
            'program_title' => 'Bad',
            'timezone' => 'Australia/Melbourne',
          ],
        ],
      ],
    ];

    $schedule = ScheduleTransformer::transformToSchedule(
      $response,
      self::TEST_BASE_URL,
      $this->fortnightAnchor()
    );
    $this->assertSame([], $schedule);
  }

}
