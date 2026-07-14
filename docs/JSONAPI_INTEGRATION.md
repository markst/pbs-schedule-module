# JSON:API Integration Guide

## Overview

This module transforms data from the PBS Drupal JSON:API backend into a legacy-compatible format for mobile applications.

**Backend URL:** `https://nginx-php.project-migration.pbsfm.au2.amazee.io`

**JSON:API Base:** `/api/v1`

**Authentication:** Basic Auth (configured in module settings)

## Architecture

### Services

#### JsonApiClient
HTTP client wrapper for JSON:API requests.

**Location:** `src/Service/JsonApiClient.php`

**Methods:**
- `getEpisodes($filters, $includes, $page)` - Query episodes
- `getPrograms($filters, $includes)` - Query programs  
- `getProgramByUuid($uuid, $includes)` - Single program
- `getEpisodeByUuid($uuid, $includes)` - Single episode
- `getEpisodeTracks($uuid)` - Episode tracks/playlist
- `resolveIncluded($data, $type, $id)` - Resolve relationships

#### SlugResolver
Maps legacy slugs to UUIDs with caching.

**Location:** `src/Service/SlugResolver.php`

**Methods:**
- `resolveProgram($slug)` - Resolve program slug → UUID
- `resolveEpisode($slug, $date)` - Resolve episode slug+date → UUID
- `buildProgramCache()` - Warm cache with all programs
- `invalidateProgramCache($slug)` - Clear cache entry
- `invalidateEpisodeCache($slug, $date)` - Clear episode cache

**Cache Strategy:**
- Cache bin: `cache.default`
- Cache key format: `pbs_slug_program:{slug}` or `pbs_slug_episode:{slug}:{date}`
- TTL: 24 hours (86400 seconds)
- Fallback: JSON:API query on cache miss
- Null caching: Cache null for 1 hour to prevent repeated lookups

## JSON:API Endpoints Consumed

### GET /api/v1/episode
Query episodes with filtering and pagination.

**Filters:**
- `field_date_range.value` - Episode start date
- `field_program.id` - Program UUID
- `status` - Published status

**Includes:**
- `field_program` - Include program details
- `field_tracks` - Include tracks (for playlists)

**Example:**
```
/api/v1/episode?filter[field_date_range.value][operator]=>= &filter[field_date_range.value][value]=2025-11-29 &include=field_program&page[limit]=500
```

### GET /api/v1/program
Query programs with filtering.

**Filters:**
- `status` - Published status
- `path.alias` - URL path for slug resolution

**Example:**
```
/api/v1/program?filter[status]=true
```

### GET /api/v1/program/{uuid}
Single program by UUID.

### GET /api/v1/episode/{uuid}
Single episode by UUID.

**Includes:**
- `field_program` - Program details

### GET /api/v1/episode/{uuid}/field_tracks
Tracks for an episode.

**Includes:**
- `field_artist` - Artist details

## Field Mappings

Defined in `src/Mapping/FieldMap.php`.

### Program Fields

| JSON:API Field | Legacy Field | Transformation |
|----------------|--------------|----------------|
| `title` | `name` | Direct |
| `field_author` | `broadcasters` | Direct |
| `field_body.processed` | `description` | HTML content |
| `field_summary` | `gridDescription` | Direct |
| `path.alias` | `slug` | Extract from "/program/{slug}" |
| `field_link_x` | `twitterHandle` | Direct |
| `field_link_facebook` | `facebookPage` | Direct |
| `metatag[og:image]` | `profileImageUrl` | Extract from metatag array |

### Episode Fields

| JSON:API Field | Legacy Field | Transformation |
|----------------|--------------|----------------|
| `field_date_range.value` | `start` | ISO 8601 → "Y-m-d H:i:s" |
| `field_date_range.end_value` | `end` | ISO 8601 → "Y-m-d H:i:s" |
| `field_date_range.duration` | `duration` | Minutes × 60 → seconds |
| `title` | `title` | Direct |
| `field_body.processed` | `description` | HTML content |
| `field_audio_url.uri` | `url` | Direct |

### Track Fields

| JSON:API Field | Legacy Field | Transformation |
|----------------|--------------|----------------|
| `field_title` | `track`, `title` | Direct |
| `field_album` | `release` | Direct |
| `field_track_marker` | `time` | Seconds → "HH:MM:SS" |
| `field_descriptors` | `contentDescriptors` | Array → object with booleans |
| `field_artist.name` | `artist` | Resolve from relationship |

