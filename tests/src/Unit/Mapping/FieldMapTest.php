<?php

namespace Drupal\Tests\api_proxy_pbs\Unit\Mapping;

use Drupal\api_proxy_pbs\Mapping\FieldMap;
use Drupal\Tests\UnitTestCase;

/**
 * Tests FieldMap transformations documented in JSONAPI_INTEGRATION.md.
 *
 * @group api_proxy_pbs
 */
class FieldMapTest extends UnitTestCase {

  /**
   * Tests program attribute mapping.
   */
  public function testMapProgramAttributes(): void {
    $attributes = $this->fixtureAttributes('jsonapi/program.json');
    $mapped = FieldMap::mapProgramAttributes($attributes);

    $this->assertSame('Lullabies to Anthems', $mapped['name']);
    $this->assertSame('Jane Doe, John Smith', $mapped['broadcasters']);
    $this->assertSame('<p>Full HTML description</p>', $mapped['description']);
    $this->assertSame('Music genre/style', $mapped['gridDescription']);
    $this->assertSame('pbsfm', $mapped['twitterHandle']);
    $this->assertSame('pbsfm', $mapped['facebookPage']);
    $this->assertSame('/program/lullabies-to-anthems', $mapped['url']);
    $this->assertSame('fm', $mapped['defaultFirstAiredGuide']);
  }

  /**
   * Tests episode attribute mapping including duration conversion.
   */
  public function testMapEpisodeAttributes(): void {
    $attributes = $this->fixtureAttributes('jsonapi/episode.json');
    $mapped = FieldMap::mapEpisodeAttributes($attributes);

    $this->assertSame('2025-11-17 02:00:00', $mapped['start']);
    $this->assertSame('2025-11-17 06:00:00', $mapped['end']);
    $this->assertSame(14400, $mapped['duration']);
    $this->assertSame('Episode Title', $mapped['title']);
    $this->assertSame('<p>Episode HTML description</p>', $mapped['notes']);
    $this->assertSame('https://example.com/audio.m4a', $mapped['url']);
  }

  /**
   * Tests track attribute mapping.
   */
  public function testMapTrackAttributes(): void {
    $track = $this->fixtureTrackAttributes();
    $mapped = FieldMap::mapTrackAttributes($track);

    $this->assertSame('Track Title', $mapped['track']);
    $this->assertSame('Track Title', $mapped['title']);
    $this->assertSame('Album Name', $mapped['release']);
    $this->assertSame('02:00:00', $mapped['time']);
    $this->assertSame('Track notes', $mapped['notes']);
  }

  /**
   * Tests content descriptor mapping to legacy camelCase booleans.
   */
  public function testMapContentDescriptors(): void {
    $mapped = FieldMap::mapContentDescriptors(['is_australian', 'is_woman']);

    $this->assertTrue($mapped['isAustralian']);
    $this->assertTrue($mapped['isFemale']);
    $this->assertNull($mapped['isLocal']);
    $this->assertNull($mapped['isGenderNonConforming']);
    $this->assertNull($mapped['isIndigenous']);
    $this->assertNull($mapped['isNew']);
  }

  /**
   * Tests slug extraction from Drupal path aliases.
   */
  public function testExtractSlugFromPath(): void {
    $this->assertSame('lullabies-to-anthems', FieldMap::extractSlugFromPath('/program/lullabies-to-anthems'));
    $this->assertSame('lullabies-to-anthems', FieldMap::extractSlugFromPath('/program/lullabies-to-anthems/extra'));
    $this->assertNull(FieldMap::extractSlugFromPath('/node/123'));
    $this->assertNull(FieldMap::extractSlugFromPath(NULL));
  }

  /**
   * Tests og:image extraction from metatag arrays.
   */
  public function testExtractImageFromMetatag(): void {
    $metatags = [
      ['attributes' => ['property' => 'og:image', 'content' => 'https://example.com/image.jpg']],
    ];
    $this->assertSame('https://example.com/image.jpg', FieldMap::extractImageFromMetatag($metatags));
    $this->assertNull(FieldMap::extractImageFromMetatag([]));
  }

