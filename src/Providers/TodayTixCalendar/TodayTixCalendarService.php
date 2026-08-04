<?php

declare(strict_types=1);

namespace TodayTixCalendar\Providers\TodayTixCalendar;

use DateTimeImmutable;
use DateTimeZone;
use TodayTixCalendar\Engine\Availability;
use TodayTixCalendar\Engine\BuyLinkBuilder;
use TodayTixCalendar\Engine\CalendarModel;
use TodayTixCalendar\Engine\CalendarMonth;
use TodayTixCalendar\Engine\Http\TransportException;
use TodayTixCalendar\Engine\PerformanceRef;
use TodayTixCalendar\Engine\Showtime;
use TodayTixCalendar\Engine\SoldOutResolver;
use TodayTixCalendar\Engine\TodayTixClient;
use TodayTixCalendar\Providers\TodayTixCalendar\Http\WpHttpTransport;

/**
 * The WordPress glue around the engine: config, caching, cron, canonical store,
 * overrides. This is the boundary that keeps the engine pure.
 *
 * Two responsibilities, deliberately separated:
 *   - {@see refresh()}  — WRITES the cache. Talks to TodayTix, grows the canonical
 *                          schedule, resolves sold-out, stores the run. Called by
 *                          WP-Cron (~10 min), never at request time.
 *   - {@see getCalendar()} — READS the cache and assembles the month grids. Never
 *                          blocks on the API; on a cold cache it serves last-known-
 *                          good and lets cron warm things. This is what render.php
 *                          calls.
 *
 * Site config (show id, white-label base URL, timezone, run window) comes through
 * the `todaytix_calendar/config` filter so the package carries no per-show data —
 * the consuming theme supplies it. With no show id configured, everything no-ops.
 */
final class TodayTixCalendarService
{
    /** Rendered-run cache (transient; Redis-backed on prod). */
    private const CACHE_KEY = 'todaytix_calendar_run';

    /** Resilience copy served when the transient is cold or the API is down. */
    private const LAST_GOOD = 'todaytix_calendar_last_good';

    /** Ever-growing schedule of every performance seen — the sold-out baseline. */
    private const CANONICAL = 'todaytix_canonical_schedule';

    /** Manual state overrides {performanceId: stateSlug}, applied last. */
    private const OVERRIDES = 'todaytix_status_overrides';

    public const CRON_HOOK       = 'todaytix_calendar_refresh';
    public const CRON_SCHEDULE   = 'todaytix_calendar_ten_minutes';
    private const CACHE_TTL      = 600; // 10 minutes, Marc-approved.

    public function __construct(
        private readonly WpHttpTransport $transport,
    ) {}

    /* ---------------------------------------------------------------------------
     * Read path — cheap, pure, never hits the network. Called by render.php.
     * ------------------------------------------------------------------------ */

    /**
     * The full set of month grids for the run, ready to render.
     *
     * @return CalendarMonth[] Empty if unconfigured or if there is no data at all.
     */
    public function getCalendar(): array
    {
        $config = $this->config();
        if ($config['show_id'] <= 0) {
            return [];
        }

        $timezone = $this->timezone($config);
        $run      = $this->resolvedRun();

        $model = new CalendarModel(
            $timezone,
            new DateTimeImmutable('now', $timezone),
            new BuyLinkBuilder((string) $config['base_url'], (int) $config['show_id'], (string) $config['buy_path_template']),
            (int) $config['week_starts_on'],
        );

        [$start, $end] = $this->renderRange($config, $run, $timezone);

        return $model->build($run, $start, $end);
    }

    /**
     * The resolved run (cache-backed, overrides applied), sorted chronologically.
     * The building block behind both {@see getCalendar()} and the REST endpoint.
     * Never blocks on the API; warms a cold cache synchronously the first time.
     *
     * @return Showtime[]
     */
    public function resolvedRun(): array
    {
        $config = $this->config();
        if ($config['show_id'] <= 0) {
            return [];
        }

        $timezone = $this->timezone($config);
        $run      = $this->cachedRun($timezone);

        if ($run === null) {
            // Truly cold (no transient, no last-good): warm it once, synchronously.
            $run = $this->refresh() ?? [];
        }

        return $this->applyOverrides($run);
    }

    /**
     * The lowest price across still-buyable performances, formatted for display
     * (e.g. "$89"), for a run-level "Tickets from …" line. Null if nothing is on
     * sale or the feed carried no prices.
     */
    public function getPriceFrom(): ?string
    {
        $lowest = null;
        foreach ($this->resolvedRun() as $showtime) {
            if (!$showtime->availability->isBuyable() || $showtime->priceValue === null) {
                continue;
            }
            if ($lowest === null || $showtime->priceValue < $lowest->priceValue) {
                $lowest = $showtime;
            }
        }

        if ($lowest === null) {
            return null;
        }

        return $lowest->priceDisplay ?? ('$' . $lowest->priceValue);
    }

    /* ---------------------------------------------------------------------------
     * Write path — the only place that talks to TodayTix. Called by WP-Cron.
     * ------------------------------------------------------------------------ */

    /**
     * Fetch the live feed, grow the canonical schedule, resolve sold-out, and cache
     * the resulting run. On any API failure the caches are left untouched so the last
     * good data keeps serving.
     *
     * @return Showtime[]|null The resolved run, or null if the refresh failed.
     */
    public function refresh(): ?array
    {
        $config = $this->config();
        if ($config['show_id'] <= 0) {
            return null;
        }

        $timezone = $this->timezone($config);
        $client   = new TodayTixClient($this->transport, $timezone);

        try {
            $feed = $client->fetchShowtimes((int) $config['show_id']);
        } catch (TransportException $e) {
            return null; // Keep last-known-good; never blank the calendar on an outage.
        }

        $canonical = SoldOutResolver::mergeCanonical($this->canonical($timezone), $feed);
        $this->storeCanonical($canonical);

        $run        = (new SoldOutResolver())->resolve($canonical, $feed);
        $serialized = array_map(static fn (Showtime $s): array => $s->toArray(), $run);

        set_transient(self::CACHE_KEY, $serialized, self::CACHE_TTL);
        update_option(self::LAST_GOOD, $serialized, false);

        return $run;
    }

