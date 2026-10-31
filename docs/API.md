# PBS Schedule API Documentation

## Overview

This API provides access to PBS FM radio schedule, program, and episode information in a legacy-compatible format for mobile applications.

**Base URL:** `https://schedule.pbsfm.org.au`

**Response Format:** JSON

**Authentication:** None required for public endpoints

## Endpoints

### GET /api/fortnight

Returns a two-week schedule of all programs on PBS FM.

**Response:**
```json
[
  {
    "guideId": "fm",
    "day": "1",
    "start": "00:00:00",
    "duration": 7200,
    "name": "Program Name",
    "broadcasters": "Presenter Name",
    "gridDescription": "Music genre/style",
    "slug": "program-slug",
    "startTime": "2025-12-01T00:00:00+11:00",
    "profileImage": "https://...",
    "bannerImage": "https://...",
    "programRestUrl": "https://airnet.org.au/rest/stations/3pbs/programs/program-slug",
    "archived": false
  }
]
```

**Fields:**
- `guideId`: Guide identifier (always "fm")
- `day`: Day number 1-14 in fortnight. As in the Airnet-era proxy: in an odd ISO week the current week is days 1-7 and next week 8-14; in an even ISO week the current week is days 8-14 and next week 1-7. Use `startTime` for the actual date.
- `start`: Start time in HH:mm:ss format
- `duration`: Duration in seconds
- `name`: Program name
- `broadcasters`: Comma-separated list of presenters
- `gridDescription`: Short description of genre/style
- `slug`: URL-friendly program identifier
- `startTime`: ISO 8601 formatted start time
- `profileImage`: URL to program image
- `programRestUrl`: URL for program details
- `archived`: Whether program is archived (always false)

**Cache:** 1 hour

---

### GET /api/programs

Returns every published program. The proxy follows the backend's JSON:API `links.next` pagination (50 per page) until exhausted, so the list is not capped at 50.

**Response:**
```json
[
  {
    "name": "Program Name",
    "broadcasters": "Presenter Name",
    "description": "Full HTML description",
    "gridDescription": "Short description",
    "slug": "program-slug",
    "url": "/program/program-slug",
    "profileImageUrl": "https://...",
    "bannerImageUrl": "https://...",
    "twitterHandle": "@username",
    "facebookPage": "https://facebook.com/...",
    "defaultFirstAiredGuide": "fm",
    "episodesRestUrl": "https://airnet.org.au/rest/stations/3pbs/programs/program-slug/episodes"
  }
]
```

**Cache:** 24 hours

---

### GET /api/programs/{slug}

Returns detailed information about a specific program.

**Parameters:**
- `slug`: Program slug (e.g., "lullabies-to-anthems")

**Response:** Single program object (same structure as /api/programs)

**Cache:** 24 hours

**Error Responses:**
- `404`: Program not found
- `500`: Backend unavailable (never reported as 404)

---

### GET /api/programs/{slug}/episodes

Returns a window of episodes around an anchor episode, matching Airnet.

**Parameters:**
- `slug`: Program slug

**Query Parameters:**
- `date` (optional): anchor date, Melbourne local time, in any format accepted by the single-episode route (iOS sends `YYYY-MM-DDTHH:MM:SS`). The anchor is the episode broadcast on that calendar day (the one starting exactly at `date` if several). If no episode aired that day, or the date cannot be parsed, the response is `[]`. Without `date` the anchor is the most recent episode that has already started.
- `numBefore` (optional, default 10, max 50): episodes before the anchor.
- `numAfter` (optional, default 10, max 50): episodes after the anchor.

The response is sorted oldest → newest: `numBefore` episodes, the anchor, then `numAfter` episodes. iOS fetches `?numBefore=11` for the first page and pages back with `?date={oldest start}&numBefore=11&numAfter=0`, dropping the trailing anchor.

The defaults/cap are not observable on Airnet any more (Airnet returned whole windows for small programs); 10/50 bound backend load.

