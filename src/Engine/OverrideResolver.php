<?php

declare(strict_types=1);

namespace TodayTixCalendar\Engine;

/**
 * Normalises the editor-supplied manual state overrides into the sparse map the
 * service applies at render time.
 *
 * The contract, and the reason this is deliberately sparse: an override is an
 * *exception*, not a categorisation of the whole run. Any performance without an
 * entry keeps whatever the live TodayTix feed says about it, and keeps updating on
 * every refresh. Setting a row back to "use TodayTix" removes its entry entirely and
 * hands the performance back to the feed.
 *
 * Pure logic — no WordPress, no persistence. The caller supplies the raw submitted
 * values and the current run; this decides what is legitimate to store.
 */
final class OverrideResolver
{
    /** Submitted value meaning "no override — defer to the feed". */
    public const AUTO = '';

    /**
     * Build the storable override map from raw submitted input.
     *
     * Rejects, rather than stores:
     *   - ids that aren't in the current run (stale rows from an old page load)
     *   - values that aren't a real {@see Availability} case
     *   - AUTO / empty, which is the *absence* of an override
     *   - promoting a seed-only performance to a buyable state (see below)
     *
     * @param array<array-key, mixed> $raw Submitted {performanceId: stateSlug}.
     * @param Showtime[]              $run The current resolved run (pre-override).
     *
     * @return array<int, string> Sparse {performanceId: stateSlug}, ids as ints.
     */
    public static function normalize(array $raw, array $run): array
    {
        $byId = [];
        foreach ($run as $showtime) {
            $byId[$showtime->id] = $showtime;
        }

        $out = [];
        foreach ($raw as $id => $slug) {
            if (!is_numeric($id)) {
                continue;
            }
            $id = (int) $id;

            if (!isset($byId[$id])) {
                continue; // not a performance we currently know about
            }
            if (!is_string($slug) || $slug === self::AUTO) {
                continue; // "use TodayTix" — store nothing
            }

            $state = Availability::tryFrom($slug);
            if ($state === null) {
                continue;
            }
            if (!self::isAllowedFor($id, $state)) {
                continue;
            }

            $out[$id] = $state->value;
        }

        ksort($out);

        return $out;
    }

    /**
     * Whether a performance may be forced into a given state.
     *
     * A **seeded** performance (negative, synthetic id — one we know exists only
     * because a human told us, since TodayTix has never listed it) carries no real
     * TodayTix showtime id. {@see BuyLinkBuilder} interpolates that id into the
     * checkout URL, so forcing such a performance into a buyable state would render
     * a live, clickable link to a broken booking URL. Marking one sold out is always
     * safe; promoting one is not, and is refused here rather than in the UI so the
     * rule holds no matter who calls it.
     *
     * When TodayTix genuinely re-lists the performance it arrives with a real id and
     * this restriction stops applying on its own — no cleanup needed.
     */
    public static function isAllowedFor(int $performanceId, Availability $state): bool
    {
        if ($performanceId >= 0) {
            return true; // real TodayTix id — any state is safe
        }

        return $state === Availability::SOLD_OUT;
    }

    /**
     * The states an editor may choose for a performance, as {slug: label} — for
     * building the row's dropdown. Always includes AUTO first.
     *
     * @return array<string, string>
     */
    public static function choicesFor(int $performanceId, array $labels = []): array
    {
        $choices = [self::AUTO => 'Use TodayTix'];

        foreach (Availability::cases() as $case) {
            if (!self::isAllowedFor($performanceId, $case)) {
                continue;
            }
            $label = $labels[$case->value] ?? '';
            $choices[$case->value] = $label !== '' ? $label : $case->label();
        }

        return $choices;
    }

    /**
     * Drop overrides whose performance is no longer in the run, so the stored map
     * doesn't accumulate orphans as the run evolves (e.g. a seeded slot that later
     * self-heals into the feed under a real id).
     *
     * @param array<array-key, mixed> $overrides
     * @param Showtime[]              $run
     *
     * @return array<int, string>
     */
    public static function prune(array $overrides, array $run): array
    {
        return self::normalize($overrides, $run);
    }
}
