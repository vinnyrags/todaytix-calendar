<?php

declare(strict_types=1);

namespace TodayTixCalendar\Providers\TodayTixCalendar\Endpoints;

use Mythus\Support\Rest\Endpoint;
use TodayTixCalendar\Engine\Showtime;
use TodayTixCalendar\Providers\TodayTixCalendar\TodayTixCalendarService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Read-only cached availability, at /wp-json/theme/v1/todaytix/availability.
 *
 * Serves the same cache the block renders from — never hits TodayTix at request
 * time. Optional for launch (the calendar is fully server-rendered); it exists so a
 * future enhancement can refresh availability client-side without a full page load.
 */
final class AvailabilityEndpoint extends Endpoint
{
    public function __construct(
        private readonly TodayTixCalendarService $service,
    ) {}

    public function getRoute(): string
    {
        return '/todaytix/availability';
    }

    public function getMethods(): string
    {
        return 'GET';
    }

    /** Public — it's the same data anyone sees on the page. */
    public function getPermission(WP_REST_Request $request): bool
    {
        return true;
    }

    public function callback(WP_REST_Request $request): WP_REST_Response
    {
        $performances = array_map(
            static fn (Showtime $s): array => [
                'id'    => $s->id,
                'date'  => $s->localDate(),
                'time'  => $s->localTime(),
                'state' => $s->availability->value,
            ],
            $this->service->resolvedRun(),
        );

        return new WP_REST_Response([
            'performances' => $performances,
            'count'        => count($performances),
        ], 200);
    }
}
