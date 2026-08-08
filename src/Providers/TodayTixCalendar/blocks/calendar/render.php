<?php

/**
 * Server-side render for todaytix/calendar.
 *
 * Pulls the cached, resolved month grids from the service (never the API) and hands
 * them to Timber. All availability logic already happened in the engine/service — the
 * template is pure presentation. Identical markup ships to the editor via
 * ServerSideRender so authors preview exactly what the front end shows. The block
 * has no attributes of its own — heading/intro copy is composed around it with
 * core/heading + core/paragraph in the CMS.
 */

use IX\Theme;
use Timber\Timber;
use TodayTixCalendar\Providers\TodayTixCalendar\TodayTixCalendarService;

/** @var TodayTixCalendarService $service */
$service   = Theme::container()->get(TodayTixCalendarService::class);
$months    = $service->getCalendar();
$priceFrom = $service->getPriceFrom();

// Legend from the configured labels: only states that are visible AND have a label
// (a blank label — e.g. sold-out on a "more inventory coming" show — is omitted).
$config        = $service->config();
$stateLabels   = is_array($config['state_labels'] ?? null) ? $config['state_labels'] : [];
$visibleStates = is_array($config['visible_states'] ?? null) ? $config['visible_states'] : ['available', 'limited', 'sold_out'];
$defaultLabels = ['available' => 'Available', 'limited' => 'Limited', 'sold_out' => 'Sold Out'];
$legendItems   = [];
foreach (['available', 'limited', 'sold_out'] as $slug) {
    if (!in_array($slug, $visibleStates, true)) {
        continue;
    }
    $label = $stateLabels[$slug] ?? $defaultLabels[$slug];
    if ($label !== '') {
        $legendItems[] = ['slug' => $slug, 'label' => $label];
    }
}

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
$context['months']   = $months;
$context['weekdays'] = $weekdays;
$context['price_from'] = $priceFrom;
$context['legend_items'] = $legendItems;
$context['empty_message'] = __('Performance dates will appear here once they go on sale.', 'todaytix-calendar');
// Any heading/intro copy is composed around the block with core/heading +
// core/paragraph in the CMS; the block itself is just the calendar.
$context['region_label'] = __('Performance calendar', 'todaytix-calendar');

Timber::render(__DIR__ . '/calendar.twig', $context);
