<?php

namespace Drupal\api_proxy_pbs\Tests;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests for the PBS Api module.
 *
 * @group api_proxy_pbs
 */
class ApiTests extends BrowserTestBase
{
    /**
     * Modules to install
     *
     * @var array
     */
    public static $modules = ['api_proxy_pbs'];

    // Perform initial setup tasks that run before every test method.
    public function setUp()
    {
        parent::setUp();
    }

    /**
     * Tests that the fortnight endpoint can be reached.
     */
    public function testFortnightEndpointExists()
    {
        $this->drupalGet('api/fortnight');
        $this->assertSession()->statusCodeEquals(200);
    }
}
