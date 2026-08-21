<?php

declare(strict_types=1);

namespace TodayTixCalendar\Providers\TodayTixCalendar;

use IX\Providers\Provider;
use TodayTixCalendar\Engine\OverrideResolver;
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
    /** ACF field key for the per-performance override table. */
    private const OVERRIDES_FIELD = 'field_todaytix_overrides_ui';

    /** Request key the per-row selects submit under. */
    private const POST_KEY = 'todaytix_override';

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

        // The per-performance override table. Rendered into an `esc_html => 0`
        // message field — the platform's pattern for custom hub UI (cf. arthouse-kit's
        // Migration tab) — and saved off the same form submit.
        add_filter('acf/load_field/key=' . self::OVERRIDES_FIELD, [$this, 'injectOverridesUi']);
        add_action('acf/save_post', [$this, 'saveOverrides'], 20);

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

        // Toggle labels track the site's configured display labels so the admin
        // matches the front end (e.g. View shows "Best Availability" / "Selling
        // Fast"); a site with no custom labels falls back to the feed's own names.
        $labels     = is_array($config['state_labels'] ?? null) ? $config['state_labels'] : [];
        $toggleLabel = static fn (string $slug, string $fallback): string
            => 'Show “' . (($labels[$slug] ?? '') !== '' ? $labels[$slug] : $fallback) . '”';

        // This iteration exposes only the editorial control — which availability
        // states appear. Everything else (show id, booking URL, run window,
        // per-performance deep link) is developer/build-time config supplied in
        // code via the `todaytix_calendar/config` filter, so it stays version-
        // controlled rather than in the DB. Add fields back here if a future site
        // needs to configure those from the CMS.
        $add('tab', $order, ['label' => 'Ticket Calendar', 'name' => '', 'type' => 'tab', 'placement' => 'top']);
        $add('show_available', $order + 1, ['label' => $toggleLabel('available', 'Available'), 'name' => 'todaytix_show_available', 'type' => 'true_false', 'ui' => 1, 'default_value' => 1, 'wrapper' => ['width' => '33.33333'], 'instructions' => 'Show performances with good availability. Turn off to hide them from the calendar.']);
        $add('show_limited', $order + 2, ['label' => $toggleLabel('limited', 'Limited'), 'name' => 'todaytix_show_limited', 'type' => 'true_false', 'ui' => 1, 'default_value' => 1, 'wrapper' => ['width' => '33.33333'], 'instructions' => 'Show performances with only a few tickets left. Turn off to hide them from the calendar.']);
        $add('show_sold_out', $order + 3, ['label' => $toggleLabel('sold_out', 'Sold Out'), 'name' => 'todaytix_show_sold_out', 'type' => 'true_false', 'ui' => 1, 'default_value' => 1, 'wrapper' => ['width' => '33.33333'], 'instructions' => 'Show performances with no inventory left. Turn off to hide them from the calendar.']);

        // Per-performance manual overrides. The message body is injected lazily in
        // injectOverridesUi() so we only hit the cache when the tab actually renders.
        $add('overrides_ui', $order + 4, [
            'key'       => self::OVERRIDES_FIELD,
            'label'     => 'Performance overrides',
            'name'      => 'todaytix_overrides_ui',
            'type'      => 'message',
            'message'   => '',
            'new_lines' => '',
            'esc_html'  => 0,
        ]);
    }

    /**
     * Inject the override table into the message field at render time.
     *
     * @param array<string, mixed> $field
     *
     * @return array<string, mixed>
     */
    public function injectOverridesUi(array $field): array
    {
        $field['message'] = $this->overridesHtml();

        return $field;
    }

    /**
     * Persist the submitted overrides.
     *
     * Guarded on the request key being present: the hub is one form across many tabs,
     * and a save that never rendered this field must not be read as "the editor
     * cleared every override". All validation — unknown ids, bad slugs, and the
     * seeded-performance rule — lives in the engine.
     *
     * @param mixed $postId ACF's save target; 'options' for the hub page.
     */
    public function saveOverrides($postId): void
    {
        if ($postId !== 'options' || !current_user_can('manage_options')) {
            return;
        }
        // ACF has already verified its own nonce by the time acf/save_post fires.
        if (!isset($_POST[self::POST_KEY]) || !is_array($_POST[self::POST_KEY])) {
            return;
        }

        $raw = wp_unslash($_POST[self::POST_KEY]); // phpcs:ignore WordPress.Security.NonceVerification

        $this->container->get(TodayTixCalendarService::class)->saveOverrides((array) $raw);
    }

    /**
     * The override table: one row per performance, grouped by month, each row a select
     * that defaults to "use TodayTix". Only rows the editor actually changes are
     * stored — see {@see \TodayTixCalendar\Engine\OverrideResolver}.
     */
    private function overridesHtml(): string
    {
        $service = $this->container->get(TodayTixCalendarService::class);
        $run     = $service->baseRun();

        if ($run === []) {
            return '<p><em>No performances available yet. The calendar fills in automatically once TodayTix data has been fetched.</em></p>';
        }

        $config    = $service->config();
        $labels    = is_array($config['state_labels'] ?? null) ? $config['state_labels'] : [];
        $overrides = $service->overrides();
        $timeFmt   = (string) ($config['time_format'] ?? '') ?: 'g:i A';

        ob_start();
        ?>
        <p style="max-width:64em;">
            Every performance below follows TodayTix automatically. Change a row only when you
            want to say something different from the live feed — everything you leave on
            <strong>Use TodayTix</strong> keeps updating on its own. Setting a row back to
            <strong>Use TodayTix</strong> hands it straight back to the feed.
        </p>
        <?php if ($overrides !== []) : ?>
            <p><strong><?php echo count($overrides); ?></strong> performance<?php echo count($overrides) === 1 ? ' is' : 's are'; ?> currently overridden.</p>
        <?php endif; ?>
        <table class="widefat striped" style="max-width:64em;">
            <thead>
                <tr>
                    <th style="width:34%;">Performance</th>
                    <th style="width:22%;">TodayTix says</th>
                    <th style="width:44%;">Show on the calendar as</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $month = '';
            foreach ($run as $showtime) :
                $thisMonth = $showtime->datetime->format('F Y');
                if ($thisMonth !== $month) :
                    $month = $thisMonth;
                    ?>
                    <tr><th colspan="3" style="background:#f0f0f1;"><?php echo esc_html($month); ?></th></tr>
                <?php endif;

                $id      = $showtime->id;
                $feed    = $showtime->availability;
                $feedLbl = ($labels[$feed->value] ?? '') !== '' ? $labels[$feed->value] : $feed->label();
                $current = $overrides[$id] ?? OverrideResolver::AUTO;
                $choices = OverrideResolver::choicesFor($id, $labels);
                $seeded  = $id < 0;
                ?>
                <tr>
                    <td>
                        <?php echo esc_html($showtime->datetime->format('D, M j') . ' at ' . $showtime->datetime->format($timeFmt)); ?>
                        <?php if ($seeded) : ?>
                            <span title="TodayTix has no record of this performance, so it can only be shown as sold out." style="color:#787c82;">&nbsp;·&nbsp;not in feed</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($feedLbl); ?></td>
                    <td>
                        <select name="<?php echo esc_attr(self::POST_KEY); ?>[<?php echo esc_attr((string) $id); ?>]">
                            <?php foreach ($choices as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($current, $value); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($current !== OverrideResolver::AUTO) : ?>
                            <span style="color:#b32d2e;">&nbsp;overridden</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php

        return (string) ob_get_clean();
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

    /**
     * Editor-only: register the block on the client so the editor can render it
     * (a ServerSideRender preview plus the heading/intro controls). Without this
     * the editor reports "your site doesn't include support for this block". Hooked
     * automatically by the BlockManager on 'enqueue_block_editor_assets'.
     */
    public function enqueueBlockEditorAssets(): void
    {
        $this->enqueueEditorScript('todaytix-calendar-block-editor', 'calendar.js', ['wp-server-side-render']);
        $this->enqueueDistStyle('todaytix-calendar-block-editor', 'css/calendar-editor.css');
    }
}
