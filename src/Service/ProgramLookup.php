<?php

namespace Drupal\api_proxy_pbs\Service;

use Drupal\api_proxy_pbs\Mapping\FieldMap;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;

/**
 * Answers "which program is on air at this instant?" from JSON:API.
 *
 * Replaces the pbs_airnet_api module, which re-fetched this module's own
 * legacy HTTP endpoints (one request per program) to answer the same
 * questions.
 */
class ProgramLookup
{
    /**
     * Tag on every lookup response and on the cached schedule slots.
     */
    const CACHE_TAG = 'api_proxy_pbs:lookup';

    const SLOTS_CACHE_ID = 'api_proxy_pbs:schedule_slots';
    const SLOTS_CACHE_TTL = 3600;

    const EPISODE_START_PATH = FieldMap::EPISODE_DATE_RANGE . '.value';
    const EPISODE_END_PATH = FieldMap::EPISODE_DATE_RANGE . '.end_value';

    protected $jsonApiClient;
    protected $cache;
    protected $cacheTagsInvalidator;

    /**
     * Constructor.
     */
    public function __construct(
        JsonApiClient $jsonApiClient,
        CacheBackendInterface $cache,
        CacheTagsInvalidatorInterface $cacheTagsInvalidator
    ) {
        $this->jsonApiClient = $jsonApiClient;
        $this->cache = $cache;
        $this->cacheTagsInvalidator = $cacheTagsInvalidator;
    }

    /**
     * Name of the program whose episode is broadcasting at an instant.
     *
     * An episode is on air from its start (inclusive) to its end
     * (exclusive). When episodes overlap the latest start wins.
     *
     * @return string|null
     *   The program name, or NULL when no published episode is on air.
     *
     * @throws \Exception
     *   When the backend request fails.
     */
    public function programOnAir(\DateTimeInterface $when): ?string
    {
        return $this->onAirProgram($when)['name'] ?? null;
    }

    /**
     * Name and presenters of the program broadcasting at an instant.
     *
     * Same selection rule as programOnAir().
     *
     * @return array{name: string, broadcasters: string}|null
     *   NULL when no published episode is on air or its program is unnamed.
     *
     * @throws \Exception
     *   When the backend request fails.
     */
    public function onAirProgram(\DateTimeInterface $when): ?array
    {
        $timestamp = $when->getTimestamp();
        $response = $this->jsonApiClient->getEpisodes(
            [
                'status' => true,
                'started' => $this->condition(self::EPISODE_START_PATH, '<=', $timestamp),
                'not_ended' => $this->condition(self::EPISODE_END_PATH, '>', $timestamp),
            ],
            ['field_program'],
            ['limit' => 1],
            '-' . self::EPISODE_START_PATH
        );

        $episode = $response['data'][0] ?? null;
        $programId = $episode['relationships']['field_program']['data']['id'] ?? null;
        foreach ($response['included'] ?? [] as $included) {
            if ($included['type'] === 'program' && $included['id'] === $programId) {
                $program = FieldMap::mapProgramAttributes($included['attributes'] ?? []);
                if ($program[FieldMap::PROGRAM_NAME] === '') {
                    return null;
                }

                return [
                    FieldMap::PROGRAM_NAME => $program[FieldMap::PROGRAM_NAME],
                    FieldMap::PROGRAM_BROADCASTERS => $program[FieldMap::PROGRAM_BROADCASTERS],
                ];
            }
        }

        return null;
    }

    /**
     * Name of the program in the weekly schedule grid at an instant.
     *
     * The backend schedule is a set of undated weekday templates, so the
     * answer depends only on the weekday and wall-clock time in the slot's
     * timezone. Comparing wall-clock minutes keeps programs on the hour
     * across daylight saving changes. When slots overlap the earliest start
     * wins.
     *
     * @return string|null
     *   The program name, or NULL when nothing is scheduled.
     *
     * @throws \Exception
     *   When the backend request fails.
     */
    public function programScheduled(\DateTimeInterface $when): ?string
    {
        return self::findScheduledProgram($this->getSlots(), $when);
    }

    /**
     * Drop cached slots and every cached lookup response.
     */
    public function clear(): void
    {
        $this->cacheTagsInvalidator->invalidateTags([self::CACHE_TAG]);
    }

    /**
     * Find the program of the first slot containing the instant.
     *
     * @param array $slots
     *   Output of reduceSlots(), ordered by start.
     */
    public static function findScheduledProgram(array $slots, \DateTimeInterface $when): ?string
    {
        foreach ($slots as $slot) {
            $local = \DateTimeImmutable::createFromInterface($when)
                ->setTimezone(new \DateTimeZone($slot['timezone']));
            if ((int) $local->format('N') !== $slot['weekday']) {
                continue;
            }

            $minutes = (int) $local->format('G') * 60 + (int) $local->format('i');
            if ($minutes >= $slot['start'] && $minutes < $slot['end']) {
                return $slot['name'];
            }
        }

        return null;
    }

    /**
     * Reduce a JSON:API schedule response to the fields lookups need.
     *
     * Slots that cannot be placed on the grid (unknown weekday, missing
     * times or timezone) are dropped, as ScheduleTransformer does.
     *
     * @return list<array{weekday: int, start: int, end: int, timezone: string, name: string}>
     *   Ordered by start time.
     */
    public static function reduceSlots(array $jsonApiResponse): array
    {
        $slots = [];
        foreach ($jsonApiResponse['data'] ?? [] as $slot) {
            $attributes = $slot['attributes'] ?? [];
            if (($slot['type'] ?? '') !== 'schedule_slot'
                || !isset($attributes['day'], $attributes['start_time'], $attributes['end_time'])) {
                continue;
            }

            $weekday = FieldMap::weekdayCodeToIsoDay((string) $attributes['day']);
            $timezone = $attributes['timezone'] ?? 'Australia/Melbourne';
            if ($weekday === null || !in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
                continue;
            }

            $slots[] = [
                'weekday' => $weekday,
                'start' => (int) $attributes['start_time'],
                'end' => (int) $attributes['end_time'],
                'timezone' => $timezone,
                'name' => $attributes['program_title'] ?? '',
            ];
        }

        usort($slots, fn ($a, $b) => $a['start'] <=> $b['start']);

        return $slots;
    }

    /**
     * Schedule slots, cached so per-instant URLs don't each hit the backend.
     */
    protected function getSlots(): array
    {
        if ($cached = $this->cache->get(self::SLOTS_CACHE_ID)) {
            return $cached->data;
        }

        $slots = self::reduceSlots($this->jsonApiClient->getSchedule());
        // An empty list means the backend answered with nothing usable; don't
        // pin "nothing scheduled" for the whole TTL.
        if ($slots) {
            $this->cache->set(
                self::SLOTS_CACHE_ID,
                $slots,
                \Drupal::time()->getRequestTime() + self::SLOTS_CACHE_TTL,
                [self::CACHE_TAG]
            );
        }

        return $slots;
    }

    /**
     * JSON:API condition on an episode date_range property (Unix timestamp).
     */
    protected function condition(string $path, string $operator, int $timestamp): array
    {
        return [
            'condition' => [
                'path' => $path,
                'operator' => $operator,
                'value' => $timestamp,
            ],
        ];
    }
}
