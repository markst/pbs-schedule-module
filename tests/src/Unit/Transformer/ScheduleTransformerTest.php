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
   * Tests a real schedule slot matches the legacy fortnight contract.
   */
  public function testTransformToScheduleMatchesLegacyContract(): void {
    // Wednesday of ISO week 44 (even).
    $schedule = $this->transform(new \DateTimeImmutable('2026-10-28 10:00', $this->melbourne()));
    $expected = $this->loadFixture('legacy/schedule_entry.json');

    $this->assertCount(4, $schedule);
    $this->assertLegacyContract($schedule[0], $this->scheduleEntryContractKeys());
    // Key order is not part of the JSON contract.
    ksort($expected);
    $entry = $schedule[0];
    ksort($entry);
    $this->assertSame($expected, $entry);
  }

  /**
   * Tests labels match develop's ScheduleController::startDate() parity.
   *
   * Even ISO week: current week is days 8-14, next week days 1-7. Odd ISO
   * week: current week is days 1-7, next week days 8-14.
   */
  public function testDayLabelsUseIsoWeekParity(): void {
    // ISO week 44 (even): Monday 26 Oct is day 8, Monday 2 Nov is day 1.
    $even = $this->startTimesByDay(new \DateTimeImmutable('2026-10-28 10:00', $this->melbourne()));
    $this->assertSame('2026-10-26T06:00:00+11:00', $even['8']);
    $this->assertSame('2026-11-02T06:00:00+11:00', $even['1']);
    $this->assertSame('2026-11-01T13:00:00+11:00', $even['14']);
    $this->assertSame('2026-11-08T13:00:00+11:00', $even['7']);

    // ISO week 45 (odd): Monday 2 Nov is day 1, Monday 9 Nov is day 8.
    $odd = $this->startTimesByDay(new \DateTimeImmutable('2026-11-04 10:00', $this->melbourne()));
    $this->assertSame('2026-11-02T06:00:00+11:00', $odd['1']);
    $this->assertSame('2026-11-09T06:00:00+11:00', $odd['8']);
  }

  /**
   * Tests weeks whose dates span two ISO / calendar years.
   */
  public function testYearBoundary(): void {
    // 2025-W52 (even): current week 22-28 Dec is 8-14, next week (2026-W01) is 1-7.
    $even = $this->startTimesByDay(new \DateTimeImmutable('2025-12-24 10:00', $this->melbourne()));
    $this->assertSame('2025-12-22T06:00:00+11:00', $even['8']);
    $this->assertSame('2025-12-29T06:00:00+11:00', $even['1']);
    $this->assertSame('2026-01-04T13:00:00+11:00', $even['7']);

    // 2026-W53 (odd, Sunday 3 Jan 2027 is still in it): 28 Dec is day 1, 4 Jan 2027 day 8.
    $odd = $this->startTimesByDay(new \DateTimeImmutable('2027-01-03 22:00', $this->melbourne()));
    $this->assertSame('2026-12-28T06:00:00+11:00', $odd['1']);
    $this->assertSame('2027-01-04T06:00:00+11:00', $odd['8']);
    $this->assertSame('2027-01-10T13:00:00+11:00', $odd['14']);
  }

  /**
   * Tests every day of two years against develop's startDate() algorithm.
   */
  public function testMatchesDevelopStartDateEveryDay(): void {
    $day = new \DateTimeImmutable('2025-12-01 12:00', $this->melbourne());
    $end = new \DateTimeImmutable('2027-12-31 12:00', $this->melbourne());
    for (; $day <= $end; $day = $day->modify('+1 day')) {
      foreach ($this->transform($day) as $entry) {
        $this->assertSame(
          $this->developStartDate($day, (int) $entry['day'], $entry['start']),
          $entry['startTime'],
          'Anchor ' . $day->format('Y-m-d') . ' day ' . $entry['day']
        );
      }
    }
  }

  /**
   * Tests schedule entries are sorted by day then start time.
   */
  public function testScheduleIsSortedByDayAndStart(): void {
    $schedule = $this->transform(new \DateTimeImmutable('2026-10-28 10:00', $this->melbourne()));

    $this->assertSame(['1', '7', '8', '14'], array_column($schedule, 'day'));
  }

  /**
   * Tests unknown weekday codes produce no entries.
   */
  public function testInvalidDayCodeIsSkipped(): void {
    $response = $this->loadFixture('jsonapi/schedule_slots.json');
    $response['data'] = [$response['data'][0]];
    $response['data'][0]['attributes']['day'] = 'XX';

    $schedule = ScheduleTransformer::transformToSchedule($response, self::TEST_BASE_URL, new \DateTimeImmutable());
    $this->assertSame([], $schedule);
  }

  /**
   * develop's ScheduleController::startDate() with an injectable "now".
   */
  protected function developStartDate(\DateTimeImmutable $now, int $day, string $start): string {
    $times = explode(':', $start);
    $evenWeek = $now->format('W') % 2 == 0;
    $append = $evenWeek && $day <= 7 ? 14 : 0;
    $targetWeek = (int) $now->format('W') - ($evenWeek ? 1 : 0);

    return (new \DateTime('now', $this->melbourne()))
      ->setISODate((int) $now->format('o'), $targetWeek, $day + $append)
      ->setTime((int) $times[0], (int) $times[1])
      ->format('c');
  }

  /**
   * Transforms the real schedule fixture for an anchor.
   */
  protected function transform(\DateTimeImmutable $anchor): array {
    return ScheduleTransformer::transformToSchedule(
      $this->loadFixture('jsonapi/schedule_slots.json'),
      self::TEST_BASE_URL,
      $anchor
    );
  }

  /**
   * Maps day label => startTime for an anchor.
   */
  protected function startTimesByDay(\DateTimeImmutable $anchor): array {
    return array_column($this->transform($anchor), 'startTime', 'day');
  }

  /**
   * Melbourne timezone.
   */
  protected function melbourne(): \DateTimeZone {
    return new \DateTimeZone('Australia/Melbourne');
  }

}
