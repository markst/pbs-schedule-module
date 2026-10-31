# Migration Guide: Airnet API → JSON:API

## Overview

This document outlines the migration from the legacy Airnet REST API to the new PBS Drupal JSON:API backend.

**Version:** 1.2.2 → 2.0.0

**Status:** Complete

## Breaking Changes

### Removed Features

#### 1. Insomnia Rotation
**Status:** REMOVED

The bi-weekly rotation logic for late-night "Insomnia" shows has been retired. Programs are now managed directly in the schedule.

**Removed:**
- `/api/insomnia-lookup` endpoint
- `src/insomnia-lookup.json` file
- Insomnia configuration in admin form
- Rotation logic in ScheduleController

**Action Required:** None for mobile apps (transparent change)

#### 2. Omny Integration
**Status:** REMOVED

Direct Omny Studio API integration has been replaced with audio URLs stored in Drupal.

**Removed:**
- `/api/omny-programs` endpoint
- `src/Controller/OmnyController.php`
- Omny API slug mapping logic
- `StreamController::getOmnySlug()` method

**Migration:**
`/api/programs/{slug}/episodes/{date}/stream` resolves the episode and 302s to
`field_api_aod_audio_url` (the Omny clip of the broadcast), falling back to
`field_api_podcast_audio_url`. Episodes with neither return 404; there is no
synthesized Airnet URL fallback any more. `{date}` also accepts the `YmdHi`
timestamp iOS substitutes for `???EPISODE???`.

**Action Required (owner decision):** iOS builds replay URLs from
`browserReplayUrlTemplate` in `/api/channels/fm`, which still points at
`https://airnet.org.au/omnystudio/3pbs/???PROGRAM???/???EPISODE???/aac_mid.m4a`.
Playback only uses the backend's audio fields once that template is switched
(via the `config` override setting) to
`https://<proxy-host>/api/programs/???PROGRAM???/episodes/???EPISODE???/stream`.
Do that before Airnet's omnystudio redirect is retired, after confirming
`field_api_aod_audio_url` is populated for the archive.

#### 3. pbs_airnet_api module (merged)
**Status:** MERGED, module to be sunset in pbss

The separate `pbs_airnet_api` Drupal module (`/api/showname/{date}`, `/api/schedule/{date}`) is now part of this module (`LookupController`, `Service/ProgramLookup`). It no longer calls this site's own legacy endpoints over HTTP, so there is no per-program request loop; both lookups read JSON:API directly.

**Behaviour changes:**
- `/api/showname/{date}`: one episode query (start ≤ t < end) instead of fetching `numBefore=100` episodes for every program. Same answer: the on-air episode with the latest start.
- `/api/schedule/{date}`: matches weekday + wall-clock time against the schedule templates. The old 14-day cycle anchored on 2010-01-04 (and its daylight-saving corrections) is gone; a fixed 14-day cycle can disagree with `/api/fortnight`'s ISO-week labelling after 53-week years.
- No match (or unparseable date) is always `[]`. Previously `/api/showname/{date}` returned `[]` and `/api/schedule/{date}` had no defined no-match response.
- Backend failures answer `500 {"error": ...}` without the exception message.
- `/api/showname/clear` and `/api/schedule/clear` still answer `"Cache Cleared."`; they now invalidate one cache tag (`api_proxy_pbs:lookup`), which also drops cached responses, and the `clear` response itself is not cached. Both endpoints previously shared one cache key by mistake.
- `Proxy-Version` is not sent on these two endpoints.
- Fortnightly and monthly schedule slots are not yet honoured by `/api/fortnight`, `/api/schedule` and `/api/schedule/{date}` (they appear every week). Pending backend PBS-260 date filtering.

**Action Required (pbss):**
1. Pull this module into pbss (`git subtree pull`, see `api_proxy_pbs.subtree.md`).
2. `drush cr`, then check `/api/showname/{YYYYMMDDHHMMSS}` and `/api/schedule/{YYYYMMDDHHMMSS}`.
3. Uninstall `pbs_airnet_api` (`drush pmu pbs_airnet_api`, export config so `core.extension.yml` drops it) and delete `web/modules/custom/pbs_airnet_api`. Its routes use the same paths, so the module must not stay enabled alongside this one.

### Changed Behavior

#### Slug Resolution
**Old:** Direct slug usage in API paths  
**New:** Slugs resolved to UUIDs via cache + JSON:API query

