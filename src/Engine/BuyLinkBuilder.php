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
 *   {showtime_id}   the TodayTix showtime id        e.g. 2511756
 *   {month}         the performance month, Y-m      e.g. 2026-12
 *   {date}          the performance date, Y-m-d     e.g. 2026-12-10
 *
 * ── Per-performance deep link (planned) ──────────────────────────────────────
 * The DEFAULT template is the CONFIRMED month-calendar route:
 *   /booking/calendar/{show_id}/{month}
 * The white-label booking site is a client-rendered Next.js SPA, so the exact
 * per-showtime route (expected to be something like /booking/showtime/{showtime_id})
 * still needs a quick in-browser confirmation. Because the route is a *template*,
 * switching to the true deep link is a CONFIG change — set `buy_path_template` in
 * the theme's `todaytix_calendar/config` filter (or a CMS field) to e.g.
 *   '/booking/showtime/{showtime_id}'
 * — no package code changes. Until then the month route is the safe placeholder
 * (it never 404s on a stale showtime id).
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
     * Deep-link for a specific performance. Returns null for sold-out performances
     * (nothing to buy), so callers can render state without a dead link.
     */
    public function forShowtime(Showtime $showtime): ?string
    {
        if (!$showtime->availability->isBuyable()) {
            return null;
        }

        $path = strtr($this->pathTemplate, [
            '{show_id}'     => (string) $this->showId,
            '{showtime_id}' => (string) $showtime->id,
            '{month}'       => $showtime->datetime->format('Y-m'),
            '{date}'        => $showtime->datetime->format('Y-m-d'),
        ]);

        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }
}
