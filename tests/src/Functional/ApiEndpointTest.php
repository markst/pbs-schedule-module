<?php

namespace Drupal\Tests\api_proxy_pbs\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\api_proxy_pbs\Traits\LegacyContractTestTrait;

/**
 * Functional smoke tests for static API endpoints.
 *
 * @group api_proxy_pbs
 */
class ApiEndpointTest extends BrowserTestBase {

  use LegacyContractTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['api_proxy_pbs'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests channel config endpoint returns legacy contract.
   */
  public function testChannelEndpointMatchesLegacyContract(): void {
    $this->drupalGet('api/channels/fm');
    $this->assertSession()->statusCodeEquals(200);

    $body = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $expected = $this->loadFixture('legacy/channel.json');

    $this->assertLegacyContract($body, $this->channelContractKeys());
    $this->assertSame($expected, $body);
    $this->assertSession()->responseHeaderEquals('content-type', 'application/json; charset=utf-8');
  }

}
