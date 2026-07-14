<?php

namespace Drupal\Tests\api_proxy_pbs\Integration;

use Drupal\Tests\api_proxy_pbs\Traits\LegacyContractTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Live HTTP tests against the running Lando site.
 *
 * These hit the real Drupal install (not the simpletest sandbox) and require
 * the JSON:API develop backend to be reachable.
 *
 * Run with: lando test-external
 *
 * @group api_proxy_pbs
 * @group api_proxy_pbs_external
 */
class ApiLiveHttpTest extends TestCase {

  use LegacyContractTestTrait;

  /**
   * Tests fortnight schedule against the live proxy.
   */
  public function testFortnightEndpointMatchesLegacyContract(): void {
    $body = $this->fetchJson('/api/fortnight');
    $this->assertIsArray($body);
    $this->assertNotEmpty($body);

    $this->assertLegacyContract($body[0], $this->scheduleEntryContractKeys());
    $this->assertSame('fm', $body[0]['guideId']);
    $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $body[0]['start']);
    $this->assertIsInt($body[0]['duration']);
    $this->assertStringContainsString('airnet.org.au/rest/stations/3pbs/programs/', $body[0]['programRestUrl']);
  }

  /**
   * Tests programs list against the live proxy.
   */
  public function testProgramsEndpointMatchesLegacyContract(): void {
    $body = $this->fetchJson('/api/programs');
    $this->assertIsArray($body);
    $this->assertNotEmpty($body);

    $this->assertLegacyContract($body[0], $this->programContractKeys());
    $this->assertNotEmpty($body[0]['slug']);
    $this->assertSame('fm', $body[0]['defaultFirstAiredGuide']);
    $this->assertStringContainsString('airnet.org.au/rest/stations/3pbs/programs/', $body[0]['episodesRestUrl']);
  }

  /**
   * Tests channel config against the live proxy.
   */
  public function testChannelEndpointMatchesLegacyContract(): void {
    $body = $this->fetchJson('/api/channels/fm');
    $expected = $this->loadFixture('legacy/channel.json');

    $this->assertLegacyContract($body, $this->channelContractKeys());
    $this->assertSame($expected, $body);
  }

  /**
   * Fetch and decode JSON from the running site.
   */
  protected function fetchJson(string $path): array {
    $base = getenv('API_PROXY_PBS_TEST_URL') ?: 'http://localhost';
    $host = getenv('API_PROXY_PBS_TEST_HOST') ?: 'pbss.lndo.site';

    $handle = curl_init($base . $path);
    curl_setopt_array($handle, [
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_TIMEOUT => 60,
      CURLOPT_HTTPHEADER => ["Host: $host"],
    ]);

    $response = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    $this->assertSame(200, $status, "Expected HTTP 200 for $path");
    $this->assertNotFalse($response);

    $data = json_decode($response, TRUE);
    $this->assertIsArray($data);

    return $data;
  }

}
