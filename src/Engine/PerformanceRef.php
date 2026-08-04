<?php

declare(strict_types=1);

namespace TodayTixCalendar\Engine;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A minimal {id, datetime} pointer to a performance.
 *
 * This is what the canonical schedule is made of: the full, ever-growing set of
 * performances the show has ever offered. It carries no availability — availability
 * is transient and lives on {@see Showtime}; the canonical ref is the durable record
 * that lets {@see SoldOutResolver} notice when a performance has dropped from the
 * live feed (i.e. sold out).
 */
final class PerformanceRef
{
    public function __construct(
        public readonly int $id,
        public readonly DateTimeImmutable $datetime,
    ) {}

    /** @return array{id:int,datetime:string} Persistable form (ISO-8601 with offset). */
    public function toArray(): array
    {
        return [
            'id'       => $this->id,
            'datetime' => $this->datetime->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * Rebuild from a persisted row, normalizing into the show timezone so downstream
     * local-date math is stable regardless of how the offset was stored.
     *
     * @param array{id:int|string,datetime:string} $row
     */
    public static function fromArray(array $row, DateTimeZone $tz): self
    {
        $dt = new DateTimeImmutable((string) $row['datetime']);

        return new self((int) $row['id'], $dt->setTimezone($tz));
    }
}
