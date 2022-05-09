<?php

/**
 * @file
 * Local settings file.
 *
 * Copy this file to settings.local.php, which is excluded from the repo and
 * will not be committed.
 */

// Enable error reporting.
error_reporting(E_ALL | E_STRICT);

$settings['container_yamls'][] = DRUPAL_ROOT . '/sites/development.services.yml';

$config['system.performance']['css']['preprocess'] = FALSE;
$config['system.performance']['js']['preprocess'] = FALSE;

$settings['cache']['bins']['render'] = 'cache.backend.null';
$settings['cache']['bins']['dynamic_page_cache'] = 'cache.backend.null';

$settings['cache']['bins']['page'] = 'cache.backend.null';

$settings['extension_discovery_scan_tests'] = FALSE;

// Local Database config.
$databases['default']['default'] = array (
  'database' => 'pbss_db',
  'username' => 'pbss_user',
  'password' => 'password',
  'prefix' => '',
  'host' => 'localhost',
  'port' => '3306',
  'namespace' => 'Drupal\\Core\\Database\\Driver\\mysql',
  'driver' => 'mysql',
);

$config['imagemagick.settings']['path_to_binaries'] = '/usr/local/bin/';

$config['search_api']['index_apo_search']['search_api']['server'] = "<app_id>";

// disable email sending.
$config['reroute_email.settings']['enable'] = TRUE;
$config['reroute_email.settings']['address'] = 'email@address.com';
