<?php

namespace Drupal\Tests\api_proxy_pbs\Traits;

/**
 * Helpers for loading fixtures and validating legacy Airnet JSON contracts.
 */
trait LegacyContractTestTrait {

  /**
   * Load a JSON fixture relative to the module tests directory.
   */
  protected function loadFixture(string $relativePath): array {
    $path = dirname(__DIR__, 2) . '/fixtures/' . $relativePath;
    $this->assertFileExists($path);
    $data = json_decode(file_get_contents($path), TRUE);
    $this->assertIsArray($data);
    return $data;
  }

  /**
   * Assert an array contains all keys from the legacy contract.
   *
   * @param array $actual
   *   The transformed response.
   * @param array $contractKeys
   *   Required top-level keys from docs/API.md.
   */
  protected function assertLegacyContract(array $actual, array $contractKeys): void {
    foreach ($contractKeys as $key) {
      $this->assertArrayHasKey($key, $actual, "Missing legacy contract key: $key");
    }
  }

  /**
   * Assert selected keys match between actual and expected fixture values.
   */
  protected function assertLegacyFieldsMatch(array $actual, array $expected, array $keys): void {
    foreach ($keys as $key) {
      $this->assertSame($expected[$key], $actual[$key], "Field mismatch for: $key");
    }
  }

  /**
   * Required keys for each legacy endpoint per docs/API.md.
   */
  protected function scheduleEntryContractKeys(): array {
    return [
      'guideId',
      'day',
      'start',
      'duration',
      'name',
      'broadcasters',
      'gridDescription',
      'slug',
      'startTime',
      'profileImage',
      'bannerImage',
      'programRestUrl',
      'archived',
    ];
  }

  protected function programContractKeys(): array {
    return [
      'name',
      'broadcasters',
      'description',
      'gridDescription',
      'slug',
      'url',
      'profileImageUrl',
      'bannerImageUrl',
      'twitterHandle',
      'facebookPage',
      'defaultFirstAiredGuide',
      'episodesRestUrl',
    ];
  }

  protected function episodeContractKeys(): array {
    return [
      'start',
      'end',
      'duration',
      'title',
      'description',
      'imageUrl',
      'smallImageUrl',
      'currentEpisode',
      'multipleEpsOnDay',
      'episodeRestUrl',
    ];
  }

  protected function trackContractKeys(): array {
    return [
      'type',
      'id',
      'artist',
      'title',
      'track',
      'release',
      'time',
      'notes',
      'contentDescriptors',
      'wikipedia',
      'image',
      'video',
      'url',
      'approximateTime',
    ];
  }

  protected function contentDescriptorContractKeys(): array {
    return [
      'isAustralian',
      'isLocal',
      'isFemale',
      'isGenderNonConforming',
      'isIndigenous',
      'isNew',
    ];
  }

  protected function channelContractKeys(): array {
    return [
      'enableAudioServer',
      'enableOnDemandRecording',
      'liveStreamUrl',
      'liveStreamUrlHQ',
      'liveStreamUrl128',
      'browserLiveStreamUrl',
      'browserReplayUrlTemplate',
      'replaySourceUrl',
    ];
  }

}
