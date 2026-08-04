<?php

declare(strict_types=1);

namespace TodayTixCalendar\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TodayTixCalendar\Engine\Availability;
use TodayTixCalendar\Engine\PerformanceRef;
use TodayTixCalendar\Engine\Showtime;
use TodayTixCalendar\Engine\SoldOutResolver;

final class SoldOutResolverTest extends TestCase
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

    public function testFeedPerformancesKeepTheirLiveAvailability(): void
    {
        $canonical = [new PerformanceRef(1, $this->dt('2026-12-10 19:30'))];
        $feed      = [$this->showtime(1, '2026-12-10 19:30', Availability::LIMITED)];

        $resolved = (new SoldOutResolver())->resolve($canonical, $feed);

        self::assertCount(1, $resolved);
        self::assertSame(Availability::LIMITED, $resolved[0]->availability);
    }

    public function testCanonicalPerformanceMissingFromFeedIsSoldOut(): void
    {
        $canonical = [
            new PerformanceRef(1, $this->dt('2026-12-10 19:30')),
            new PerformanceRef(2, $this->dt('2026-12-11 19:30')),
        ];
        // #2 has dropped out of the feed -> sold out.
        $feed = [$this->showtime(1, '2026-12-10 19:30', Availability::AVAILABLE)];

        $resolved = (new SoldOutResolver())->resolve($canonical, $feed);
        $byId     = [];
        foreach ($resolved as $s) {
            $byId[$s->id] = $s;
        }

        self::assertSame(Availability::AVAILABLE, $byId[1]->availability);
        self::assertSame(Availability::SOLD_OUT, $byId[2]->availability);
    }

    public function testNewFeedPerformanceNotYetInCanonicalIsIncluded(): void
    {
        $canonical = [];
        $feed      = [$this->showtime(9, '2026-12-20 19:30', Availability::AVAILABLE)];

        $resolved = (new SoldOutResolver())->resolve($canonical, $feed);

        self::assertCount(1, $resolved);
        self::assertSame(9, $resolved[0]->id);
    }

    public function testResolvedRunIsSortedChronologically(): void
    {
        $canonical = [
            new PerformanceRef(2, $this->dt('2026-12-20 19:30')),
            new PerformanceRef(1, $this->dt('2026-12-10 19:30')),
        ];
        $feed = [$this->showtime(3, '2026-12-05 19:30', Availability::AVAILABLE)];

        $resolved = (new SoldOutResolver())->resolve($canonical, $feed);
        $ids      = array_map(static fn (Showtime $s): int => $s->id, $resolved);

        self::assertSame([3, 1, 2], $ids);
    }

    public function testMergeCanonicalUnionsAndDedupesById(): void
    {
        $canonical = [new PerformanceRef(1, $this->dt('2026-12-10 19:30'))];
        $feed      = [
            $this->showtime(1, '2026-12-10 19:30', Availability::AVAILABLE), // already known
            $this->showtime(2, '2026-12-11 19:30', Availability::AVAILABLE), // new
        ];

        $grown = SoldOutResolver::mergeCanonical($canonical, $feed);

        self::assertCount(2, $grown);
        self::assertContainsOnlyInstancesOf(PerformanceRef::class, $grown);
        self::assertSame([1, 2], array_map(static fn (PerformanceRef $r): int => $r->id, $grown));
    }

    public function testMergeCanonicalNeverForgetsADroppedPerformance(): void
    {
        $canonical = [
            new PerformanceRef(1, $this->dt('2026-12-10 19:30')),
            new PerformanceRef(2, $this->dt('2026-12-11 19:30')),
        ];
        // #2 gone from feed — merge must retain it so it can read as sold out.
        $feed = [$this->showtime(1, '2026-12-10 19:30', Availability::AVAILABLE)];

        $grown = SoldOutResolver::mergeCanonical($canonical, $feed);

        self::assertCount(2, $grown);
    }
}
