<?php

declare(strict_types=1);

namespace TodayTixCalendar\Engine;

/**
 * Builds the white-label checkout deep-link for a performance from a token template.
 *
 * The template is a URL path (appended to the white-label base URL) with tokens
 * substituted per performance:
 *
 *   {show_id}       the TodayTix show id            e.g. 46495
 *   {showtime_id}   the TodayTix showtime id        e.g. 2502992
 *   {month}         the performance month, Y-m      e.g. 2026-12
 *   {date}          the performance date, Y-m-d     e.g. 2026-12-21
 *   {time}          the performance time, H:i       e.g. 19:00
 *
 * ── Per-performance deep link ────────────────────────────────────────────────
 * The DEFAULT template is the month-calendar route (/booking/calendar/{show_id}/{month}),
 * a safe placeholder that never 404s on a stale showtime id. The confirmed
 * per-performance route is the white-label seating-plan step — the feed's showtime
 * id IS the white-label showtime_id (verified: 2026-12-21 19:00 = feed id 2502992) —
 * so switching to it is a CONFIG change (set `buy_path_template` in the theme's
 * `todaytix_calendar/config` filter / CMS field), e.g.:
 *   /booking/seating-plan?product_id={show_id}&content_product_id={show_id}
 *     &venue_id=6555&product_type=show&qt=2&showtime_id={showtime_id}&slot={time}&date={date}
 * (venue_id and qt are per-show literals; slot is the performance time). No package
 * change is needed — the template just references the tokens above.
 */
final class BuyLinkBuilder
{
    /** The confirmed month-calendar route — safe default until the deep link lands. */
    public const MONTH_TEMPLATE = '/booking/calendar/{show_id}/{month}';

    public function __construct(
        private readonly string $baseUrl,
        private readonly int $showId,
        private readonly string $pathTemplate = self::MONTH_TEMPLATE,
    ) {}

    /**
     * Deep-link for a specific performance — a pure URL builder. Whether a given
     * state should be clickable is the CALLER's policy (see CalendarModel's
     * buyableStates), not this builder's: some shows keep sold-out dates clickable
     * because more inventory is coming.
     */
    public function forShowtime(Showtime $showtime): string
    {
        $path = strtr($this->pathTemplate, [
            '{show_id}'     => (string) $this->showId,
            '{showtime_id}' => (string) $showtime->id,
            '{month}'       => $showtime->datetime->format('Y-m'),
            '{date}'        => $showtime->datetime->format('Y-m-d'),
            '{time}'        => $showtime->datetime->format('H:i'),
        ]);

        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }
}