  /**
   * Tests Drupal link field objects are normalized to strings.
   */
  public function testExtractLinkFieldsFromJsonApiObjects(): void {
    $twitter = FieldMap::extractTwitterHandle([
      'uri' => 'https://x.com/adamrudegeair',
      'title' => NULL,
      'options' => [],
    ]);
    $facebook = FieldMap::extractFacebookPage([
      'uri' => 'https://www.facebook.com/Black-Wax-on-PBS-FM-115780531226',
      'title' => NULL,
      'options' => [],
    ]);

    $this->assertSame('adamrudegeair', $twitter);
    $this->assertSame('Black-Wax-on-PBS-FM-115780531226', $facebook);
  }

  /**
   * Tests legacy @handle strings still work.
   */
  public function testExtractTwitterHandleFromLegacyString(): void {
    $this->assertSame('pbsfm', FieldMap::extractTwitterHandle('@pbsfm'));
    $this->assertNull(FieldMap::extractLinkString(NULL));
  }

  /**
   * Tests ISO date formatting to legacy Airnet format in Melbourne time.
   */
  public function testFormatDateLegacy(): void {
    $this->assertSame('2025-11-17 02:00:00', FieldMap::formatDateLegacy('2025-11-17T02:00:00+11:00'));
    $this->assertSame('2026-02-16 12:00:05', FieldMap::formatDateLegacy('2026-02-16T12:00:05+11:00'));
    $this->assertSame('2026-02-16 12:00:05', FieldMap::formatDateLegacy('2026-02-16T01:00:05+00:00'));
    $this->assertNull(FieldMap::formatDateLegacy(''));
  }

  /**
   * Tests track marker seconds to HH:MM:SS.
   */
  public function testFormatTrackTime(): void {
    $this->assertSame('02:00:00', FieldMap::formatTrackTime(7200));
    $this->assertSame('00:05:30', FieldMap::formatTrackTime(330));
    $this->assertNull(FieldMap::formatTrackTime(NULL));
  }

  /**
   * Tests fortnight day calculation returns a value in the 1-14 range.
   */
  public function testCalculateFortnightDay(): void {
    $episodeDate = new \DateTime('2025-12-01T06:00:00+11:00');
    $day = FieldMap::calculateFortnightDay($episodeDate);
    $this->assertGreaterThanOrEqual(1, $day);
    $this->assertLessThanOrEqual(14, $day);
  }

  /**
   * Tests minutes-from-midnight conversion to HH:MM:SS.
   */
  public function testFormatMinutesAsTime(): void {
    $this->assertSame('06:00:00', FieldMap::formatMinutesAsTime(360));
    $this->assertSame('08:15:00', FieldMap::formatMinutesAsTime(495));
    $this->assertSame('00:00:00', FieldMap::formatMinutesAsTime(0));
  }

  /**
   * Tests fortnight day calculation from a slot date.
   */
  public function testCalculateFortnightDayFromDate(): void {
    $fortnightStart = new \DateTime('2025-12-01', new \DateTimeZone('Australia/Melbourne'));
    $this->assertSame(1, FieldMap::calculateFortnightDayFromDate('2025-12-01', $fortnightStart));
    $this->assertSame(2, FieldMap::calculateFortnightDayFromDate('2025-12-02', $fortnightStart));
    $this->assertSame(8, FieldMap::calculateFortnightDayFromDate('2025-12-08', $fortnightStart));
  }

  /**
   * Tests relative URI absolutization.
   */
  public function testMakeAbsoluteUrl(): void {
    $this->assertSame(
      'https://example.com/sites/default/files/profile.jpg',
      FieldMap::makeAbsoluteUrl('https://example.com', '/sites/default/files/profile.jpg')
    );
    $this->assertSame(
      'https://cdn.example.com/image.jpg',
      FieldMap::makeAbsoluteUrl('https://example.com', 'https://cdn.example.com/image.jpg')
    );
    $this->assertNull(FieldMap::makeAbsoluteUrl('https://example.com', NULL));
  }

  /**
   * Loads attributes from a program fixture.
   */
  protected function fixtureAttributes(string $path): array {
    $fixture = json_decode(file_get_contents(dirname(__DIR__, 3) . '/fixtures/' . $path), TRUE);
    return $fixture['attributes'];
  }

  /**
   * Loads track attributes from the tracks fixture.
   */
  protected function fixtureTrackAttributes(): array {
    $fixture = json_decode(file_get_contents(dirname(__DIR__, 3) . '/fixtures/jsonapi/tracks_collection.json'), TRUE);
    return $fixture['data'][0]['attributes'];
  }

}
