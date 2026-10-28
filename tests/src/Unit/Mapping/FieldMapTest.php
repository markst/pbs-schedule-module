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
   * Tests program attribute mapping on a real backend program.
   */
  public function testMapProgramAttributes(): void {
    $mapped = FieldMap::mapProgramAttributes($this->fixtureAttributes('jsonapi/program.json'));

    $this->assertSame('Black Wax', $mapped['name']);
    $this->assertSame('Adam Rudegeair', $mapped['broadcasters']);
    $this->assertSame("Groovin' jazz", $mapped['gridDescription']);
    $this->assertSame('adamrudegeair', $mapped['twitterHandle']);
    $this->assertSame('Black-Wax-on-PBS-FM-115780531226', $mapped['facebookPage']);
    $this->assertSame('/program/black-wax', $mapped['url']);
    $this->assertSame('fm', $mapped['defaultFirstAiredGuide']);
  }

  /**
   * Tests episode attribute mapping on a real backend episode.
   */
  public function testMapEpisodeAttributes(): void {
    $fixture = json_decode(file_get_contents(dirname(__DIR__, 3) . '/fixtures/jsonapi/episode.json'), TRUE);
    $mapped = FieldMap::mapEpisodeAttributes($fixture['data']['attributes']);

    $this->assertSame('2026-10-28 11:00:00', $mapped['start']);
    $this->assertSame('2026-10-28 11:30:00', $mapped['end']);
    $this->assertSame(1800, $mapped['duration']);
    $this->assertStringStartsWith('https://traffic.omny.fm/d/clips/', $mapped['url']);
  }

  /**
   * Tests duration uses smart_date minutes, else end - start seconds.
   */
  public function testEpisodeDurationSeconds(): void {
    $this->assertSame(1800, FieldMap::episodeDurationSeconds([
      'value' => '2026-10-28T00:00:00+00:00',
      'end_value' => '2026-10-28T01:00:00+00:00',
      'duration' => 30,
    ]));
    $this->assertSame(7200, FieldMap::episodeDurationSeconds([
      'value' => '2026-08-10T01:00:00+00:00',
      'end_value' => '2026-08-10T03:00:00+00:00',
      'duration' => NULL,
    ]));
    $this->assertSame(0, FieldMap::episodeDurationSeconds([]));
  }

  /**
   * Tests track attribute mapping on a real backend track.
   */
  public function testMapTrackAttributes(): void {
    $mapped = FieldMap::mapTrackAttributes($this->fixtureTrackAttributes());

    $this->assertSame('Transfiguration', $mapped['track']);
    $this->assertSame('Transfiguration', $mapped['title']);
    $this->assertSame('Even If & Especially When', $mapped['release']);
    $this->assertSame('00:00:00', $mapped['time']);
    $this->assertNull($mapped['notes']);
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
   * Tests the program slug prefers field_api_aod_slug over the alias.
   */
  public function testExtractProgramSlug(): void {
    $this->assertSame('black-wax', FieldMap::extractProgramSlug([
      'field_api_aod_slug' => 'black-wax',
      'path' => ['alias' => '/program/black-wax-renamed'],
    ]));
    $this->assertSame('black-wax-renamed', FieldMap::extractProgramSlug([
      'field_api_aod_slug' => NULL,
      'path' => ['alias' => '/program/black-wax-renamed'],
    ]));
    $this->assertNull(FieldMap::extractProgramSlug([]));
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
   * Tests minutes-from-midnight conversion to HH:MM:SS.
   */
  public function testFormatMinutesAsTime(): void {
    $this->assertSame('06:00:00', FieldMap::formatMinutesAsTime(360));
    $this->assertSame('08:15:00', FieldMap::formatMinutesAsTime(495));
    $this->assertSame('00:00:00', FieldMap::formatMinutesAsTime(0));
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
