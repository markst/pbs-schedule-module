<?php

namespace Drupal\api_proxy_pbs\Transformer;

use Drupal\api_proxy_pbs\Mapping\FieldMap;

/**
 * Transforms JSON:API program data to legacy format.
 */
class ProgramTransformer
{
    /**
     * Transform a single program from JSON:API to legacy format.
     *
     * @param array $jsonApiData
     *   JSON:API program resource.
     *
     * @return array
     *   Legacy format program data.
     */
    public static function transform(array $jsonApiData): array
    {
        $attributes = $jsonApiData['attributes'] ?? [];
        $relationships = $jsonApiData['relationships'] ?? [];

        // Base transformation using FieldMap
        $program = FieldMap::mapProgramAttributes($attributes);

        // Extract slug from path
        $program[FieldMap::PROGRAM_SLUG] = FieldMap::extractSlugFromPath(
            $attributes[FieldMap::PROGRAM_PATH]['alias'] ?? null
        );

        // Extract images from metatags
        $metatags = $attributes[FieldMap::IMAGE_METATAG] ?? [];
        $program[FieldMap::IMAGE_PROFILE] = FieldMap::extractImageFromMetatag($metatags, FieldMap::IMAGE_OG_IMAGE);
        $program[FieldMap::IMAGE_PROFILE_SMALL] = $program[FieldMap::IMAGE_PROFILE]; // Use same for small
        $program[FieldMap::IMAGE_BANNER] = $program[FieldMap::IMAGE_PROFILE]; // Use same for banner
        $program[FieldMap::IMAGE_BANNER_SMALL] = $program[FieldMap::IMAGE_PROFILE];

        // Build REST URLs
        if ($program[FieldMap::PROGRAM_SLUG]) {
            $program['episodesRestUrl'] = 'https://airnet.org.au/rest/stations/3pbs/programs/' . 
                                          $program[FieldMap::PROGRAM_SLUG] . '/episodes';
            $program['programRestUrl'] = 'https://airnet.org.au/rest/stations/3pbs/programs/' . 
                                         $program[FieldMap::PROGRAM_SLUG];
        }

        // Add podcast URLs if available
        $program['podcastUrl'] = null;
        $program['podcastUrl2'] = null;

        return $program;
    }

    /**
     * Transform multiple programs.
     *
     * @param array $jsonApiResponse
     *   Full JSON:API response with data array.
     *
     * @return array
     *   Array of legacy format programs.
     */
    public static function transformCollection(array $jsonApiResponse): array
    {
        $programs = [];
        
        foreach ($jsonApiResponse['data'] ?? [] as $program) {
            $programs[] = self::transform($program);
        }

        return $programs;
    }
}

