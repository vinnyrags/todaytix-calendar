<?php

declare(strict_types=1);

namespace TodayTixCalendar\Engine;

use DateTimeImmutable;

/**
 * One cell in the month grid.
 *
 * A day may hold zero, one, or several performances (matinee + evening). `state` is
 * the aggregate used to colour/label the cell; `performances` is the per-showtime
 * detail (time, state, buy link) the template can reveal. Filler cells from adjacent
 * months ({@see $inMonth} false) are rendered muted and never carry performances.
 */
final class CalendarDay
{
    /**
     * @param array<int,array{id:int,time:string,state_slug:string,state_label:string,buy_url:?string}> $performances
     */
    public function __construct(
        public readonly DateTimeImmutable $date,
        public readonly bool $inMonth,
        public readonly bool $isPast,
        public readonly bool $isToday,
        public readonly ?Availability $state,
        public readonly array $performances,
    ) {}

    public function dayNumber(): int
    {
        return (int) $this->date->format('j');
    }

    public function isoDate(): string
    {
        return $this->date->format('Y-m-d');
    }

    public function hasPerformances(): bool
    {
        return $this->performances !== [];
    }

    /** Machine slug for the aggregate state (CSS hook), or null for an empty day. */
    public function stateSlug(): ?string
    {
        return $this->state?->value;
    }

    /** Buyer-facing aggregate label, or null for an empty day. */
    public function stateLabel(): ?string
    {
        return $this->state?->label();
    }

    /** Long human date, e.g. "Thursday, December 10" — formatted in the day's own tz. */
    public function dateLabel(): string
    {
        return $this->date->format('l, F j');
    }

    /** Full accessible label for the cell: date, plus state when there's a performance. */
    public function ariaLabel(): string
    {
        if ($this->state === null) {
            return $this->dateLabel();
        }

        return sprintf('%s — %s', $this->dateLabel(), $this->stateLabel());
    }

    /**
     * A day is actionable if any of its performances carries a buy link. Buyability
     * is the model's policy (see CalendarModel's buyableStates) — a sold-out date can
     * be clickable — so we read the per-performance links rather than the enum.
     */
    public function isInteractive(): bool
    {
        if (!$this->inMonth || $this->isPast) {
            return false;
        }
        foreach ($this->performances as $perf) {
            if (!empty($perf['buy_url'])) {
                return true;
            }
        }

        return false;
    }
}
