<?php

/**
 * Server-side render for todaytix/calendar.
 *
 * Pulls the cached, resolved month grids from the service (never the API) and hands
 * them to Timber. All availability logic already happened in the engine/service — the
 * template is pure presentation. Identical markup ships to the editor via
 * ServerSideRender so authors preview exactly what the front end shows.
 *
 * @var array<string,mixed> $attributes Block attributes (heading, intro).
 */

use IX\Theme;
use Timber\Timber;
use TodayTixCalendar\Providers\TodayTixCalendar\TodayTixCalendarService;

/** @var TodayTixCalendarService $service */
$service   = Theme::container()->get(TodayTixCalendarService::class);
$months    = $service->getCalendar();
$priceFrom = $service->getPriceFrom();

// Weekday header, Sunday-first to match the engine's default grid.
$weekdays = [
    ['abbr' => 'Sun', 'full' => 'Sunday'],
    ['abbr' => 'Mon', 'full' => 'Monday'],
    ['abbr' => 'Tue', 'full' => 'Tuesday'],
    ['abbr' => 'Wed', 'full' => 'Wednesday'],
    ['abbr' => 'Thu', 'full' => 'Thursday'],
    ['abbr' => 'Fri', 'full' => 'Friday'],
    ['abbr' => 'Sat', 'full' => 'Saturday'],
];

$context = Timber::context();
$context['wrapper_attributes'] = get_block_wrapper_attributes(['class' => 'ttx-calendar']);
$context['heading']  = isset($attributes['heading']) ? trim((string) $attributes['heading']) : '';
$context['intro']    = isset($attributes['intro']) ? trim((string) $attributes['intro']) : '';
$context['months']   = $months;
$context['weekdays'] = $weekdays;
$context['price_from'] = $priceFrom;
$context['empty_message'] = __('Performance dates will appear here once they go on sale.', 'todaytix-calendar');

Timber::render(__DIR__ . '/calendar.twig', $context);
