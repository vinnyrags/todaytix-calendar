<?php

declare(strict_types=1);

namespace TodayTixCalendar\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TodayTixCalendar\Engine\Availability;
use TodayTixCalendar\Engine\BuyLinkBuilder;
use TodayTixCalendar\Engine\Showtime;

final class BuyLinkBuilderTest extends TestCase
{
    private function showtime(Availability $a): Showtime
    {
        return new Showtime(
            2511756,
            new DateTimeImmutable('2026-12-10 19:30', new DateTimeZone('America/New_York')),
            $a,
        );
    }

    public function testBuildsMonthCalendarDeepLink(): void
    {
        $builder = new BuyLinkBuilder('https://tickets.viewfromthebridgeplay.com', 46495);

        self::assertSame(
            'https://tickets.viewfromthebridgeplay.com/booking/calendar/46495/2026-12',
            $builder->forShowtime($this->showtime(Availability::AVAILABLE)),
        );
    }

    public function testTrimsTrailingSlashOnBaseUrl(): void
    {
        $builder = new BuyLinkBuilder('https://tickets.viewfromthebridgeplay.com/', 46495);

        self::assertStringNotContainsString('.com//booking', (string) $builder->forShowtime($this->showtime(Availability::LIMITED)));
    }

    public function testBuildsAUrlRegardlessOfState(): void
    {
        // The builder is pure — WHICH states are clickable is the model's policy
        // (buyableStates), not the builder's. Some shows keep sold-out clickable.
        $builder = new BuyLinkBuilder('https://tickets.viewfromthebridgeplay.com', 46495);

        self::assertSame(
            'https://tickets.viewfromthebridgeplay.com/booking/calendar/46495/2026-12',
            $builder->forShowtime($this->showtime(Availability::SOLD_OUT)),
        );
    }

    public function testPerShowtimeTemplateIsAConfigSwap(): void
    {
        // The planned per-performance deep link: no code change, just a new template.
        $builder = new BuyLinkBuilder('https://tickets.viewfromthebridgeplay.com', 46495, '/booking/showtime/{showtime_id}');

        self::assertSame(
            'https://tickets.viewfromthebridgeplay.com/booking/showtime/2511756',
            $builder->forShowtime($this->showtime(Availability::AVAILABLE)),
        );
    }

    public function testTemplateSubstitutesAllTokens(): void
    {
        $builder = new BuyLinkBuilder('https://x.test', 46495, '/{show_id}/{date}/{month}/{showtime_id}/{time}');

        self::assertSame(
            'https://x.test/46495/2026-12-10/2026-12/2511756/19:30',
            $builder->forShowtime($this->showtime(Availability::LIMITED)),
        );
    }

    public function testBuildsTheSeatingPlanDeepLink(): void
    {
        // Marc's confirmed per-performance route (2026-08-07): feed showtime id IS
        // the white-label showtime_id; slot is the performance time.
        $builder = new BuyLinkBuilder(
            'https://tickets.viewfromthebridgeplay.com',
            46495,
            '/booking/seating-plan?product_id={show_id}&content_product_id={show_id}'
                . '&venue_id=6555&product_type=show&qt=2&showtime_id={showtime_id}&slot={time}&date={date}',
        );

        self::assertSame(
            'https://tickets.viewfromthebridgeplay.com/booking/seating-plan?product_id=46495'
                . '&content_product_id=46495&venue_id=6555&product_type=show&qt=2'
                . '&showtime_id=2511756&slot=19:30&date=2026-12-10',
            $builder->forShowtime($this->showtime(Availability::AVAILABLE)),
        );
    }
}
