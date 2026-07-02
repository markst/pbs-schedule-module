<?php

namespace Drupal\api_proxy_pbs\Transformer;

use Drupal\api_proxy_pbs\Mapping\FieldMap;

/**
 * Transforms JSON:API track/playlist data to legacy format.
 */
class TrackTransformer
{
    /**
     * Transform a single track from JSON:API to legacy format.
     *
     * @param array $jsonApiData
     *   JSON:API track resource.
     * @param array|null $includedArtist
     *   The included artist data if available.
     *
     * @return array
     *   Legacy format track data.
     */
    public static function transform(array $jsonApiData, ?array $includedArtist = null): array
    {
        $attributes = $jsonApiData['attributes'] ?? [];
        $relationships = $jsonApiData['relationships'] ?? [];

        // Base transformation using FieldMap
        $track = FieldMap::mapTrackAttributes($attributes);

        // Add track ID
        $track['id'] = $jsonApiData['id'] ?? null;
        $track['type'] = 'track';

        // Artist name
        $artistName = '';
        if ($includedArtist) {
            $artistName = $includedArtist['attributes']['name'] ?? '';
        }
        $track['artist'] = $artistName;

        // Content descriptors
        $descriptors = $attributes[FieldMap::TRACK_DESCRIPTORS] ?? [];
        $track['contentDescriptors'] = FieldMap::mapContentDescriptors($descriptors);

        // Additional fields
        $track['wikipedia'] = null;
        $track['image'] = null;
        $track['video'] = null;
        $track['url'] = null;
        $track['twitterHandle'] = null;

        // Approximate time (same as time for now)
        if ($track[FieldMap::TRACK_TIME]) {
            $track['approximateTime'] = $track[FieldMap::TRACK_TIME];
        } else {
            $track['approximateTime'] = null;
        }

        return $track;
    }

    /**
     * Transform track collection (playlist).
     *
     * @param array $jsonApiResponse
     *   Full JSON:API response with tracks.
     *
     * @return array
     *   Array of legacy format tracks.
     */
    public static function transformCollection(array $jsonApiResponse): array
    {
        $tracks = [];
        $included = $jsonApiResponse['included'] ?? [];

        // Build artist map
        $artistsMap = [];
        foreach ($included as $item) {
            if ($item['type'] === 'artist') {
                $artistsMap[$item['id']] = $item;
            }
        }

        foreach ($jsonApiResponse['data'] ?? [] as $track) {
            // Resolve artist
            $artistId = $track['relationships']['field_artist']['data']['id'] ?? null;
            $artist = $artistId ? ($artistsMap[$artistId] ?? null) : null;

            $tracks[] = self::transform($track, $artist);
        }

        return $tracks;
    }
}

