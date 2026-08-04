<?php

declare(strict_types=1);

namespace TodayTixCalendar\Engine;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use TodayTixCalendar\Engine\Http\HttpTransport;
use TodayTixCalendar\Engine\Http\TransportException;

/**
 * Reads the public TodayTix showtimes feed and turns it into {@see Showtime}s.
 *
 * Endpoint: GET https://api.todaytix.com/api/v2/shows/{showId}/showtimes
 * Shape:    { "code": 200, "data": [ { Showtime }, ... ] }
 *
 * Only the fields the calendar needs are read; the rest of each (large) showtime
 * record is ignored. Availability is derived here:
 *   - regularTickets absent, or numAssignedSeatsAvailable <= 0  => SOLD_OUT
 *     (on TodayTix but nothing left to sell — the seats-exhausted edge)
 *   - otherwise                                                 => availabilityLevel
 *     mapped via {@see Availability::fromFeedLevel()}
 * A performance that has left the feed entirely is a different kind of sold-out and
 * is resolved against the canonical schedule by {@see SoldOutResolver}.
 */
final class TodayTixClient
{
    public function __construct(
        private readonly HttpTransport $http,
        private readonly DateTimeZone $timezone,
        private readonly string $baseUrl = 'https://api.todaytix.com',
    ) {}

    /**
     * Fetch and parse the live run for a show.
     *
     * @return Showtime[] in feed order (typically chronological)
     *
     * @throws TransportException on transport failure, malformed JSON, or a non-200
     *                            API `code`. The caller falls back to last-good.
     */
    public function fetchShowtimes(int $showId): array
    {
        $url  = sprintf('%s/api/v2/shows/%d/showtimes', rtrim($this->baseUrl, '/'), $showId);
        $body = $this->http->get($url);

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new TransportException("TodayTix feed for show {$showId} was not valid JSON: {$e->getMessage()}", 0, $e);
        }

        if (!is_array($payload) || (($payload['code'] ?? null) !== 200)) {
            $code = is_array($payload) ? ($payload['code'] ?? 'none') : 'none';
            throw new TransportException("TodayTix feed for show {$showId} returned API code {$code}.");
        }

        $rows = $payload['data'] ?? [];
        if (!is_array($rows)) {
            throw new TransportException("TodayTix feed for show {$showId} had no data array.");
        }

        $showtimes = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }
            $showtimes[] = $this->parseShowtime($row);
        }

        return $showtimes;
    }

    /**
     * @param array<string,mixed> $row A single feed showtime record.
     */
    private function parseShowtime(array $row): Showtime
    {
        $datetime = $this->resolveDatetime($row);

        /** @var array<string,mixed>|null $regular */
        $regular = is_array($row['regularTickets'] ?? null) ? $row['regularTickets'] : null;

        $seats = null;
        if ($regular !== null && isset($regular['numAssignedSeatsAvailable']) && is_numeric($regular['numAssignedSeatsAvailable'])) {
            $seats = (int) $regular['numAssignedSeatsAvailable'];
        }

        if ($regular === null || ($seats !== null && $seats <= 0)) {
            $availability = Availability::SOLD_OUT;
        } else {
            $level        = isset($regular['availabilityLevel']) ? (string) $regular['availabilityLevel'] : null;
            $availability = Availability::fromFeedLevel($level);
        }

        $price      = null;
        $priceValue = null;
        if ($regular !== null && isset($regular['lowPrice']) && is_array($regular['lowPrice'])) {
            $lowPrice = $regular['lowPrice'];
            if (isset($lowPrice['display']) && is_string($lowPrice['display'])) {
                $price = $lowPrice['display'];
            }
            if (isset($lowPrice['value']) && is_numeric($lowPrice['value'])) {
                $priceValue = (int) $lowPrice['value'];
            }
        }

        return new Showtime((int) $row['id'], $datetime, $availability, $seats, $price, $priceValue);
    }

    /**
     * Prefer the epoch (unambiguous UTC instant) and convert into the show timezone,
     * so local date/time and DST are correct without trusting a hand-formatted offset
     * string. Fall back to the ISO `datetime` when no epoch is present.
     *
     * @param array<string,mixed> $row
     */
    private function resolveDatetime(array $row): DateTimeImmutable
    {
        if (isset($row['datetimeEpoch']) && is_numeric($row['datetimeEpoch'])) {
            return (new DateTimeImmutable('@' . (int) $row['datetimeEpoch']))->setTimezone($this->timezone);
        }

        $raw = isset($row['datetime']) ? (string) $row['datetime'] : 'now';

        return (new DateTimeImmutable($raw))->setTimezone($this->timezone);
    }
}
