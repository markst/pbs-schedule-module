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
- `day`: Day number 1-14 in fortnight
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

Returns a list of all active programs.

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

---

### GET /api/programs/{slug}/episodes

Returns episodes for a specific program.

**Parameters:**
- `slug`: Program slug

**Query Parameters:**
- `date`: Filter by date (optional)
- `numBefore`: Limit number of results (optional)

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
- `date`: Episode date (format: YYYY-MM-DD HH:MM:SS or YYYY-MM-DD+HH%3AMM%3ASS)

**Response:** Single episode object (same structure as episodes list)

**Cache:** 1 hour

**Error Responses:**
- `404`: Episode not found

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
- `404`: Playlist not found

---

### GET /api/programs/{slug}/episodes/{date}/stream

Redirects to the audio stream URL for an episode.

**Parameters:**
- `slug`: Program slug
- `date`: Episode date

**Response:** HTTP 302 redirect to audio URL

**Error Responses:**
- `404`: Audio not available

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
- `404`: Resource not found
- `500`: Internal server error

## Notes

- All times are in Australian Eastern Time (AEDT/AEST)
- Duration is always in seconds
- Dates in URLs should be URL-encoded (colon = %3A, space = +)
- Images may be null if not available
- Content descriptors may contain null values

