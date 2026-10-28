<?php

namespace Drupal\Tests\api_proxy_pbs\Unit\Transformer;

use Drupal\api_proxy_pbs\Transformer\EpisodeTransformer;
use Drupal\Tests\api_proxy_pbs\Traits\LegacyContractTestTrait;
use Drupal\Tests\UnitTestCase;

/**
 * Tests episode transformation matches legacy Airnet contract.
 *
 * @group api_proxy_pbs
 */
class EpisodeTransformerTest extends UnitTestCase {

  use LegacyContractTestTrait;

  /**
   * Tests a real backend episode transforms to the documented legacy shape.
   */
  public function testTransformMatchesLegacyContract(): void {
    $response = $this->loadFixture('jsonapi/episode.json');
    $expected = $this->loadFixture('legacy/episode.json');
    $actual = EpisodeTransformer::transform($response['data'], $response['included'][0]);

    $this->assertLegacyContract($actual, $this->episodeContractKeys());
    $keys = array_diff(array_keys($expected), ['currentEpisode']);
    $this->assertLegacyFieldsMatch($actual, $expected, $keys);
    $this->assertIsBool($actual['currentEpisode']);
  }

  /**
   * Tests `url` comes from the AOD audio field, else the podcast audio field.
   */
  public function testUrlUsesAodThenPodcastAudio(): void {
    $episode = $this->loadFixture('jsonapi/episode.json')['data'];
    $aod = $episode['attributes']['field_api_aod_audio_url']['uri'];
    $this->assertSame($aod, EpisodeTransformer::transform($episode)['url']);

    $episode['attributes']['field_api_aod_audio_url'] = NULL;
    $episode['attributes']['field_api_podcast_audio_url'] = ['uri' => 'https://example.com/podcast.mp3'];
    $this->assertSame('https://example.com/podcast.mp3', EpisodeTransformer::transform($episode)['url']);
  }

  /**
   * Tests duration falls back to end - start when the backend omits it.
   */
  public function testDurationFallsBackToEndMinusStart(): void {
    $response = $this->loadFixture('jsonapi/episodes_collection.json');
    $this->assertNull($response['data'][0]['attributes']['field_date_range']['duration']);

    $episodes = EpisodeTransformer::transformCollection($response);

    $this->assertCount(3, $episodes);
    $this->assertSame(7200, $episodes[0]['duration']);
    $this->assertSame('2026-07-27 11:00:00', $episodes[0]['start']);
    $this->assertSame(
      'https://airnet.org.au/rest/stations/3pbs/programs/black-wax/episodes/2026-07-27+11%3A00%3A00',
      $episodes[0]['episodeRestUrl']
    );
  }

}
