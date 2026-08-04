<?php

declare(strict_types=1);

namespace TodayTixCalendar\Tests\Unit;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TodayTixCalendar\Engine\Availability;
use TodayTixCalendar\Engine\Http\TransportException;
use TodayTixCalendar\Engine\Showtime;
use TodayTixCalendar\Engine\TodayTixClient;
use TodayTixCalendar\Tests\Support\FakeTransport;

final class TodayTixClientTest extends TestCase
{
    private DateTimeZone $et;

    protected function setUp(): void
    {
        $this->et = new DateTimeZone('America/New_York');
    }

    /** @return Showtime[] indexed by id */
    private function parseFixture(): array
    {
        $client = new TodayTixClient(FakeTransport::fromFixture('showtimes.json'), $this->et);
        $out = [];
        foreach ($client->fetchShowtimes(46495) as $showtime) {
            $out[$showtime->id] = $showtime;
        }

        return $out;
    }

    public function testRequestsTheCorrectShowtimesUrl(): void
    {
        $transport = FakeTransport::withBody('{"code":200,"data":[]}');
        $client    = new TodayTixClient($transport, $this->et, 'https://api.todaytix.com/');

        $client->fetchShowtimes(46495);

        self::assertSame('https://api.todaytix.com/api/v2/shows/46495/showtimes', $transport->requestedUrl);
    }

    public function testParsesAllRows(): void
    {
        self::assertCount(5, $this->parseFixture());
    }

    public function testMapsAvailabilityStates(): void
    {
        $byId = $this->parseFixture();

        self::assertSame(Availability::LIMITED, $byId[2511756]->availability, 'LOW level -> limited');
        self::assertSame(Availability::AVAILABLE, $byId[2502984]->availability, 'null level, seats left -> available');
        self::assertSame(Availability::SOLD_OUT, $byId[2502990]->availability, 'zero seats -> sold out');
        self::assertSame(Availability::AVAILABLE, $byId[2502991]->availability);
        self::assertSame(Availability::SOLD_OUT, $byId[2599001]->availability, 'null regularTickets -> sold out');
    }

    public function testDerivesLocalDatetimeInShowTimezone(): void
    {
        $showtime = $this->parseFixture()[2511756];

        self::assertSame('2026-12-10', $showtime->localDate());
        self::assertSame('19:30', $showtime->localTime());
        self::assertSame('7:30 PM', $showtime->timeLabel());
        self::assertSame('America/New_York', $showtime->datetime->getTimezone()->getName());
    }

    public function testCompactTimeDropsMinutesOnTheHour(): void
    {
        $byId = $this->parseFixture();

        self::assertSame('7:30 PM', $byId[2511756]->timeLabelShort(), 'half-hour keeps minutes');
        self::assertSame('2 PM', $byId[2502990]->timeLabelShort(), 'a matinee on the hour drops :00');
    }

    public function testCarriesSeatsAndPrice(): void
    {
        $byId = $this->parseFixture();

        self::assertSame(1, $byId[2511756]->seatsAvailable);
        self::assertSame('$279', $byId[2511756]->priceDisplay);
        self::assertSame(279, $byId[2511756]->priceValue, 'numeric price parsed for "from $X"');
        self::assertNull($byId[2599001]->seatsAvailable, 'null regularTickets -> no seat count');
        self::assertNull($byId[2599001]->priceValue, 'null regularTickets -> no price');
    }

    public function testThrowsOnMalformedJson(): void
    {
        $client = new TodayTixClient(FakeTransport::withBody('not json{'), $this->et);

        $this->expectException(TransportException::class);
        $client->fetchShowtimes(46495);
    }

    public function testThrowsOnNon200ApiCode(): void
    {
        $client = new TodayTixClient(FakeTransport::withBody('{"code":500,"data":[]}'), $this->et);

        $this->expectException(TransportException::class);
        $client->fetchShowtimes(46495);
    }

    public function testPropagatesTransportFailure(): void
    {
        $client = new TodayTixClient(FakeTransport::throwing('timeout'), $this->et);

        $this->expectException(TransportException::class);
        $client->fetchShowtimes(46495);
    }

    public function testSkipsRowsWithoutAnId(): void
    {
        $client = new TodayTixClient(
            FakeTransport::withBody('{"code":200,"data":[{"datetime":"2026-12-10T19:30:00-05:00"},{"id":7,"datetimeEpoch":1796949000}]}'),
            $this->et,
        );

        $showtimes = $client->fetchShowtimes(46495);

        self::assertCount(1, $showtimes);
        self::assertSame(7, $showtimes[0]->id);
    }
}
