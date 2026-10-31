<?php

namespace Drupal\Tests\api_proxy_pbs\Kernel;

use Drupal\api_proxy_pbs\Controller\LookupController;
use Drupal\api_proxy_pbs\Service\JsonApiClient;
use Drupal\api_proxy_pbs\Service\ProgramLookup;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\api_proxy_pbs\Traits\FakeJsonApiBackendTrait;

/**
 * Tests the showname/schedule lookups (ex pbs_airnet_api) against fixtures.
 *
 * Fixtures: Black Wax episode on 2026-07-27 11:00-13:00 Melbourne; weekly
 * slots Monday 06:00-09:00 (Breakfast Spread) and Sunday 13:00-15:00
 * (Radio City).
 *
 * @group api_proxy_pbs
 */
class LookupControllerKernelTest extends KernelTestBase {

  use FakeJsonApiBackendTrait;

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
   * Tests an episode is on air from its start up to, not including, its end.
   *
   * @dataProvider shownameProvider
   */
  public function testShowname(string $date, $expected): void {
    $response = $this->createController()->showname($date);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame($expected, json_decode($response->getContent(), TRUE));
  }

  /**
   * Showname cases; times are Melbourne local.
   */
  public function shownameProvider(): array {
    return [
      'at episode start' => ['20260727110000', 'Black Wax'],
      'inside the episode' => ['2026-07-27T12:00:00', 'Black Wax'],
      'last second' => ['20260727125959', 'Black Wax'],
      'at episode end' => ['20260727130000', []],
      'just before start' => ['20260727105959', []],
      'no episode that day' => ['20260728120000', []],
      'unparseable' => ['garbage', []],
    ];
  }

  /**
   * Tests the on-air lookup answers the presenters with the program name.
   */
  public function testOnAirProgramIncludesBroadcasters(): void {
    $lookup = new ProgramLookup(
      $this->createFakeClient(),
      $this->container->get('cache.default'),
      $this->container->get('cache_tags.invalidator')
    );

    $this->assertSame(
      ['name' => 'Black Wax', 'broadcasters' => 'Adam Rudegeair'],
      $lookup->onAirProgram(new \DateTimeImmutable('2026-07-27 12:00:00', new \DateTimeZone('Australia/Melbourne')))
    );
    $this->assertNull(
      $lookup->onAirProgram(new \DateTimeImmutable('2026-07-27 13:00:00', new \DateTimeZone('Australia/Melbourne')))
    );
  }

  /**
   * Tests the weekly grid answers by weekday and wall-clock time.
   *
   * @dataProvider scheduleProvider
   */
  public function testSchedule(string $date, $expected): void {
    $response = $this->createController()->schedule($date);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame($expected, json_decode($response->getContent(), TRUE));
  }

  /**
   * Schedule cases; times are Melbourne local.
   */
  public function scheduleProvider(): array {
    return [
      'slot start' => ['20261026060000', 'TEST | The Breakfast Spread'],
      'last second of slot' => ['20261026085959', 'TEST | The Breakfast Spread'],
      'slot end' => ['20261026090000', []],
      'weekday without the slot' => ['20261027070000', []],
      'any week matches' => ['20300107070000', 'TEST | The Breakfast Spread'],
      // Melbourne DST starts Sun 2026-10-04 and ends Sun 2027-04-04; the
      // 13:00-15:00 slot stays on the wall clock on both days.
      'sunday of DST start' => ['20261004133000', 'TEST | Radio City'],
      'DST start slot end' => ['20261004150000', []],
      'sunday of DST end' => ['20270404145959', 'TEST | Radio City'],
      'DST end slot end' => ['20270404150000', []],
      'unparseable' => ['garbage', []],
    ];
  }

  /**
   * Tests schedule slots are fetched once, and `clear` forces a refetch.
   */
  public function testScheduleSlotsAreCachedUntilCleared(): void {
    $controller = $this->createController();

    $controller->schedule('20261026070000');
    $controller->schedule('20261027070000');
    $this->assertSame(1, $this->scheduleRequests);

    $response = $controller->schedule('clear');
    $this->assertSame('Cache Cleared.', json_decode($response->getContent(), TRUE));
    $this->assertSame(0, $response->getMaxAge());

    $controller->schedule('20261026070000');
    $this->assertSame(2, $this->scheduleRequests);
  }

  /**
   * Tests a backend failure is 5xx rather than "nothing on air".
   */
  public function testBackendFailureIsServerError(): void {
    $failing = $this->createController($this->createFakeClient([
      'getEpisodes' => fn () => throw new \RuntimeException('backend down'),
      'getSchedule' => fn () => throw new \RuntimeException('backend down'),
    ]));

    $this->assertSame(500, $failing->showname('20260727120000')->getStatusCode());
    $this->assertSame(500, $failing->schedule('20261026070000')->getStatusCode());
  }

  /**
   * Creates a lookup controller backed by the fake backend.
   */
  protected function createController(?JsonApiClient $client = NULL): LookupController {
    return new LookupController(new ProgramLookup(
      $client ?? $this->createFakeClient(),
      $this->container->get('cache.default'),
      $this->container->get('cache_tags.invalidator')
    ));
  }

}