### Schedule Fields

Schedule entries are built from episodes:
- `day`: Calculated from episode start date (1-14)
- `start`: Time extracted from ISO datetime ("HH:mm:ss")
- `startTime`: ISO 8601 timestamp
- `guideId`: Always "fm"
- Other fields mapped from program data

## Transformation Flow

### 1. Fortnight Schedule
```
Request → JsonApiClient.getEpisodes()
       → Filter: next 14 days, include program
       → ScheduleTransformer.transformToSchedule()
       → Calculate day numbers
       → Sort by day + time
       → Response
```

### 2. Program by Slug
```
Request → SlugResolver.resolveProgram(slug)
       → Check cache
       → JSON:API query if miss
       → JsonApiClient.getProgramByUuid(uuid)
       → ProgramTransformer.transform()
       → Extract images from metatags
       → Response
```

### 3. Episode Playlist
```
Request → SlugResolver.resolveEpisode(slug, date)
       → JsonApiClient.getEpisodeTracks(uuid)
       → Include artist relationships
       → TrackTransformer.transformCollection()
       → Resolve artists from included
       → Map content descriptors
       → Response
```

## Performance Considerations

### Caching Layers
1. **Slug Resolution Cache**: 24h TTL, reduces UUID lookups
2. **HTTP Response Cache**: 1-24h TTL per endpoint
3. **Drupal Page Cache**: For cacheable responses

### Optimization Strategies
- Use `?include` parameter to fetch relationships in one request
- Set appropriate page limits (default: 500 for schedules)
- Warm slug cache on deployment with `SlugResolver::buildProgramCache()`
- Monitor cache hit rates via Drupal admin

### Query Efficiency
- Filter episodes by date range to limit results
- Use pagination for large result sets
- Minimize included relationships to only what's needed

## Authentication

**Method:** HTTP Basic Authentication

**Configuration:**
- Username: Set in `api_proxy_pbs.settings.jsonapi_auth_username`
- Password: Set in `api_proxy_pbs.settings.jsonapi_auth_password` (never commit real credentials)
- Header: `Authorization: Basic {base64(username:password)}`

Credentials must be configured per environment via the PBS Schedule settings form or `drush config:set`.

## Error Handling

### JSON:API Errors
JSON:API errors are caught and logged:
```php
try {
    $response = $this->jsonApiClient->getEpisodes(...);
} catch (\Exception $e) {
    \Drupal::logger('api_proxy_pbs')->error('...');
    return new JsonResponse(['error' => '...'], 500);
}
```

### Null Handling
- Missing fields default to `null`
- Empty arrays default to `[]`
- Failed transformations return empty/error responses

## Configuration

Module settings at `/admin/config/services/api-proxy-pbs/settings`:

- **JSON:API Base URL**: Backend URL
- **JSON:API Username**: Auth username
- **JSON:API Password**: Auth password
- **Slug Cache TTL**: Cache duration in seconds

## Development

### Adding New Endpoints

1. Add method to `JsonApiClient` for JSON:API query
2. Create transformer if needed in `src/Transformer/`
3. Add controller method in appropriate controller
4. Define route in `api_proxy_pbs.routing.yml`
5. Update field mappings in `FieldMap.php` if needed

### Testing JSON:API Queries

Use curl to test directly:
```bash
curl -H "Authorization: Basic cGJzOnBiczIwMjU=" \
  "https://nginx-php.project-migration.pbsfm.au2.amazee.io/api/v1/episode?filter[status]=true&page[limit]=1"
```

### Debugging

Enable debug logging:
```php
\Drupal::logger('api_proxy_pbs')->debug('Message', ['context' => $data]);
```

View logs: `/admin/reports/dblog`

## Troubleshooting

### Slug Resolution Fails
- Check cache: `drush cache:rebuild`
- Verify path.alias in JSON:API response
- Test slug directly: `SlugResolver::resolveProgram('test-slug')`

### Missing Fields
- Verify field exists in JSON:API response
- Check FieldMap transformation
- Ensure field is included in query

### Authentication Errors
- Verify credentials in module settings
- Check Basic Auth header in requests
- Test credentials with curl

### Performance Issues
- Check cache hit rates
- Optimize `?include` parameters
- Reduce page limits
- Warm slug cache

