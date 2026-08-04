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
    public function __construct(
        private readonly DateTimeZone $timezone,
        private readonly DateTimeImmutable $now,
        private readonly BuyLinkBuilder $buyLinks,
        private readonly int $weekStartsOn = 0,
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

        $keys           = array_map(static fn (DateTimeImmutable $d): string => $d->format('Y-m'), $monthStarts);
        $monthsWithPerf = [];
        foreach (array_keys($byDate) as $date) {
            $monthsWithPerf[substr((string) $date, 0, 7)] = true;
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
        $defaultKey = $this->resolveDefaultMonth($keys, $monthsWithPerf, $today);
        $endKey     = max($perfKeys);

        $months = [];
        foreach ($monthStarts as $firstOfMonth) {
            $key = $firstOfMonth->format('Y-m');
            if ($key < $defaultKey || $key > $endKey) {
                continue;
            }
            $months[] = $this->buildMonth($firstOfMonth, $byDate, $today, $key === $defaultKey);
        }

        return $months;
    }

    /**
     * The first navigable month: the first month that is not in the past AND has
     * performances; failing that, the first month with performances at all. Callers
     * guarantee at least one month has performances, so this always resolves to a
     * real performance month — which becomes both the opening month and the floor
     * for backward paging.
     *
     * @param string[]              $keys           Month keys (Y-m), in order.
     * @param array<string,bool>    $monthsWithPerf Keys that hold performances.
     */
    private function resolveDefaultMonth(array $keys, array $monthsWithPerf, DateTimeImmutable $today): string
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
     * @param array<string,Showtime[]> $byDate
     */
    private function buildMonth(DateTimeImmutable $firstOfMonth, array $byDate, DateTimeImmutable $today, bool $isDefault): CalendarMonth
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

            $cells[] = [
                'id'          => $showtime->id,
                'time'        => $showtime->timeLabelShort(),
                'state_slug'  => $showtime->availability->value,
                'state_label' => $showtime->availability->label(),
                'price'       => $showtime->priceDisplay,
                'buy_url'     => $isPast ? null : $this->buyLinks->forShowtime($showtime),
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
