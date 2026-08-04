<?php

declare(strict_types=1);

namespace TodayTixCalendar\Engine\Http;

use RuntimeException;

/**
 * Thrown by an {@see HttpTransport} when a request cannot be completed, and by
 * {@see \TodayTixCalendar\Engine\TodayTixClient} when the response is unusable
 * (bad JSON, non-200 API code). Signals "no fresh data" to the caller, which then
 * serves last-known-good rather than a fatal.
 */
final class TransportException extends RuntimeException
{
}
