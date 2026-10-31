# Follow-up after PBS-260 merges

Backend PR: https://github.com/mediainsights/pbs/pull/363 (Jira PBS-260).

Until it is deployed, this module treats `/api/v1/schedule` as a weekly template, so
fortnightly and monthly programs appear every week. The backend change adds:

- `GET /api/v1/schedule?filter[date]=YYYY-MM-DD`, or `filter[from]` with `filter[to]`
  (inclusive, at most 31 days). Only slots airing in that range are returned, each with an
  `occurrences` list of `Y-m-d` start dates. Invalid filters return a JSON:API 400.
- `rrule` is now the stored string (`FREQ=MONTHLY;INTERVAL=1;BYDAY=FR;BYSETPOS=2`), so slot
  IDs change. The unfiltered response is otherwise unchanged.

## Changes to make here once it is deployed to the dev backend

1. `JsonApiClient::getSchedule()` takes an optional filter (`date`, or `from` and `to`) and
   builds `filter[...]` query args.
2. `/api/fortnight` (`ScheduleController`, `ScheduleTransformer`): request the 14 days
   covered by the day labels (Monday of the labelled week to Sunday of the next, within the
   31-day limit). Emit an entry for a slot only for the dates in its `occurrences`, instead
   of expanding every template row onto both weeks.
3. `/api/schedule/{date}` (`ProgramLookup::programScheduled()`): request
   `filter[date]` for the queried Melbourne date and match the returned rows by
   `start_time` / `end_time`. Cache per date (the all-slots cache and `reduceSlots()` go).
   Keep the same `api_proxy_pbs:lookup` tag so `clear` still works.
4. Do not parse `rrule`, `interval` or `start_date` in this module.
5. Fixtures and tests:
   - capture real filtered responses for a fortnightly and a monthly program from dev and
     add them under `tests/fixtures/jsonapi/`;
   - make `FakeJsonApiBackendTrait::getSchedule` honour the filter;
   - add the 02:00-06:00 rotation timestamps that `legacy_snapshot/schedule_lookup.json`
     currently leaves out;
   - add a fortnightly and a monthly case to the transformer and lookup tests.
6. Docs: replace the "Known limitation" paragraph in `JSONAPI_INTEGRATION.md` and the
   matching bullet in `MIGRATION.md`.

## Decisions and checks

- Deploy order: backend first. An older backend does not know `filter[...]` and may reject it,
  so confirm its behaviour before switching, or fall back to the unfiltered call.
- `/api/showname/{date}` stays on the episode query. It already uses the same data as the
  backend's `/api/timeline/{date}/program`, which also differs in input (12 digits, no
  seconds) and error shape (HTML 404), so forwarding would not remove code.
- Check that `occurrences` dates are slot-timezone start dates (a slot past midnight belongs to
  the day it starts), matching the day labels used by `/api/fortnight`.
- Reviewer notes on the backend PR (weekly rules with no `BYDAY` vanish from the schedule;
  existing unsupported rules fail validation on edit) affect which rows we receive; recheck
  them against dev once it ships.
