<?php

declare(strict_types=1);

namespace TodayTixCalendar\Engine;

/**
 * One rendered month: a label plus a rectangular grid of weeks.
 *
 * Every week is exactly 7 {@see CalendarDay}s; the grid is padded with adjacent-month
 * filler days so the template can render a clean table without edge-case math.
 */
final class CalendarMonth
{
    /**
     * @param CalendarDay[][] $weeks Rows of 7 days each.
     */
    public function __construct(
        public readonly int $year,
        public readonly int $month,
        public readonly string $label,
        public readonly array $weeks,
        public readonly bool $isDefault = false,
    ) {}

    /** Stable key for this month, e.g. "2026-12" — used as the JS paging id. */
    public function key(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    /** True if any in-month day carries a performance (lets the view skip dead months). */
    public function hasPerformances(): bool
    {
        foreach ($this->weeks as $week) {
            foreach ($week as $day) {
                if ($day->inMonth && $day->hasPerformances()) {
                    return true;
                }
            }
        }

        return false;
    }
}