    /* ---------------------------------------------------------------------------
     * Cron wiring — the provider calls these on activation / hook fire.
     * ------------------------------------------------------------------------ */

    /** Ensure the recurring refresh event exists. Idempotent. */
    public function scheduleCron(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK);
        }
    }

    /** Tear down the recurring event (theme switch / deactivate). */
    public function unscheduleCron(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp !== false) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    /**
     * Register a 10-minute cron interval. Hooked on `cron_schedules`.
     *
     * @param array<string,array{interval:int,display:string}> $schedules
     *
     * @return array<string,array{interval:int,display:string}>
     */
    public function registerCronInterval(array $schedules): array
    {
        $schedules[self::CRON_SCHEDULE] = [
            'interval' => self::CACHE_TTL,
            'display'  => __('Every 10 minutes (TodayTix calendar)', 'todaytix-calendar'),
        ];

        return $schedules;
    }

    /* ---------------------------------------------------------------------------
     * Internals.
     * ------------------------------------------------------------------------ */

    /**
     * Resolved run from the transient, falling back to the persistent last-good copy.
     * Returns null only when neither exists (a genuinely cold cache).
     *
     * @return Showtime[]|null
     */
    private function cachedRun(DateTimeZone $timezone): ?array
    {
        $data = get_transient(self::CACHE_KEY);

        if ($data === false) {
            $data = get_option(self::LAST_GOOD, null);
            $this->ensureRefreshScheduled(); // warm the transient out-of-band
        }

        if (!is_array($data)) {
            return null;
        }

        return array_map(static fn (array $row): Showtime => Showtime::fromArray($row, $timezone), $data);
    }

    /** Apply manual overrides last, so a human can force a state the feed disagrees with. */
    private function applyOverrides(array $run): array
    {
        $overrides = get_option(self::OVERRIDES, []);
        if (!is_array($overrides) || $overrides === []) {
            return $run;
        }

        return array_map(static function (Showtime $showtime) use ($overrides): Showtime {
            $slug  = $overrides[$showtime->id] ?? null;
            $state = is_string($slug) ? Availability::tryFrom($slug) : null;

            return $state === null
                ? $showtime
                : new Showtime($showtime->id, $showtime->datetime, $state, $showtime->seatsAvailable, $showtime->priceDisplay);
        }, $run);
    }

    /**
     * The persisted canonical schedule as engine refs.
     *
     * @return PerformanceRef[]
     */
    private function canonical(DateTimeZone $timezone): array
    {
        $stored = get_option(self::CANONICAL, []);
        if (!is_array($stored)) {
            return [];
        }

        $refs = [];
        foreach ($stored as $row) {
            if (is_array($row) && isset($row['id'], $row['datetime'])) {
                $refs[] = PerformanceRef::fromArray($row, $timezone);
            }
        }

        return $refs;
    }

    /** @param PerformanceRef[] $canonical */
    private function storeCanonical(array $canonical): void
    {
        update_option(
            self::CANONICAL,
            array_map(static fn (PerformanceRef $ref): array => $ref->toArray(), $canonical),
            false,
        );
    }

    private function ensureRefreshScheduled(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time(), self::CRON_HOOK);
        }
    }

    /**
     * The month window to render. Prefers explicit run_start/run_end from config
     * (authoritative, so empty early months still render); otherwise derives the span
     * from the data; otherwise falls back to the current month.
     *
     * @return array{0:DateTimeImmutable,1:DateTimeImmutable}
     */
    private function renderRange(array $config, array $run, DateTimeZone $timezone): array
    {
        $start = $this->parseDate((string) $config['run_start'], $timezone);
        $end   = $this->parseDate((string) $config['run_end'], $timezone);

        if ($start === null || $end === null) {
            $dates = array_map(static fn (Showtime $s): DateTimeImmutable => $s->datetime, $run);
            if ($dates !== []) {
                $start ??= min($dates);
                $end   ??= max($dates);
            }
        }

        $now = new DateTimeImmutable('now', $timezone);

        return [$start ?? $now, $end ?? $now];
    }

    private function parseDate(string $value, DateTimeZone $timezone): ?DateTimeImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, $timezone);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function timezone(array $config): DateTimeZone
    {
        try {
            return new DateTimeZone((string) $config['timezone']);
        } catch (\Exception $e) {
            return new DateTimeZone('America/New_York');
        }
    }

    /**
     * Site configuration. The package ships inert defaults; the consuming theme
     * supplies real values via the filter (see the AVFTB Theme bootstrap).
     *
     * @return array{show_id:int,base_url:string,timezone:string,run_start:string,run_end:string,week_starts_on:int,buy_path_template:string}
     */
    private function config(): array
    {
        $defaults = [
            'show_id'           => 0,
            'base_url'          => '',
            'timezone'          => 'America/New_York',
            'run_start'         => '',
            'run_end'           => '',
            'week_starts_on'    => 0,
            // Per-performance deep link lands here later (see BuyLinkBuilder); the
            // month route is the safe placeholder until the exact route is confirmed.
            'buy_path_template' => BuyLinkBuilder::MONTH_TEMPLATE,
        ];

        $config = apply_filters('todaytix_calendar/config', $defaults);

        return array_merge($defaults, is_array($config) ? $config : []);
    }
}
