<?php

namespace Drupal\Tests\api_proxy_pbs\Kernel;

use Drupal\api_proxy_pbs\Controller\LookupController;
use Drupal\api_proxy_pbs\Service\ProgramLookup;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\api_proxy_pbs\Traits\FakeJsonApiBackendTrait;

/**
 * Checks the lookups against answers the legacy pbs_airnet_api module gave.
 *
 * tests/fixtures/legacy_snapshot holds what https://schedule.pbsfm.org.au
 * served on 2026-10-30: the schedule/episode data the old module read, and
 * its answers for a sample of timestamps (slot and episode boundaries,
 * mid-slot, daylight saving transition days). The data is replayed through a
 * fake JSON:API backend, so these tests show the new lookups give the answer
 * the old module gave for the same input.
 *
 * @group api_proxy_pbs
 */
class LookupLegacySnapshotTest extends KernelTestBase {

  use FakeJsonApiBackendTrait;

  protected const WEEKDAY_CODES = [1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR', 6 => 'SA', 7 => 'SU'];

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'api_proxy_pbs'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['api_proxy_pbs']);
  }

  /**
   * Tests /api/schedule/{date} gives the legacy answer.
   *
   * One kernel serves every case: booting one per snapshot row takes minutes.
   */
  public function testScheduleMatchesLegacyAnswers(): void {
    $controller = $this->createController($this->scheduleClient());

    foreach (self::snapshot('schedule_lookup.json')['answers'] as $date => $expected) {
      $this->assertSame($expected, $this->lookup($controller, 'schedule', (string) $date), "schedule $date");
    }
  }

  /**
   * Tests the last second of a slot is still that slot.
   *
   * The legacy module ended every slot one second early and answered `{}` at
   * hh:mm:59 of the slot's final minute. That is a defect, not behaviour to
   * keep: the expected answer is what legacy said for hh:mm:00 of the same
   * minute.
   */
  public function testScheduleLastSecondBelongsToTheSlot(): void {
    $controller = $this->createController($this->scheduleClient());

    foreach (self::snapshot('schedule_lookup.json')['lastSecondAnswers'] as $date => $expected) {
      $this->assertSame($expected, $this->lookup($controller, 'schedule', (string) $date), "schedule $date");
    }
  }

  /**
   * Tests /api/showname/{date} gives the legacy answer (name, or [] if none).
   */
  public function testShownameMatchesLegacyAnswers(): void {
    $controller = $this->createController($this->episodeClient());

    foreach (self::snapshot('showname_lookup.json')['answers'] as $date => $expected) {
      $this->assertSame($expected, $this->lookup($controller, 'showname', (string) $date), "showname $date");
    }
  }

  /**
   * Decoded body of a lookup endpoint.
   */
  protected function lookup(LookupController $controller, string $method, string $date) {
    return json_decode($controller->$method($date)->getContent(), TRUE);
  }

  /**
   * Fake client serving the snapshot's weekly slots as schedule_slot data.
   */
  protected function scheduleClient() {
    $data = [];
    foreach (self::snapshot('schedule_lookup.json')['slots'] as $i => $slot) {
      $data[] = [
        'type' => 'schedule_slot',
        'id' => 'slot-' . $i,
        'attributes' => [
          'day' => self::WEEKDAY_CODES[$slot['weekday']],
          'start_time' => $slot['start'],
          'end_time' => $slot['end'],
          'program_title' => $slot['name'],
          'timezone' => 'Australia/Melbourne',
        ],
      ];
    }

    return $this->createFakeClient(['getSchedule' => fn () => ['data' => $data]]);
  }

  /**
   * Fake client serving the snapshot's episodes as JSON:API episodes.
   */
  protected function episodeClient() {
    $melbourne = new \DateTimeZone('Australia/Melbourne');
    $utc = new \DateTimeZone('UTC');
    $fixture = ['data' => [], 'included' => []];
    $programIds = [];

    foreach (self::snapshot('showname_lookup.json')['episodes'] as $i => $episode) {
      $name = $episode['program'];
      if (!isset($programIds[$name])) {
        $programIds[$name] = 'program-' . count($programIds);
        $fixture['included'][] = [
          'type' => 'program',
          'id' => $programIds[$name],
          'attributes' => ['title' => $name],
        ];
      }

      $range = [];
      foreach (['value' => 'start', 'end_value' => 'end'] as $property => $key) {
        $range[$property] = (new \DateTimeImmutable($episode[$key], $melbourne))
          ->setTimezone($utc)
          ->format('c');
      }
      $fixture['data'][] = [
        'type' => 'episode',
        'id' => 'episode-' . $i,
        'attributes' => ['field_date_range' => $range],
        'relationships' => ['field_program' => ['data' => ['type' => 'program', 'id' => $programIds[$name]]]],
      ];
    }

    return $this->createFakeClient([
      'getEpisodes' => fn (array $filters = [], array $includes = [], array $page = [], ?string $sort = NULL) => $this->queryEpisodes($fixture, $filters, $page, $sort),
    ]);
  }

  /**
   * Creates a lookup controller around the given client.
   */
  protected function createController($client): LookupController {
    return new LookupController(new ProgramLookup(
      $client,
      $this->container->get('cache.default'),
      $this->container->get('cache_tags.invalidator')
    ));
  }

  /**
   * Loads a legacy snapshot fixture (usable from data providers).
   */
  protected static function snapshot(string $file): array {
    return json_decode(file_get_contents(dirname(__DIR__, 2) . '/fixtures/legacy_snapshot/' . $file), TRUE);
  }

}
