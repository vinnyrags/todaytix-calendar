<?php

declare(strict_types=1);

namespace TodayTixCalendar\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TodayTixCalendar\Engine\Availability;
use TodayTixCalendar\Engine\OverrideResolver;
use TodayTixCalendar\Engine\Showtime;

final class OverrideResolverTest extends TestCase
{
    private DateTimeZone $et;

    protected function setUp(): void
    {
        $this->et = new DateTimeZone('America/New_York');
    }

    private function showtime(int $id, string $iso, Availability $a = Availability::AVAILABLE): Showtime
    {
        return new Showtime($id, new DateTimeImmutable($iso, $this->et), $a);
    }

    /** A real-id run plus one seeded (synthetic, negative-id) performance. */
    private function sampleRun(): array
    {
        return [
            $this->showtime(100, '2026-12-15 19:00'),
            $this->showtime(101, '2026-12-16 14:00', Availability::LIMITED),
            $this->showtime(-202611271930, '2026-11-27 19:30', Availability::SOLD_OUT),
        ];
    }

    /* ---- normalize() ---- */

    public function testStoresAValidOverrideForAKnownPerformance(): void
    {
        $out = OverrideResolver::normalize([100 => 'limited'], $this->sampleRun());

        self::assertSame([100 => 'limited'], $out);
    }

    public function testAutoMeansNoEntryAtAll(): void
    {
        $out = OverrideResolver::normalize([100 => OverrideResolver::AUTO], $this->sampleRun());

        self::assertSame([], $out, 'AUTO is the absence of an override, not a stored value');
    }

    public function testUntouchedPerformancesAreNeverStored(): void
    {
        // Only 100 is submitted with a value; 101 defers to the feed.
        $out = OverrideResolver::normalize([100 => 'sold_out', 101 => ''], $this->sampleRun());

        self::assertSame([100 => 'sold_out'], $out);
        self::assertArrayNotHasKey(101, $out, 'the map must stay sparse');
    }

    public function testIgnoresPerformancesNotInTheRun(): void
    {
        $out = OverrideResolver::normalize([999 => 'sold_out'], $this->sampleRun());

        self::assertSame([], $out, 'a stale row from an old page load must not persist');
    }

    public function testIgnoresUnknownStateSlugs(): void
    {
        $out = OverrideResolver::normalize([100 => 'not_a_state'], $this->sampleRun());

        self::assertSame([], $out);
    }

    public function testIgnoresNonNumericKeysAndNonStringValues(): void
    {
        $out = OverrideResolver::normalize(['abc' => 'limited', 100 => ['limited']], $this->sampleRun());

        self::assertSame([], $out);
    }

    public function testKeysAreIntsAndSorted(): void
    {
        $out = OverrideResolver::normalize([101 => 'sold_out', 100 => 'limited'], $this->sampleRun());

        self::assertSame([100, 101], array_keys($out));
        foreach (array_keys($out) as $k) {
            self::assertIsInt($k);
        }
    }

    /* ---- the seeded-performance guard ---- */

    public function testSeededPerformanceMayBeForcedSoldOut(): void
    {
        $out = OverrideResolver::normalize([-202611271930 => 'sold_out'], $this->sampleRun());

        self::assertSame([-202611271930 => 'sold_out'], $out);
    }

    public function testSeededPerformanceCannotBePromotedToABuyableState(): void
    {
        // It has no real TodayTix id, so a buy link built from it would be broken.
        foreach (['available', 'limited'] as $slug) {
            $out = OverrideResolver::normalize([-202611271930 => $slug], $this->sampleRun());
            self::assertSame([], $out, "seeded performance must not be promoted to {$slug}");
        }
    }

    public function testIsAllowedForEncodesTheRule(): void
    {
        self::assertTrue(OverrideResolver::isAllowedFor(100, Availability::AVAILABLE));
        self::assertTrue(OverrideResolver::isAllowedFor(100, Availability::SOLD_OUT));
        self::assertTrue(OverrideResolver::isAllowedFor(-1, Availability::SOLD_OUT));
        self::assertFalse(OverrideResolver::isAllowedFor(-1, Availability::AVAILABLE));
        self::assertFalse(OverrideResolver::isAllowedFor(-1, Availability::LIMITED));
    }

    /* ---- choicesFor() ---- */

    public function testChoicesForRealPerformanceOfferEveryState(): void
    {
        $c = OverrideResolver::choicesFor(100);

        self::assertSame(['', 'available', 'limited', 'sold_out'], array_keys($c));
        self::assertSame('Use TodayTix', $c['']);
    }

    public function testChoicesForSeededPerformanceOmitBuyableStates(): void
    {
        $c = OverrideResolver::choicesFor(-202611271930);

        self::assertSame(['', 'sold_out'], array_keys($c));
    }

    public function testChoicesUseSiteLabelsWhenProvided(): void
    {
        $c = OverrideResolver::choicesFor(100, ['available' => 'Best Availability', 'limited' => 'Selling Fast']);

        self::assertSame('Best Availability', $c['available']);
        self::assertSame('Selling Fast', $c['limited']);
        self::assertSame('Sold Out', $c['sold_out'], 'falls back to the enum label when the site sets none');
    }

    /* ---- prune() ---- */

    public function testPruneDropsOrphanedOverrides(): void
    {
        // -202611271930 self-healed into the feed under real id 500, so the old
        // synthetic-keyed override no longer refers to anything in the run.
        $healed = [
            $this->showtime(100, '2026-12-15 19:00'),
            $this->showtime(500, '2026-11-27 19:30'),
        ];

        $out = OverrideResolver::prune([-202611271930 => 'sold_out', 100 => 'limited'], $healed);

        self::assertSame([100 => 'limited'], $out);
    }
}
