<?php

declare(strict_types=1);

namespace TodayTixCalendar\Engine;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Assembles the resolved run into month-by-month grids ready for rendering.
 *
 * Pure and deterministic: given the same performances, "now", and range it always
 * produces the same {@see CalendarMonth}[]. All time math is done in the show
 * timezone so "past" and "today" are correct for the audience, not the server.
 *
 * "now" and the render range are injected so the whole thing is unit-testable
 * without touching the clock or WordPress.
 */
final class CalendarModel
{
    /**
     * @param int $weekStartsOn 0=Sunday … 6=Saturday. US theatre calendars start
     *                          Sunday; override for a Monday-first skin.
     */
    /**
     * @param array<string,string> $stateLabels   Per-state display label (state slug =>
     *                                             label). An empty string means "no
     *                                             marker" (rendered as a plain date).
     *                                             Missing keys fall back to the enum label.
     * @param string[]             $buyableStates Which states get a buy link. Default is
     *                                            available+limited; a site can add
     *                                            'sold_out' to keep sold-out dates
     *                                            clickable (e.g. more inventory coming).
     * @param bool                 $trimLeadingWeeks Drop the opening month's leading
     *                                               weeks that end before the run's
     *                                               first performance, so a run that
     *                                               opens late in a month doesn't
     *                                               render rows of empty dates.
     */
    public function __construct(
        private readonly DateTimeZone $timezone,
        private readonly DateTimeImmutable $now,
        private readonly BuyLinkBuilder $buyLinks,
        private readonly int $weekStartsOn = 0,
        private readonly array $stateLabels = [],
        private readonly array $buyableStates = ['available', 'limited'],
        private readonly bool $showPrice = true,
        private readonly string $timeFormat = '',
        private readonly bool $trimLeadingWeeks = false,
    ) {}

    /**
     * @param Showtime[]        $performances Resolved run (from {@see SoldOutResolver}).
     * @param DateTimeImmutable $rangeStart   Any date within the first month to render.
     * @param DateTimeImmutable $rangeEnd     Any date within the last month to render.
     *
     * @return CalendarMonth[] One entry per calendar month in [rangeStart, rangeEnd].
     */
    public function build(array $performances, DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd): array
    {
        $byDate = [];
        foreach ($performances as $showtime) {
            $byDate[$showtime->localDate()][] = $showtime;
        }
        foreach ($byDate as &$list) {
            usort($list, static fn (Showtime $a, Showtime $b): int => $a->datetime <=> $b->datetime);
        }
        unset($list);

        $today  = $this->startOfDay($this->now);
        $cursor = $this->firstOfMonth($rangeStart);
        $last   = $this->firstOfMonth($rangeEnd);

        $monthStarts = [];
        while ($cursor <= $last) {
            $monthStarts[] = $cursor;
            $cursor = $cursor->modify('first day of next month');
        }
        if ($monthStarts === []) {
            return [];
        }

        $keys              = array_map(static fn (DateTimeImmutable $d): string => $d->format('Y-m'), $monthStarts);
        $monthsWithPerf    = [];
        $monthsWithBuyable = [];
        $todayIso          = $today->format('Y-m-d');
        foreach ($byDate as $date => $list) {
            $monthKey = substr((string) $date, 0, 7);
            $monthsWithPerf[$monthKey] = true;
            // A past date is never sellable (see summarize()), whatever state the feed
            // or an override still reports — so it can't hold the opening month back.
            if ((string) $date < $todayIso) {
                continue;
            }
            foreach ($list as $showtime) {
                if (in_array($showtime->availability->value, $this->buyableStates, true)) {
                    $monthsWithBuyable[$monthKey] = true;
                    break;
                }
            }
        }

        // Only months that actually hold performances anchor the navigable window.
        // With none in range there is nothing to show — the caller renders its
        // "dates appear once on sale" message.
        $perfKeys = array_values(array_filter($keys, static fn (string $k): bool => isset($monthsWithPerf[$k])));
        if ($perfKeys === []) {
            return [];
        }

        // Render only [firstNavigableMonth … lastMonthWithPerformances], so empty
        // leading months (before tickets go on sale, or already past) and empty
        // trailing months are not navigable — you can't page back to an empty
        // November when the run doesn't really begin until December.
        $floorKey   = $this->resolveFloorMonth($keys, $monthsWithPerf, $today);
        $endKey     = max($perfKeys);
        $openingKey = $this->resolveOpeningMonth($keys, $monthsWithBuyable, $floorKey, $endKey);
        $firstDate  = $this->trimLeadingWeeks ? (string) min(array_keys($byDate)) : null;

        $months = [];
        foreach ($monthStarts as $firstOfMonth) {
            $key = $firstOfMonth->format('Y-m');
            if ($key < $floorKey || $key > $endKey) {
                continue;
            }
            $months[] = $this->buildMonth($firstOfMonth, $byDate, $today, $key === $openingKey, $firstDate);
        }

        return $months;
    }

    /**
     * The navigable floor: the first month that is not in the past AND has
     * performances; failing that, the first month with performances at all. Callers
     * guarantee at least one month has performances, so this always resolves to a
     * real performance month. It's the floor for backward paging (the earliest month
     * the prev arrow reaches) — the opening month can sit later (see
     * {@see resolveOpeningMonth()}), but never earlier.
     *
     * @param string[]              $keys           Month keys (Y-m), in order.
     * @param array<string,bool>    $monthsWithPerf Keys that hold performances.
     */
    private function resolveFloorMonth(array $keys, array $monthsWithPerf, DateTimeImmutable $today): string
    {
        $currentKey = $today->format('Y-m');

        foreach ($keys as $key) {
            if ($key >= $currentKey && isset($monthsWithPerf[$key])) {
                return $key;
            }
        }
        foreach ($keys as $key) {
            if (isset($monthsWithPerf[$key])) {
                return $key;
            }
        }

        return $keys[0];
    }

