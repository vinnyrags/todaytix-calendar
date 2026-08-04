<?php

declare(strict_types=1);

namespace TodayTixCalendar\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TodayTixCalendar\Engine\Availability;

final class AvailabilityTest extends TestCase
{
    /**
     * @dataProvider feedLevels
     */
    public function testFromFeedLevelMapsToBuyerState(?string $level, Availability $expected): void
    {
        self::assertSame($expected, Availability::fromFeedLevel($level));
    }

    /** @return array<string,array{0:?string,1:Availability}> */
    public static function feedLevels(): array
    {
        return [
            'null means no scarcity signal' => [null, Availability::AVAILABLE],
            'empty string'                  => ['', Availability::AVAILABLE],
            'HIGH is plentiful'             => ['HIGH', Availability::AVAILABLE],
            'LOW is limited'                => ['LOW', Availability::LIMITED],
            'lowercase is normalized'       => ['low', Availability::LIMITED],
            'unknown level is conservative' => ['MYSTERY', Availability::LIMITED],
        ];
    }

    public function testSoldOutIsNeverDerivedFromLevel(): void
    {
        // Sold-out only comes from the resolver / seats-exhausted edge, never a level.
        self::assertNotSame(Availability::SOLD_OUT, Availability::fromFeedLevel('LOW'));
    }

    public function testLabels(): void
    {
        self::assertSame('Available', Availability::AVAILABLE->label());
        self::assertSame('Limited', Availability::LIMITED->label());
        self::assertSame('Sold Out', Availability::SOLD_OUT->label());
    }

    public function testIsBuyable(): void
    {
        self::assertTrue(Availability::AVAILABLE->isBuyable());
        self::assertTrue(Availability::LIMITED->isBuyable());
        self::assertFalse(Availability::SOLD_OUT->isBuyable());
    }
}
