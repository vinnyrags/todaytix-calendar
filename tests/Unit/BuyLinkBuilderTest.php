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

    public function testSoldOutHasNoBuyLink(): void
    {
        $builder = new BuyLinkBuilder('https://tickets.viewfromthebridgeplay.com', 46495);

        self::assertNull($builder->forShowtime($this->showtime(Availability::SOLD_OUT)));
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
        $builder = new BuyLinkBuilder('https://x.test', 46495, '/{show_id}/{date}/{month}/{showtime_id}');

        self::assertSame(
            'https://x.test/46495/2026-12-10/2026-12/2511756',
            $builder->forShowtime($this->showtime(Availability::LIMITED)),
        );
    }
}