    /**
     * The opening month: the first navigable month (floor…end) that still has a
     * buyable performance, so the calendar lands where tickets can actually be
     * bought and skips a fully sold-out early run — those months stay rendered and
     * reachable via the prev arrow, they just aren't the default view. Self-healing:
     * as each month sells out the opening view advances on its own, with no hardcoded
     * date. Falls back to the floor when the whole run is sold out (nothing buyable).
     *
     * @param string[]           $keys              Month keys (Y-m), in order.
     * @param array<string,bool> $monthsWithBuyable Keys with a buyable performance.
     */
    private function resolveOpeningMonth(array $keys, array $monthsWithBuyable, string $floorKey, string $endKey): string
    {
        foreach ($keys as $key) {
            if ($key < $floorKey || $key > $endKey) {
                continue;
            }
            if (isset($monthsWithBuyable[$key])) {
                return $key;
            }
        }

        return $floorKey;
    }

    /**
     * @param array<string,Showtime[]> $byDate
     * @param ?string                  $trimBefore Y-m-d; leading weeks ending before
     *                                             this date are dropped. Null keeps
     *                                             the full grid.
     */
    private function buildMonth(DateTimeImmutable $firstOfMonth, array $byDate, DateTimeImmutable $today, bool $isDefault, ?string $trimBefore = null): CalendarMonth
    {
        $year  = (int) $firstOfMonth->format('Y');
        $month = (int) $firstOfMonth->format('n');

        $firstWeekday = (int) $firstOfMonth->format('w');           // 0=Sun … 6=Sat
        $leadOffset   = ($firstWeekday - $this->weekStartsOn + 7) % 7;
        $gridStart    = $firstOfMonth->modify("-{$leadOffset} days");
        $daysInMonth  = (int) $firstOfMonth->format('t');
        $totalCells   = (int) (ceil(($leadOffset + $daysInMonth) / 7) * 7);

        $weeks = [];
        $cell  = $gridStart;
        for ($i = 0; $i < $totalCells; $i++) {
            $inMonth = ((int) $cell->format('n') === $month && (int) $cell->format('Y') === $year);
            $iso     = $cell->format('Y-m-d');
            $showtimes = $inMonth ? ($byDate[$iso] ?? []) : [];

            [$state, $performances] = $this->summarize($showtimes, $cell < $today);

            $weeks[intdiv($i, 7)][] = new CalendarDay(
                $cell,
                $inMonth,
                $cell < $today,
                $cell == $today,
                $state,
                $performances,
            );

            $cell = $cell->modify('+1 day');
        }

        // Only leading weeks: once a week reaches the first performance every later
        // week is kept, so the grid stays contiguous.
        if ($trimBefore !== null) {
            while ($weeks !== [] && $weeks[0][6]->isoDate() < $trimBefore) {
                array_shift($weeks);
            }
        }

        return new CalendarMonth($year, $month, $firstOfMonth->format('F Y'), $weeks, $isDefault);
    }

    /**
     * Reduce a day's showtimes to (aggregate state, per-performance cells).
     *
     * Aggregate is optimistic — any buyable performance makes the day buyable — so a
     * day with an available evening and a sold-out matinee reads AVAILABLE, and the
     * per-performance detail still shows the matinee as sold out.
     *
     * @param Showtime[] $showtimes
     *
     * @return array{0:?Availability,1:array<int,array{id:int,time:string,state_slug:string,state_label:string,price:?string,buy_url:?string}>}
     */
    private function summarize(array $showtimes, bool $isPast): array
    {
        if ($showtimes === []) {
            return [null, []];
        }

        $hasAvailable = false;
        $hasLimited   = false;
        $cells        = [];

        foreach ($showtimes as $showtime) {
            $hasAvailable = $hasAvailable || $showtime->availability === Availability::AVAILABLE;
            $hasLimited   = $hasLimited   || $showtime->availability === Availability::LIMITED;

            $slug     = $showtime->availability->value;
            $buyable  = !$isPast && in_array($slug, $this->buyableStates, true);

            $cells[] = [
                'id'          => $showtime->id,
                // Machine date (Y-m-d) alongside the display 'time' — surfaced as a
                // data-attribute so analytics can identify a performance without
                // parsing the human label.
                'date'        => $showtime->datetime->format('Y-m-d'),
                'time'        => $this->timeFormat !== '' ? $showtime->datetime->format($this->timeFormat) : $showtime->timeLabelShort(),
                'state_slug'  => $slug,
                'state_label' => $this->stateLabels[$slug] ?? $showtime->availability->label(),
                'price'       => $this->showPrice ? $showtime->priceDisplay : null,
                'buy_url'     => $buyable ? $this->buyLinks->forShowtime($showtime) : null,
            ];
        }

        $state = match (true) {
            $hasAvailable => Availability::AVAILABLE,
            $hasLimited   => Availability::LIMITED,
            default       => Availability::SOLD_OUT,
        };

        return [$state, $cells];
    }

    private function firstOfMonth(DateTimeImmutable $date): DateTimeImmutable
    {
        return $this->startOfDay($date)->modify('first day of this month');
    }

    private function startOfDay(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setTimezone($this->timezone)->setTime(0, 0, 0);
    }
}
