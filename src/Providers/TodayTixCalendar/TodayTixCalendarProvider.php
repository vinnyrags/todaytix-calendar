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

        // Self-register the "Ticket Calendar" tab on the site's Settings Hub (after
        // the hub group, which registers at acf/init priority 5). Only fires when a
        // hub group is configured, so the package stays portable + config-only.
        add_action('acf/init', [$this, 'registerSettingsTab'], 20);

        parent::register();
    }

    /**
     * Register the "Ticket Calendar" tab + fields on the configured Settings-Hub
     * group. The package owns its own settings surface; the consuming site only tells
     * it which hub group to attach to (config `settings_group`) — no hard dependency
     * on any particular hub package. Field values are read back in
     * {@see TodayTixCalendarService::config()}.
     */
    public function registerSettingsTab(): void
    {
        if (!function_exists('acf_add_local_field')) {
            return;
        }
        $config = $this->container->get(TodayTixCalendarService::class)->config();
        $group  = (string) ($config['settings_group'] ?? '');
        if ($group === '') {
            return; // no hub wired — stay config-filter-only
        }
        $order = (int) ($config['settings_order'] ?? 60);

        $add = static function (string $key, int $menuOrder, array $field) use ($group): void {
            acf_add_local_field(array_merge(
                ['key' => "field_todaytix_{$key}", 'parent' => $group, 'menu_order' => $menuOrder],
                $field,
            ));
        };

        $add('tab', $order, ['label' => 'Ticket Calendar', 'name' => '', 'type' => 'tab', 'placement' => 'top']);
        $add('show_id', $order + 1, ['label' => 'TodayTix Show ID', 'name' => 'todaytix_show_id', 'type' => 'number', 'instructions' => "The show's TodayTix id (e.g. 46495). Overrides the code default when set."]);
        $add('base_url', $order + 2, ['label' => 'White-label Booking URL', 'name' => 'todaytix_base_url', 'type' => 'url', 'instructions' => 'e.g. https://tickets.viewfromthebridgeplay.com — where the Buy buttons point.']);
        $add('run_start', $order + 3, ['label' => 'Run Start', 'name' => 'todaytix_run_start', 'type' => 'date_picker', 'display_format' => 'F j, Y', 'return_format' => 'Y-m-d', 'first_day' => 0, 'instructions' => 'First month the calendar shows. Blank = derive from the feed.']);
        $add('run_end', $order + 4, ['label' => 'Run End', 'name' => 'todaytix_run_end', 'type' => 'date_picker', 'display_format' => 'F j, Y', 'return_format' => 'Y-m-d', 'first_day' => 0, 'instructions' => 'Last month the calendar shows. Blank = derive from the feed.']);
        $add('week_starts_on', $order + 5, ['label' => 'Week Starts On', 'name' => 'todaytix_week_starts_on', 'type' => 'select', 'choices' => [0 => 'Sunday', 1 => 'Monday'], 'default_value' => 0]);
        $add('states_msg', $order + 6, ['label' => 'Which statuses to show', 'name' => '', 'type' => 'message', 'message' => 'Toggle which availability states appear on the calendar.']);
        $add('show_available', $order + 7, ['label' => 'Show “Available”', 'name' => 'todaytix_show_available', 'type' => 'true_false', 'ui' => 1, 'default_value' => 1]);
        $add('show_limited', $order + 8, ['label' => 'Show “Limited”', 'name' => 'todaytix_show_limited', 'type' => 'true_false', 'ui' => 1, 'default_value' => 1]);
        $add('show_sold_out', $order + 9, ['label' => 'Show “Sold Out”', 'name' => 'todaytix_show_sold_out', 'type' => 'true_false', 'ui' => 1, 'default_value' => 1]);
        $add('buy_path_template', $order + 10, ['label' => 'Buy-link route (advanced)', 'name' => 'todaytix_buy_path_template', 'type' => 'text', 'instructions' => 'Tokens: {show_id} {showtime_id} {month} {date}. Blank = month-calendar route.']);
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