**Impact:** Small performance overhead on first lookup (cached thereafter)

#### Schedule Generation
**Old:** Pre-built weekly schedule duplicated for fortnight  
**New:** Episode-based schedule built from 14-day query

**Impact:** More accurate, reflects actual episode data

#### Duration Format
**Old:** Sometimes inconsistent seconds  
**New:** `field_date_range.duration` minutes × 60, or end − start seconds when the backend leaves `duration` null

**Impact:** More consistent duration values

## Deprecated Features

### None (Hard Cutover)

All deprecated features have been completely removed in version 2.0.0. There is no backwards compatibility mode.

## New Requirements

### 1. Services Configuration

Module now requires service definitions in `api_proxy_pbs.services.yml`:
- `api_proxy_pbs.jsonapi_client`
- `api_proxy_pbs.slug_resolver`

**Action:** Ensure services.yml is deployed

The module still depends on `api_proxy:api_proxy` (declared in `api_proxy_pbs.info.yml`) because the `pbs_jsonapi` plugin (`AirnetProxy`) extends `api_proxy`'s `HttpApiPluginBase`.

### 2. Configuration Settings

New module configuration at `/admin/config/services/api-proxy-pbs/settings`:
- JSON:API Base URL
- Authentication credentials
- Cache TTL settings

**Action:** Configure before deployment

### 3. Cache Warming

Slug resolution relies on cached UUID mappings.

**Action:** Run cache warming on deployment:
```php
\Drupal::service('api_proxy_pbs.slug_resolver')->buildProgramCache();
```

### 4. Authentication

JSON:API backend requires Basic Auth.

**Action:** Ensure credentials configured and working

## Deployment Checklist

### Pre-Deployment

- [ ] Backup current module configuration
- [ ] Test JSON:API backend connectivity
- [ ] Verify authentication credentials
- [ ] Review slug mappings (sample programs)
- [ ] Test transformation logic with sample data

### Deployment Steps

1. **Deploy Code**
   ```bash
   git pull origin main
   composer install
   ```

2. **Clear Drupal Cache**
   ```bash
   drush cache:rebuild
   ```

3. **Update Configuration**
   - Navigate to `/admin/config/services/api-proxy-pbs/settings`
   - Set JSON:API Base URL (environment-specific)
   - Configure authentication credentials
   - Save configuration

4. **Warm Slug Cache**
   ```bash
   drush php:eval "\Drupal::service('api_proxy_pbs.slug_resolver')->buildProgramCache();"
   ```

5. **Test Endpoints**
   ```bash
   # Test fortnight schedule
   curl https://schedule.pbsfm.org.au/api/fortnight
   
   # Test program lookup
   curl https://schedule.pbsfm.org.au/api/programs/test-program
   
   # Test playlist
   curl "https://schedule.pbsfm.org.au/api/programs/test-program/episodes/2025-12-01+00:00:00/playlists"
   ```

6. **Monitor Logs**
   ```bash
   drush watchdog:tail
   ```

### Post-Deployment

- [ ] Verify all endpoints return valid responses
- [ ] Check cache hit rates
- [ ] Monitor error logs for transformation failures
- [ ] Test with mobile app (iOS/Android)
- [ ] Verify audio streaming works
- [ ] Compare response samples with legacy API

## Testing Strategy

### Unit Tests

Run PHPUnit tests:
```bash
vendor/bin/phpunit tests/src/Unit/
```

Tests cover:
- FieldMap transformations
- SlugResolver cache logic
- Transformer output format
- Date/duration conversions

### Functional Tests

Run functional tests:
```bash
vendor/bin/phpunit tests/src/Functional/
```

Tests cover:
- Endpoint response codes
- Response structure validation
- Cache headers
- Error handling

### Integration Tests

#### Mobile App Testing

**iOS:**
1. Use existing Swift decoders
2. Test fortnight schedule parsing
3. Verify program detail views
4. Test episode playlist display
5. Verify audio playback

**Android:**
1. Use existing Dart/Kotlin decoders
2. Test all endpoints
3. Verify UI rendering
4. Test edge cases (null fields, empty arrays)

#### Manual QA Checklist

- [ ] Fortnight schedule displays correctly
- [ ] All programs accessible by slug
- [ ] Episodes return for each program
- [ ] Playlists show tracks with artists
- [ ] Audio streams redirect correctly
- [ ] Images display (not broken URLs)
- [ ] Dates/times in correct timezone
- [ ] Duration values reasonable
- [ ] No missing required fields

