<?php

declare(strict_types=1);

namespace TodayTixCalendar\Engine\Http;

/**
 * The one seam between the pure engine and the outside world.
 *
 * The engine never talks to WordPress or to the network directly — it asks an
 * HttpTransport for a URL's body. In WordPress the adapter wraps wp_remote_get;
 * in tests it returns a canned fixture. Keeping this an interface is what makes
 * {@see \TodayTixCalendar\Engine\TodayTixClient} unit-testable with no HTTP.
 */
interface HttpTransport
{
    /**
     * GET the URL and return the raw response body.
     *
     * @throws TransportException on any transport-level failure (timeout, non-2xx,
     *                            connection error). The caller decides how to fall
     *                            back — the engine itself never swallows this.
     */
    public function get(string $url): string;
}
