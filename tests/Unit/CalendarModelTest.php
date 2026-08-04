<?php

declare(strict_types=1);

namespace TodayTixCalendar\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TodayTixCalendar\Engine\Availability;
use TodayTixCalendar\Engine\BuyLinkBuilder;
use TodayTixCalendar\Engine\CalendarDay;
use TodayTixCalendar\Engine\CalendarModel;
use TodayTixCalendar\Engine\CalendarMonth;
use TodayTixCalendar\Engine\Showtime;

final class CalendarModelTest extends TestCase
{
    private DateTimeZone $et;

    protected function setUp(): void
    {
        $this->et = new DateTimeZone('America/New_York');
    }

    private function dt(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, $this->et);
    }

    private function showtime(int $id, string $iso, Availability $a): Showtime
    {
        return new Showtime($id, $this->dt($iso), $a);
    }

    /** Model with "now" fixed at 2026-12-14 (a mid-run day). */
    private function model(): CalendarModel
    {
        return new CalendarModel(
            $this->et,
            $this->dt('2026-12-14 10:00'),
            new BuyLinkBuilder('https://tickets.viewfromthebridgeplay.com', 46495),
        );
    }

    /** @return Showtime[] */
    private function sampleRun(): array
    {
        return [
            $this->showtime(1, '2026-12-10 19:30', Availability::LIMITED),   // past
            $this->showtime(2, '2026-12-15 19:00', Availability::AVAILABLE), // future available
            $this->showtime(3, '2026-12-16 14:00', Availability::SOLD_OUT),  // matinee sold out
            $this->showtime(4, '2026-12-16 19:30', Availability::AVAILABLE), // evening available
            $this->showtime(5, '2026-12-20 19:30', Availability::SOLD_OUT),  // future sold out
        ];
    }

    private function findDay(CalendarMonth $month, string $iso): CalendarDay
    {
        foreach ($month->weeks as $week) {
            foreach ($week as $day) {
                if ($day->isoDate() === $iso && $day->inMonth) {
                    return $day;
                }
            }
        }

        self::fail("Day {$iso} not found in month {$month->key()}");
    }

    public function testRendersEveryMonthFromFirstToLastWithPerformances(): void
    {
        $run = [
            $this->showtime(1, '2026-12-20 19:30', Availability::AVAILABLE),
            $this->showtime(2, '2027-01-10 19:30', Availability::AVAILABLE),
            $this->showtime(3, '2027-02-15 19:30', Availability::AVAILABLE),
        ];

        $months = $this->model()->build($run, $this->dt('2026-12-01'), $this->dt('2027-02-15'));

        self::assertSame(['2026-12', '2027-01', '2027-02'], array_map(static fn (CalendarMonth $m): string => $m->key(), $months));
        self::assertSame('December 2026', $months[0]->label);
    }

    public function testEmptyLeadingAndTrailingMonthsAreNotNavigable(): void
    {
        // Range is Nov 2026 → Feb 2027, but performances only exist in December.
        // November (empty, pre-sale) and Jan/Feb (empty here) must not be rendered —
        // so there is no "prev" back into an empty November.
        $model  = new CalendarModel($this->et, $this->dt('2026-08-01 10:00'), new BuyLinkBuilder('https://x.test', 1));
        $months = $model->build($this->sampleRun(), $this->dt('2026-11-01'), $this->dt('2027-02-28'));
        $keys   = array_map(static fn (CalendarMonth $m): string => $m->key(), $months);

        self::assertSame(['2026-12'], $keys, 'only months with performances are navigable');
        self::assertNotContains('2026-11', $keys);
    }

    public function testRangeWithNoPerformancesRendersNothing(): void
    {
        // Nothing on sale in range → empty result, so the block shows its
        // "dates appear once on sale" message instead of blank grids.
        $months = $this->model()->build([], $this->dt('2026-12-01'), $this->dt('2027-02-01'));

        self::assertSame([], $months);
    }

    public function testEveryWeekHasSevenDays(): void
    {
        $month = $this->model()->build($this->sampleRun(), $this->dt('2026-12-01'), $this->dt('2026-12-01'))[0];

        foreach ($month->weeks as $week) {
            self::assertCount(7, $week);
        }
    }

    public function testFillerDaysFromAdjacentMonthsAreMarked(): void
    {
        $month = $this->model()->build($this->sampleRun(), $this->dt('2026-12-01'), $this->dt('2026-12-01'))[0];

        // Dec 1 2026 is a Tuesday; with a Sunday-start grid the first two cells are
        // Nov 29 & 30 (filler), and Dec 1 itself is in-month.
        $firstWeek = $month->weeks[0];
        self::assertFalse($firstWeek[0]->inMonth);
        self::assertSame('2026-11-29', $firstWeek[0]->isoDate());
        self::assertTrue($firstWeek[2]->inMonth);
        self::assertSame('2026-12-01', $firstWeek[2]->isoDate());
    }

    public function testAggregateStateIsOptimisticForMultiPerformanceDay(): void
    {
        $month = $this->model()->build($this->sampleRun(), $this->dt('2026-12-01'), $this->dt('2026-12-01'))[0];
        $dec16 = $this->findDay($month, '2026-12-16');

        self::assertSame(Availability::AVAILABLE, $dec16->state, 'available evening wins over sold-out matinee');
        self::assertCount(2, $dec16->performances);
        $slugs = array_column($dec16->performances, 'state_slug');
        self::assertEqualsCanonicalizing(['sold_out', 'available'], $slugs);
    }

    public function testMultiPerformanceDayExposesEachShowtimeWithTimeAndLink(): void
    {
        $month = $this->model()->build($this->sampleRun(), $this->dt('2026-12-01'), $this->dt('2026-12-01'))[0];
        $dec16 = $this->findDay($month, '2026-12-16');

        // Matinee (2 PM, sold out) then evening (7:30 PM, available), in time order.
        [$matinee, $evening] = $dec16->performances;

        self::assertSame('2 PM', $matinee['time']);
        self::assertSame('sold_out', $matinee['state_slug']);
        self::assertNull($matinee['buy_url'], 'the sold-out matinee has no buy link');

        self::assertSame('7:30 PM', $evening['time']);
        self::assertSame('available', $evening['state_slug']);
        self::assertNotNull($evening['buy_url'], 'the available evening is buyable independently');
    }

    public function testFuturePerformanceHasBuyLink(): void
    {
        $month = $this->model()->build($this->sampleRun(), $this->dt('2026-12-01'), $this->dt('2026-12-01'))[0];
        $dec15 = $this->findDay($month, '2026-12-15');

        self::assertTrue($dec15->isInteractive());
        self::assertSame(
            'https://tickets.viewfromthebridgeplay.com/booking/calendar/46495/2026-12',
            $dec15->performances[0]['buy_url'],
        );
    }

    public function testSoldOutFuturePerformanceIsNotInteractiveAndHasNoLink(): void
    {
        $month = $this->model()->build($this->sampleRun(), $this->dt('2026-12-01'), $this->dt('2026-12-01'))[0];
        $dec20 = $this->findDay($month, '2026-12-20');

        self::assertSame(Availability::SOLD_OUT, $dec20->state);
        self::assertFalse($dec20->isInteractive());
        self::assertNull($dec20->performances[0]['buy_url']);
    }

    public function testPastPerformanceIsMarkedAndNotSellable(): void
    {
        $month = $this->model()->build($this->sampleRun(), $this->dt('2026-12-01'), $this->dt('2026-12-01'))[0];
        $dec10 = $this->findDay($month, '2026-12-10');

        self::assertTrue($dec10->isPast);
        self::assertFalse($dec10->isInteractive());
        self::assertNull($dec10->performances[0]['buy_url'], 'past performances never carry a buy link');
    }

    public function testEmptyDayHasNoStateOrPerformances(): void
    {
        $month = $this->model()->build($this->sampleRun(), $this->dt('2026-12-01'), $this->dt('2026-12-01'))[0];
        $dec12 = $this->findDay($month, '2026-12-12');

        self::assertNull($dec12->state);
        self::assertFalse($dec12->hasPerformances());
        self::assertFalse($dec12->isInteractive());
    }

    public function testTodayIsFlagged(): void
    {
        $month = $this->model()->build($this->sampleRun(), $this->dt('2026-12-01'), $this->dt('2026-12-01'))[0];
        $today = $this->findDay($month, '2026-12-14');

        self::assertTrue($today->isToday);
        self::assertFalse($today->isPast);
    }

    private function defaultMonth(array $months): CalendarMonth
    {
        $defaults = array_values(array_filter($months, static fn (CalendarMonth $m): bool => $m->isDefault));
        self::assertCount(1, $defaults, 'exactly one month should be flagged as the default');

        return $defaults[0];
    }

    public function testDefaultMonthSkipsEmptyLeadingMonthsBeforeRun(): void
    {
        // "now" is before the run; November is in range but empty (perfs start in Dec).
        $model  = new CalendarModel($this->et, $this->dt('2026-08-01 10:00'), new BuyLinkBuilder('https://x.test', 1));
        $months = $model->build($this->sampleRun(), $this->dt('2026-11-01'), $this->dt('2027-02-01'));

        self::assertSame('2026-12', $this->defaultMonth($months)->key(), 'opens on the first month with performances, not empty November');
    }

    public function testDefaultMonthIsCurrentMonthOnceRunning(): void
    {
        $run = [
            $this->showtime(1, '2026-12-20 19:30', Availability::AVAILABLE),
            $this->showtime(2, '2027-01-10 19:30', Availability::AVAILABLE),
        ];
        $model  = new CalendarModel($this->et, $this->dt('2027-01-05 10:00'), new BuyLinkBuilder('https://x.test', 1));
        $months = $model->build($run, $this->dt('2026-12-01'), $this->dt('2027-02-01'));

        self::assertSame('2027-01', $this->defaultMonth($months)->key(), 'opens on the current month when it has performances');
    }

    public function testPerformanceCellCarriesPrice(): void
    {
        $run    = [new Showtime(1, $this->dt('2026-12-20 19:30'), Availability::AVAILABLE, 100, '$89', 89)];
        $model  = new CalendarModel($this->et, $this->dt('2026-12-01'), new BuyLinkBuilder('https://x.test', 1));
        $dec20  = $this->findDay($model->build($run, $this->dt('2026-12-01'), $this->dt('2026-12-01'))[0], '2026-12-20');

        self::assertSame('$89', $dec20->performances[0]['price']);
    }
}
