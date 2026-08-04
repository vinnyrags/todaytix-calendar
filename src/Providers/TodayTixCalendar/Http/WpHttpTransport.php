<?php

declare(strict_types=1);

namespace TodayTixCalendar\Providers\TodayTixCalendar\Http;

use TodayTixCalendar\Engine\Http\HttpTransport;
use TodayTixCalendar\Engine\Http\TransportException;

/**
 * The WordPress adapter for the engine's {@see HttpTransport} seam — wraps
 * wp_remote_get with a short timeout and turns any non-2xx / WP_Error into the
 * engine's {@see TransportException}. This is the ONLY place the engine touches
 * WordPress's HTTP API; everything upstream stays framework-agnostic.
 */
final class WpHttpTransport implements HttpTransport
{
    public function __construct(
        private readonly int $timeout = 8,
    ) {}

    public function get(string $url): string
    {
        $response = wp_remote_get($url, [
            'timeout' => $this->timeout,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            throw new TransportException("TodayTix request failed: {$response->get_error_message()}");
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            throw new TransportException("TodayTix request returned HTTP {$code}.");
        }

        return (string) wp_remote_retrieve_body($response);
    }
}
