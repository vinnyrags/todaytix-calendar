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

        // The per-performance override table, echoed directly into the field wrapper.
        //
        // It deliberately does NOT go through the `message` field's own body: that
        // renders via `echo acf_esc_html($m)`, i.e. wp_kses against $allowedposttags,
        // which permits <table> but strips <select> and <option> — so the table would
        // render with no controls. Rendering on acf/render_field/key= instead lets us
        // emit form inputs ourselves (every dynamic value escaped at the point of use).
        add_action('acf/render_field/key=' . self::OVERRIDES_FIELD, [$this, 'renderOverridesUi']);
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
     * Echo the override table into the field wrapper. Built lazily so the cache is
     * only read when the tab actually renders.
     *
     * @param array<string, mixed> $field
     */
    public function renderOverridesUi(array $field): void
    {
        echo $this->overridesHtml(); // phpcs:ignore WordPress.Security.EscapingOutput -- built below; every dynamic value escaped at the point of use.
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
     * The override table: one editable row per performance TodayTix actually carries,
     * each a select defaulting to "Use TodayTix". Only rows the editor changes are
     * stored — see {@see \TodayTixCalendar\Engine\OverrideResolver}.
     *
     * Performances TodayTix has no record of (seeded, synthetic negative id) are listed
     * separately and read-only. There is genuinely no choice to make on them: they
     * already resolve SOLD_OUT, and forcing SOLD_OUT changes nothing — so offering a
     * control would be a no-op dressed as a decision. They stay visible, collapsed, so
     * the screen still accounts for every date on the calendar rather than silently
     * showing fewer performances than the site does.
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

        // Split on whether TodayTix carries the performance at all.
        $editable = [];
        $notInFeed = [];
        foreach ($run as $showtime) {
            if ($showtime->id < 0) {
                $notInFeed[] = $showtime;
            } else {
                $editable[] = $showtime;
            }
        }

        $when = fn ($s): string => $s->datetime->format('D, M j') . ' at ' . $s->datetime->format($timeFmt);

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
            foreach ($editable as $showtime) :
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
                ?>
                <tr>
                    <td><?php echo esc_html($when($showtime)); ?></td>
                    <td><?php echo esc_html($feedLbl); ?></td>
                    <td>
                        <select name="<?php echo esc_attr(self::POST_KEY); ?>[<?php echo esc_attr((string) $id); ?>]">
                            <?php foreach (OverrideResolver::choicesFor($id, $labels) as $value => $label) : ?>
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

        <?php if ($notInFeed !== []) : ?>
            <details style="max-width:64em;margin-top:1.5em;">
                <summary style="cursor:pointer;padding:.5em 0;">
                    <strong><?php echo count($notInFeed); ?></strong>
                    performance<?php echo count($notInFeed) === 1 ? '' : 's'; ?> not in the TodayTix feed
                    — shown on the calendar as sold out
                </summary>
                <p style="margin:.75em 0;color:#50575e;">
                    TodayTix has no record of these, so there is nothing to control: they can only
                    show as sold out. If one goes on sale it returns to the feed automatically,
                    moves into the table above, and becomes editable — no action needed here.
                </p>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width:50%;">Performance</th>
                            <th style="width:50%;">Shown on the calendar as</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($notInFeed as $showtime) : ?>
                        <tr>
                            <td><?php echo esc_html($when($showtime)); ?></td>
                            <td style="color:#50575e;">Sold out</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        <?php endif; ?>
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