### Comparison Testing

Compare responses with legacy API:

```bash
# Fetch from both APIs
curl https://airnet.org.au/rest/stations/3pbs/guides/fm > old.json
curl https://schedule.pbsfm.org.au/api/fortnight > new.json

# Compare structure (manual review)
diff <(jq -S '.' old.json) <(jq -S '.' new.json)
```

## Rollback Procedure

If critical issues arise:

### Quick Rollback

1. **Revert to Previous Version**
   ```bash
   git revert HEAD
   composer install
   drush cache:rebuild
   ```

2. **Restore Old Configuration**
   - Re-enable Airnet API proxy
   - Restore insomnia-lookup.json if needed
   - Revert routing changes

### Full Rollback

1. **Deploy Previous Release**
   ```bash
   git checkout v1.2.2
   composer install
   drush cache:rebuild
   ```

2. **Restore Database Configuration**
   ```bash
   drush config:import --partial --source=config/v1.2.2
   ```

### Rollback Testing

After rollback:
- [ ] Verify old endpoints work
- [ ] Test mobile apps
- [ ] Monitor error rates
- [ ] Check legacy Airnet API availability

## Known Issues

### Issue: Slug Not Found

**Symptom:** 404 errors for valid programs

**Cause:** Program has no matching `field_api_aod_slug` (or `/program/{slug}` alias when the AOD slug is empty). Backend outages return 5xx, not 404.

**Fix:**
```bash
drush php:eval "\Drupal::service('api_proxy_pbs.slug_resolver')->invalidateProgramCache('problem-slug');"
drush cache:rebuild
```

### Issue: Missing Images

**Symptom:** Null profileImage/bannerImage fields

**Cause:** metatag extraction failure

**Fix:**
- Verify og:image metatag exists in JSON:API response
- Check FieldMap::extractImageFromMetatag() logic
- Ensure program has featured media in Drupal

### Issue: Wrong Duration

**Symptom:** Duration values seem off

**Cause:** Minutes vs seconds conversion

**Fix:**
- Verify `field_date_range.duration` is in minutes (or null, in which case end − start is used)
- Confirm `FieldMap::episodeDurationSeconds()` output
- Check for negative or zero durations

### Issue: Authentication Failure

**Symptom:** 401/403 errors from JSON:API

**Cause:** Invalid credentials

**Fix:**
- Verify credentials in module settings
- Test with curl directly
- Check backend user permissions

## Performance Impact

### Expected Changes

| Metric | Old API | New API | Impact |
|--------|---------|---------|--------|
| Fortnight Schedule | ~50ms | ~150ms | +100ms (first hit) |
| Fortnight Schedule (cached) | ~50ms | ~10ms | -40ms |
| Program Lookup | ~30ms | ~80ms | +50ms (first hit) |
| Program Lookup (cached) | ~30ms | ~5ms | -25ms |
| Playlist | ~40ms | ~100ms | +60ms |

### Optimization

If performance is an issue:

1. **Increase Cache TTLs**
   - Fortnight: 1h → 4h
   - Programs: 24h → 48h

2. **Warm Cache Regularly**
   ```bash
   # Cron job every 6 hours
   drush php:eval "\Drupal::service('api_proxy_pbs.slug_resolver')->buildProgramCache();"
   ```

3. **Add CDN Caching**
   - CloudFlare/Fastly in front of API
   - Respect Cache-Control headers

## Support

### Logging

All errors logged to `api_proxy_pbs` channel:
```bash
drush watchdog:show --filter=api_proxy_pbs
```

### Debug Mode

Enable verbose logging:
```php
// In services.yml (dev only)
parameters:
  api_proxy_pbs.debug: true
```

### Contact

For migration support:
- Review documentation in `/docs`
- Check Drupal logs
- Test with curl examples
- Contact development team

## Appendix

### Environment-Specific URLs

**Development:**
- JSON:API: `https://nginx-php.project-migration.pbsfm.au2.amazee.io`

**Staging:**
- JSON:API: `https://nginx-php.staging.pbsfm.au2.amazee.io`

**Production:**
- JSON:API: `https://cms.pbsfm.org.au` (TBD)

### Field Mapping Reference

See `docs/JSONAPI_INTEGRATION.md` for complete field mappings.

### API Response Samples

Sample responses available in `tests/fixtures/` directory.

