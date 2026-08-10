<?php

declare(strict_types=1);

namespace TodayTixCalendar\Engine;

use DateTimeImmutable;

/**
 * Reconciles the durable canonical schedule against the live feed to detect sold-out.
 *
 * The core insight: TodayTix removes a performance from the feed once it can no
 * longer be sold (sold out, or sales closed). The feed alone therefore can't tell
 * you a performance is sold out — it just isn't there. So we keep a canonical set of
 * every performance ever seen (grown on each refresh via {@see mergeCanonical()}),
 * and any canonical performance now missing from the feed is reported SOLD_OUT.
 *
 * Pure logic, fully unit-testable. Timezone/"now" are the caller's concern — this
 * class only diffs two sets by id.
 */
final class SoldOutResolver
{
    /**
     * Produce the full resolved run: every canonical performance, plus any brand-new
     * feed performances not yet in canonical.
     *
     * @param PerformanceRef[] $canonical The durable schedule (id + datetime).
     * @param Showtime[]       $feed      The live feed just fetched.
     *
     * @return Showtime[] Resolved run, sorted chronologically. Performances present
     *                    in the feed carry their live availability; canonical
     *                    performances absent from the feed are marked SOLD_OUT.
     */
    public function resolve(array $canonical, array $feed): array
    {
        $feedById = [];
        foreach ($feed as $showtime) {
            $feedById[$showtime->id] = $showtime;
        }

        $resolved  = [];
        $seenIds   = [];

        foreach ($canonical as $ref) {
            $seenIds[$ref->id] = true;
            $resolved[] = $feedById[$ref->id]
                ?? new Showtime($ref->id, $ref->datetime, Availability::SOLD_OUT);
        }

        // New performances that appeared in the feed before canonical caught up.
        foreach ($feed as $showtime) {
            if (!isset($seenIds[$showtime->id])) {
                $resolved[] = $showtime;
            }
        }

        usort($resolved, static fn (Showtime $a, Showtime $b): int
            => $a->datetime <=> $b->datetime);

        return $resolved;
    }

    /**
     * Grow the canonical set by unioning in the current feed (dedup by id). This is
     * what makes sold-out detectable later: once a performance is recorded here it is
     * never forgotten, so its later disappearance from the feed reads as SOLD_OUT.
     *
     * Existing canonical datetimes win over feed datetimes for the same id (the
     * canonical record is the authoritative schedule; the feed can re-time a rush/
     * lottery slot without that meaning the original booking changed).
     *
     * @param PerformanceRef[] $canonical
     * @param Showtime[]       $feed
     *
     * @return PerformanceRef[] The grown canonical set, sorted chronologically.
     */
    public static function mergeCanonical(array $canonical, array $feed): array
    {
        $byId = [];
        foreach ($canonical as $ref) {
            $byId[$ref->id] = $ref;
        }
        foreach ($feed as $showtime) {
            if (!isset($byId[$showtime->id])) {
                $byId[$showtime->id] = $showtime->toRef();
            }
        }

        $refs = array_values($byId);
        usort($refs, static fn (PerformanceRef $a, PerformanceRef $b): int
            => $a->datetime <=> $b->datetime);

        return $refs;
    }

    /**
     * Merge "seed" performances — ones known to exist but that will never appear in
     * the feed (an early run that sold out before polling began, so no id was ever
     * captured) — into the canonical set, so {@see resolve()} reports them SOLD_OUT.
     *
     * Self-healing and idempotent: any prior synthetic seed ref (negative id) is
     * stripped first, then a fresh synthetic ref is added only for a seed slot the
     * real canonical / live feed don't already cover. So a seeded slot the feed later
     * carries is dropped here and its live entry wins — no phantom sold-out lingers.
     *
     * Slot identity is the local 'Y-m-d H:i' (matinee vs evening stay distinct);
     * synthetic ids are derived from the datetime (negative, so they can never
     * collide with TodayTix's positive ids). Pure logic — the caller supplies seed
     * datetimes already normalized to the show timezone.
     *
     * @param PerformanceRef[]    $canonical Real canonical (may include prior seeds).
     * @param Showtime[]          $feed      The live feed just fetched.
     * @param DateTimeImmutable[] $seed      Seed datetimes (in the show timezone).
     *
     * @return PerformanceRef[] The seeded canonical set, sorted chronologically.
     */
    public static function seedSoldOut(array $canonical, array $feed, array $seed): array
    {
        $slot = static fn (DateTimeImmutable $dt): string => $dt->format('Y-m-d H:i');

        // Drop prior synthetic seed refs; the real canonical + feed are the authority.
        $canonical = array_values(array_filter($canonical, static fn (PerformanceRef $r): bool => $r->id >= 0));

        $occupied = [];
        foreach ($canonical as $ref) {
            $occupied[$slot($ref->datetime)] = true;
        }
        foreach ($feed as $showtime) {
            $occupied[$slot($showtime->datetime)] = true;
        }

        foreach ($seed as $dt) {
            $key = $slot($dt);
            if (isset($occupied[$key])) {
                continue; // the real canonical / feed already own this slot
            }
            $occupied[$key] = true;
            $canonical[]    = new PerformanceRef(-(int) $dt->format('YmdHi'), $dt);
        }

        usort($canonical, static fn (PerformanceRef $a, PerformanceRef $b): int
            => $a->datetime <=> $b->datetime);

        return $canonical;
    }
}
