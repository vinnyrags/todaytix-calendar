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

    /* ---- seedSoldOut() ---- */

    public function testSeedSlotAbsentFromFeedResolvesSoldOut(): void
    {
        // A pre-polling preview: known to exist, never in the feed, no captured id.
        $seed   = [$this->dt('2026-11-27 19:30')];
        $seeded = SoldOutResolver::seedSoldOut([], [], $seed);

        self::assertCount(1, $seeded);
        self::assertLessThan(0, $seeded[0]->id, 'synthetic id must be negative (no TT collision)');

        // …and it reads as SOLD_OUT once resolved against a feed that lacks it.
        $resolved = (new SoldOutResolver())->resolve($seeded, []);
        self::assertCount(1, $resolved);
        self::assertSame(Availability::SOLD_OUT, $resolved[0]->availability);
    }

    public function testSeedSlotAlreadyInFeedIsNotDuplicated(): void
    {
        // The feed owns this slot (a real id) — the seed must defer to it.
        $feed   = [$this->showtime(555, '2026-12-15 19:00', Availability::AVAILABLE)];
        $seed   = [$this->dt('2026-12-15 19:00')];
        $seeded = SoldOutResolver::seedSoldOut([], $feed, $seed);

        self::assertSame([], $seeded, 'no synthetic ref when the feed already covers the slot');
    }

    public function testSeedSlotAlreadyInRealCanonicalIsNotDuplicated(): void
    {
        $canonical = [new PerformanceRef(42, $this->dt('2026-12-12 19:30'))];
        $seed      = [$this->dt('2026-12-12 19:30')];
        $seeded    = SoldOutResolver::seedSoldOut($canonical, [], $seed);

        self::assertCount(1, $seeded);
        self::assertSame(42, $seeded[0]->id, 'the real canonical ref is kept, not a synthetic one');
    }

    public function testSeedIsSelfHealingWhenSlotReappearsInFeed(): void
    {
        // First pass seeds a sold-out slot…
        $first = SoldOutResolver::seedSoldOut([], [], [$this->dt('2026-12-13 17:00')]);
        self::assertCount(1, $first);
        self::assertLessThan(0, $first[0]->id);

        // …then TodayTix re-lists it. Next pass must drop the synthetic and let the
        // live feed entry win — no phantom sold-out at the same slot.
        $feed   = [$this->showtime(700, '2026-12-13 17:00', Availability::AVAILABLE)];
        $second = SoldOutResolver::seedSoldOut($first, $feed, [$this->dt('2026-12-13 17:00')]);

        self::assertSame([], $second, 'stale synthetic seed is stripped when the feed carries the slot');
    }

    public function testMatineeAndEveningSameDayAreDistinctSeeds(): void
    {
        $seed = [$this->dt('2026-12-12 14:00'), $this->dt('2026-12-12 19:30')];

        $seeded = SoldOutResolver::seedSoldOut([], [], $seed);

        self::assertCount(2, $seeded, 'a matinee and an evening on the same date are separate slots');
    }

    public function testSeededCanonicalIsSortedChronologically(): void
    {
        $seed = [$this->dt('2026-12-13 17:00'), $this->dt('2026-11-27 19:30')];

        $seeded = SoldOutResolver::seedSoldOut([], [], $seed);
        $dates  = array_map(static fn (PerformanceRef $r): string => $r->datetime->format('Y-m-d'), $seeded);

        self::assertSame(['2026-11-27', '2026-12-13'], $dates);
    }
}