**Response:**
```json
[
  {
    "start": "2025-11-17 02:00:00",
    "end": "2025-11-17 06:00:00",
    "duration": 14400,
    "title": "Episode Title",
    "description": "HTML description",
    "imageUrl": "https://...",
    "smallImageUrl": "https://...",
    "currentEpisode": true,
    "multipleEpsOnDay": false,
    "episodeRestUrl": "https://airnet.org.au/rest/stations/3pbs/programs/program-slug/episodes/2025-11-17+02%3A00%3A00"
  }
]
```

**Cache:** 1 hour

---

### GET /api/programs/{slug}/episodes/{date}

Returns a specific episode.

**Parameters:**
- `slug`: Program slug
- `date`: Episode start, Melbourne local time. Accepted: `YYYY-MM-DD HH:MM:SS`, `YYYY-MM-DD+HH%3AMM%3ASS` (as in `episodeRestUrl`), `YYYY-MM-DDTHH:MM:SS` (optionally with a UTC offset) and `YYYYMMDDHHMM`

**Response:** Single episode object (same structure as episodes list)

**Cache:** 1 hour

**Error Responses:**
- `404`: Episode not found
- `500`: Backend unavailable

---

### GET /api/programs/{slug}/episodes/{date}/playlists

Returns the playlist/tracks for a specific episode.

**Parameters:**
- `slug`: Program slug
- `date`: Episode date

**Response:**
```json
[
  {
    "type": "track",
    "id": "track-uuid",
    "artist": "Artist Name",
    "title": "Track Title",
    "track": "Track Title",
    "release": "Album Name",
    "time": "02:00:00",
    "notes": "Track notes",
    "contentDescriptors": {
      "isAustralian": true,
      "isLocal": null,
      "isFemale": true,
      "isGenderNonConforming": null,
      "isIndigenous": null,
      "isNew": null
    },
    "wikipedia": null,
    "image": "https://...",
    "video": null,
    "url": null,
    "approximateTime": "02:00:00"
  }
]
```

**Cache:** 1 hour

**Error Responses:**
- `404`: Episode not found
- `500`: Backend unavailable

---

### GET /api/programs/{slug}/episodes/{date}/stream

Redirects to the recorded audio for an episode.

**Parameters:**
- `slug`: Program slug
- `date`: Episode start in any accepted episode date format, including the `YYYYMMDDHHMM` timestamp iOS puts in `???EPISODE???`

**Response:** HTTP 302 redirect to `field_api_aod_audio_url` (Omny clip), else `field_api_podcast_audio_url`

**Error Responses:**
- `404`: Episode not found, or it has no audio URL
- `500`: Backend unavailable

iOS builds replay URLs from `browserReplayUrlTemplate` (`/api/channels/fm`),
substituting `???PROGRAM???` with the slug and `???EPISODE???` with the
Melbourne start as `yyyyMMddHHmm`. Pointing that template at this endpoint
(`https://<proxy-host>/api/programs/???PROGRAM???/episodes/???EPISODE???/stream`)
routes playback through the backend's audio fields; see MIGRATION.md.

---

### GET /api/channels/fm

Returns station configuration.

**Response:** Static configuration object

**Cache:** 1 hour

---

## Response Headers

All responses include:
- `Content-Type: application/json; charset=utf-8`
- `Cache-Control: public, max-age={ttl}`
- `Expires`: Appropriate expiry date
- `Proxy-Version`: Module version number

## Error Responses

Error responses follow this format:

```json
{
  "error": "Error message description"
}
```

HTTP status codes:
- `404`: Resource not found (the backend answered and has no match)
- `500`/`502`: Backend request failed

## Notes

- All times are in Australian Eastern Time (AEDT/AEST)
- Duration is always in seconds
- Dates in URLs should be URL-encoded (colon = %3A, space = +)
- Images may be null if not available
- Content descriptors may contain null values

