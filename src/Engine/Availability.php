<?php

declare(strict_types=1);

namespace TodayTixCalendar\Engine;

/**
 * The availability state of a single performance, as surfaced in the calendar.
 *
 * Derived from the TodayTix feed's per-showtime `regularTickets.availabilityLevel`
 * (plus the sold-out inference in {@see SoldOutResolver}). Deliberately coarse —
 * three buyer-facing states, not the feed's internal price/seat granularity.
 */
enum Availability: string
{
    case AVAILABLE = 'available';
    case LIMITED   = 'limited';
    case SOLD_OUT  = 'sold_out';

    /**
     * Map a feed `availabilityLevel` string to a state for a performance that IS
     * present in the feed (i.e. still on sale). Sold-out is never derived here — a
     * sold-out performance drops out of the feed entirely and is resolved by
     * {@see SoldOutResolver}; the seats-exhausted edge is handled in TodayTixClient.
     *
     * Observed feed values: "LOW" (few seats left) and null (no scarcity signal =
     * plenty available). Any other/unknown non-empty level is treated as LIMITED —
     * a conservative default that never over-promises availability.
     */
    public static function fromFeedLevel(?string $level): self
    {
        $normalized = strtoupper(trim((string) $level));

        return match ($normalized) {
            ''                 => self::AVAILABLE,
            'HIGH', 'GOOD', 'AVAILABLE' => self::AVAILABLE,
            default            => self::LIMITED,
        };
    }

    /** Human-facing label (buyer-visible; keep short — it sits in a calendar cell). */
    public function label(): string
    {
        return match ($this) {
            self::AVAILABLE => 'Available',
            self::LIMITED   => 'Limited',
            self::SOLD_OUT  => 'Sold Out',
        };
    }

    /** Whether a buyer can still purchase this performance (drives buy-link render). */
    public function isBuyable(): bool
    {
        return $this !== self::SOLD_OUT;
    }
}
