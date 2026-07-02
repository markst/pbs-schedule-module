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
Audio URLs are now fetched directly from `field_audio_url` in episodes.

**Action Required:** Verify audio URLs work for all episodes

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
**New:** Always `minutes × 60` from JSON:API

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

**Cause:** Slug cache miss or path.alias mismatch

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
- Verify `field_date_range.duration` is in minutes
- Confirm FieldMap multiplies by 60
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

