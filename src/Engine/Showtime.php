<?php

declare(strict_types=1);

namespace TodayTixCalendar\Engine;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A single resolved performance: when it is, and whether you can buy for it.
 *
 * Immutable value object. `datetime` is always in the show's local timezone
 * (America/New_York for AVFTB) so DST is baked in and `localDate`/`localTime`
 * need no further conversion.
 */
final class Showtime
{
    public function __construct(
        public readonly int $id,
        public readonly DateTimeImmutable $datetime,
        public readonly Availability $availability,
        public readonly ?int $seatsAvailable = null,
        public readonly ?string $priceDisplay = null,
        public readonly ?int $priceValue = null,
    ) {}

    /** Local calendar date, e.g. "2026-12-10" — the calendar's grouping key. */
    public function localDate(): string
    {
        return $this->datetime->format('Y-m-d');
    }

    /** Local wall-clock time, e.g. "19:30". */
    public function localTime(): string
    {
        return $this->datetime->format('H:i');
    }

    /** Buyer-facing time label, e.g. "7:30 PM" (no leading zero). */
    public function timeLabel(): string
    {
        return ltrim($this->datetime->format('g:i A'), '0');
    }

    /**
     * Compact time for tight calendar cells: drops ":00" on the hour so a matinee
     * reads "2 PM" while an evening stays "7:30 PM". Two of these stack in a
     * two-show day without blowing out the cell height on mobile.
     */
    public function timeLabelShort(): string
    {
        $format = $this->datetime->format('i') === '00' ? 'g A' : 'g:i A';

        return $this->datetime->format($format);
    }

    /** A lightweight {id, datetime} reference for the canonical store. */
    public function toRef(): PerformanceRef
    {
        return new PerformanceRef($this->id, $this->datetime);
    }

    /**
     * Flat, serializable form for a transient/option. Deliberately plain scalars so
     * the cache survives class changes (unlike serialized objects).
     *
     * @return array{id:int,datetime:string,state:string,seats:int|null,price:string|null}
     */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'datetime'   => $this->datetime->format(DateTimeImmutable::ATOM),
            'state'      => $this->availability->value,
            'seats'      => $this->seatsAvailable,
            'price'      => $this->priceDisplay,
            'priceValue' => $this->priceValue,
        ];
    }

    /**
     * Rebuild from {@see toArray()}, normalizing into the show timezone. An unknown
     * state slug falls back to SOLD_OUT — the safe direction (never invents supply).
     *
     * @param array{id:int|string,datetime:string,state?:string,seats?:int|null,price?:string|null,priceValue?:int|null} $row
     */
    public static function fromArray(array $row, DateTimeZone $tz): self
    {
        $datetime = (new DateTimeImmutable((string) $row['datetime']))->setTimezone($tz);
        $state    = Availability::tryFrom((string) ($row['state'] ?? '')) ?? Availability::SOLD_OUT;

        return new self(
            (int) $row['id'],
            $datetime,
            $state,
            isset($row['seats']) && $row['seats'] !== null ? (int) $row['seats'] : null,
            isset($row['price']) && $row['price'] !== null ? (string) $row['price'] : null,
            isset($row['priceValue']) && $row['priceValue'] !== null ? (int) $row['priceValue'] : null,
        );
    }
}
