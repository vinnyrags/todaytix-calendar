<?php

declare(strict_types=1);

namespace TodayTixCalendar\Providers\TodayTixCalendar;

use IX\Providers\Provider;
use TodayTixCalendar\Providers\TodayTixCalendar\Endpoints\AvailabilityEndpoint;

/**
 * Wires the TodayTix ticket calendar into a Mythus/IX theme.
 *
 * Registers the `todaytix/calendar` block (server-rendered — see blocks/calendar/
 * render.php), the 10-minute WP-Cron refresh that warms the cache, and an optional
 * read-only REST endpoint for the cached availability.
 *
 * The provider owns wiring only; all logic lives in {@see TodayTixCalendarService}
 * (WP glue) and the pure engine beneath it. The service is resolved from the DI
 * container — PHP-DI autowires it and its {@see Http\WpHttpTransport} dependency.
 *
 * Site config (show id, white-label base URL, run window) is NOT here: it comes
 * through the `todaytix_calendar/config` filter the consuming theme provides, so this
 * package stays show-agnostic and portable.
 */
final class TodayTixCalendarProvider extends Provider
{
    /** @var string[] The server-rendered calendar block (blocks/calendar/). */
    protected array $blocks = ['calendar'];

    /** @var array<class-string> Read-only cached-availability endpoint (optional). */
    protected array $routes = [
        AvailabilityEndpoint::class,
    ];

    /** Pin the endpoint to /wp-json/theme/v1/... to match the other ARTHOUSE routes. */
    protected string $routeNamespace = 'theme';

    public function register(): void
    {
        $service = $this->container->get(TodayTixCalendarService::class);

        // Cron: a 10-minute interval that warms the availability cache out-of-band.
        add_filter('cron_schedules', [$service, 'registerCronInterval']);
        add_action(TodayTixCalendarService::CRON_HOOK, [$service, 'refresh']);
        $service->scheduleCron();

        // Frontend block stylesheet + progressive-enhancement paging script.
        add_action('enqueue_block_assets', [$this, 'enqueueCalendarAssets']);

        parent::register();
    }

    /**
     * The block's CSS (both editor and front) and, on the front end only, the
     * month-paging view script — loaded just for pages that actually use the block.
     */
    public function enqueueCalendarAssets(): void
    {
        $this->enqueueDistStyle('todaytix-calendar-block', 'css/calendar.css');

        if (!is_admin() && function_exists('has_block') && has_block('todaytix/calendar')) {
            $this->enqueueDistScript('todaytix-calendar-view', 'js/calendar-view.js');
        }
    }
}
