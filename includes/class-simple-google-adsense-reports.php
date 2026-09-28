<?php
/**
 * Simple_Google_Adsense Reports
 *
 * Impressions, clicks and CTR of your own ads. AdSense numbers come from
 * Google (Pro's Earnings screen).
 *
 * @package Simple_Google_Adsense
 * @since   1.4.0
 */

defined('ABSPATH') || exit;

/**
 * Simple_Google_Adsense_Reports Class.
 *
 * @class Simple_Google_Adsense_Reports
 */
final class Simple_Google_Adsense_Reports
{

    /**
     * Page slug.
     */
    const PAGE_SLUG = 'adflow-reports';

    /**
     * The single instance of the class.
     *
     * @var Simple_Google_Adsense_Reports
     * @since 1.4.0
     */
    protected static $_instance = null;

    /**
     * Main Simple_Google_Adsense_Reports Instance.
     *
     * @return Simple_Google_Adsense_Reports
     * @since 1.4.0
     * @static
     */
    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Simple_Google_Adsense_Reports Constructor.
     */
    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_menu'), 14);
    }

    /**
     * Add the submenu.
     *
     * @since 1.4.0
     */
    public function add_menu()
    {
        add_submenu_page(
            Simple_Google_Adsense_Admin::MENU_SLUG,
            __('Reports', 'simple-google-adsense'),
            __('Reports', 'simple-google-adsense'),
            Simple_Google_Adsense_Caps::VIEW_REPORTS,
            self::PAGE_SLUG,
            array($this, 'render')
        );
    }

    /**
     * Available ranges. Pro adds more (and custom dates).
     *
     * @return array key => label
     * @since 1.4.0
     */
    public static function ranges()
    {
        return apply_filters('adflow_reports_ranges', array(
            '7' => __('Last 7 days', 'simple-google-adsense'),
            '30' => __('Last 30 days', 'simple-google-adsense'),
        ));
    }

    /**
     * Current report arguments from the request.
     *
     * @return array days, from, to, ad_id, range
     * @since 1.4.0
     */
    public static function current_args()
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only report filters.
        $ranges = self::ranges();
        $range = isset($_GET['range']) ? sanitize_key(wp_unslash($_GET['range'])) : '30';
        $range = isset($ranges[$range]) ? $range : '30';
        $ad_id = isset($_GET['ad']) ? absint($_GET['ad']) : 0;
        // phpcs:enable

        $args = array(
            'range' => $range,
            'days' => is_numeric($range) ? (int) $range : 30,
            'from' => '',
            'to' => '',
            'ad_id' => $ad_id,
        );

        /**
         * Filters the report arguments (Pro: custom date ranges).
         *
         * @param array $args
         * @since 1.4.0
         */
        return apply_filters('adflow_reports_args', $args);
    }

    /**
     * Your own ad units.
     *
     * @return array[] id => unit
     * @since 1.4.0
     */
    public static function own_units()
    {
        $units = array();

        foreach (Simple_Google_Adsense_Ad_Units::get_all() as $unit) {
            if (Simple_Google_Adsense_Ad_Units::is_own_ad($unit['type'])) {
                $units[$unit['id']] = $unit;
            }
        }

        return $units;
    }

    /**
     * Render a fixed-height bar chart.
     *
     * @param array $series day => value.
     * @param string $label Accessible summary.
     * @since 1.4.0
     */
    public static function render_chart($series, $label)
    {
        $max = $series ? max(array_merge(array(0), array_values($series))) : 0;
        $top = $max > 0 ? (int) ceil($max / pow(10, floor(log10(max(1, $max))))) * pow(10, floor(log10(max(1, $max)))) : 10;
        $count = count($series);
        $every = (int) max(1, ceil($count / 10));
        $i = 0;
        ?>
        <div class="adflow-chart" role="img" aria-label="<?php echo esc_attr($label); ?>">
            <div class="adflow-chart__axis" aria-hidden="true">
                <?php foreach (array(1, 0.5, 0) as $fraction) : ?>
                    <span style="bottom:<?php echo esc_attr($fraction * 100); ?>%"><?php echo esc_html(number_format_i18n($top * $fraction)); ?></span>
                <?php endforeach; ?>
            </div>
            <div class="adflow-chart__plot" aria-hidden="true">
                <?php foreach (array(1, 0.5, 0) as $fraction) : ?>
                    <i class="adflow-chart__grid" style="bottom:<?php echo esc_attr($fraction * 100); ?>%"></i>
                <?php endforeach; ?>
                <?php foreach ($series as $day => $value) :
                    $tip = date_i18n(get_option('date_format'), strtotime($day)) . ': ' . number_format_i18n($value);
                    ?>
                    <div class="adflow-chart__col" title="<?php echo esc_attr($tip); ?>">
                        <span class="adflow-chart__bar" style="height:<?php echo esc_attr($top ? round($value / $top * 100, 2) : 0); ?>%"></span>
                        <span class="adflow-chart__x"><?php echo 0 === $i++ % $every ? esc_html(date_i18n('M j', strtotime($day))) : ''; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render the page.
     *
     * @since 1.4.0
     */
    public function render()
    {
        $args = self::current_args();
        $units = self::own_units();
        $totals = Simple_Google_Adsense_Stats::totals($args);
        $by_ad = Simple_Google_Adsense_Stats::grouped('ad_id', $args);
        $daily = Simple_Google_Adsense_Stats::grouped('day', $args);
        $types = Simple_Google_Adsense_Ad_Units::get_types();
        $statuses = Simple_Google_Adsense_Ad_Units::status_labels();
        ?>
        <div class="wrap adflow-page">
            <?php
            Simple_Google_Adsense_Admin::render_header(
                __('Reports', 'simple-google-adsense'),
                __('Impressions and clicks of your own ads - banners, text ads and custom code. AdSense earnings come from Google, under Earnings.', 'simple-google-adsense')
            );
            ?>

            <?php if (empty($units)) : ?>
                <section class="adflow-panel">
                    <div class="adflow-empty">
                        <span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>
                        <h3><?php esc_html_e('Run your own ads next to AdSense', 'simple-google-adsense'); ?></h3>
                        <p><?php esc_html_e('Add an image banner, text ad or custom code for a sponsor or your own offer. Impressions and clicks show up here - cookieless and cache-proof.', 'simple-google-adsense'); ?></p>
                        <a class="button button-primary" href="<?php echo esc_url(admin_url('post-new.php?post_type=' . Simple_Google_Adsense_Ad_Units::POST_TYPE)); ?>"><?php esc_html_e('Create your first ad', 'simple-google-adsense'); ?></a>
                    </div>
                </section>
            </div>
            <?php
                return;
            endif;
            ?>

            <form method="get" class="adflow-toolbar">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
                <label class="screen-reader-text" for="adflow-report-range"><?php esc_html_e('Date range', 'simple-google-adsense'); ?></label>
                <select id="adflow-report-range" name="range">
                    <?php foreach (self::ranges() as $key => $label) : ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($args['range'], $key); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php
                /**
                 * Fires inside the report filter bar (Pro: custom dates).
                 *
                 * @param array $args
                 * @since 1.4.0
                 */
                do_action('adflow_reports_filters', $args);
                ?>
                <label class="screen-reader-text" for="adflow-report-ad"><?php esc_html_e('Ad', 'simple-google-adsense'); ?></label>
                <select id="adflow-report-ad" name="ad">
                    <option value="0"><?php esc_html_e('All your ads', 'simple-google-adsense'); ?></option>
                    <?php foreach ($units as $id => $unit) : ?>
                        <option value="<?php echo esc_attr($id); ?>" <?php selected($args['ad_id'], $id); ?>><?php echo esc_html($unit['title']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button"><?php esc_html_e('Apply', 'simple-google-adsense'); ?></button>
                <span class="adflow-toolbar__spacer"></span>
                <span class="description">
                    <?php
                    /* translators: %s: time zone */
                    echo esc_html(sprintf(__('Days in %s', 'simple-google-adsense'), wp_timezone_string()));
                    ?>
                </span>
                <?php
                /**
                 * Fires at the right of the report filter bar (Pro: CSV export).
                 *
                 * @param array $args
                 * @since 1.4.0
                 */
                do_action('adflow_reports_actions', $args);
                ?>
            </form>

            <div class="adflow-stats">
                <div class="adflow-stat">
                    <div class="adflow-stat__label"><span><?php esc_html_e('Impressions', 'simple-google-adsense'); ?></span></div>
                    <p class="adflow-stat__value"><?php echo esc_html(number_format_i18n($totals['impressions'])); ?></p>
                    <p class="adflow-stat__meta"><?php esc_html_e('Ad displayed on a page', 'simple-google-adsense'); ?></p>
                </div>
                <div class="adflow-stat">
                    <div class="adflow-stat__label"><span><?php esc_html_e('Viewable', 'simple-google-adsense'); ?></span></div>
                    <p class="adflow-stat__value"><?php echo esc_html(number_format_i18n($totals['viewable'])); ?></p>
                    <p class="adflow-stat__meta">
                        <?php
                        /* translators: %s: viewability percentage */
                        echo esc_html(sprintf(__('%s viewability · 50%% on screen for 1s', 'simple-google-adsense'), Simple_Google_Adsense_Stats::format_ctr($totals['viewability'])));
                        ?>
                    </p>
                </div>
                <div class="adflow-stat">
                    <div class="adflow-stat__label"><span><?php esc_html_e('Clicks', 'simple-google-adsense'); ?></span></div>
                    <p class="adflow-stat__value"><?php echo esc_html(number_format_i18n($totals['clicks'])); ?></p>
                    <p class="adflow-stat__meta"><?php esc_html_e('Bots and administrators are not counted', 'simple-google-adsense'); ?></p>
                </div>
                <div class="adflow-stat">
                    <div class="adflow-stat__label"><span><?php esc_html_e('Click-through rate', 'simple-google-adsense'); ?></span></div>
                    <p class="adflow-stat__value"><?php echo esc_html(Simple_Google_Adsense_Stats::format_ctr($totals['ctr'])); ?></p>
                    <p class="adflow-stat__meta"><?php esc_html_e('Clicks per impression', 'simple-google-adsense'); ?></p>
                </div>
            </div>

            <section class="adflow-panel">
                <header class="adflow-panel__head"><div><h2><?php esc_html_e('Impressions per day', 'simple-google-adsense'); ?></h2></div></header>
                <div class="adflow-panel__body adflow-panel__body--padded">
                    <?php
                    self::render_chart(
                        wp_list_pluck($daily, 'impressions'),
                        /* translators: %s: number of impressions */
                        sprintf(__('Impressions per day, %s in total.', 'simple-google-adsense'), number_format_i18n($totals['impressions']))
                    );
                    ?>
                </div>
            </section>

            <section class="adflow-panel">
                <header class="adflow-panel__head"><div><h2><?php esc_html_e('By ad', 'simple-google-adsense'); ?></h2></div></header>
                <table class="widefat striped adflow-table">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Ad', 'simple-google-adsense'); ?></th>
                            <th scope="col"><?php esc_html_e('Status', 'simple-google-adsense'); ?></th>
                            <th scope="col" class="num"><?php esc_html_e('Impressions', 'simple-google-adsense'); ?></th>
                            <th scope="col" class="num"><?php esc_html_e('Viewability', 'simple-google-adsense'); ?></th>
                            <th scope="col" class="num"><?php esc_html_e('Clicks', 'simple-google-adsense'); ?></th>
                            <th scope="col" class="num"><?php esc_html_e('CTR', 'simple-google-adsense'); ?></th>
                            <?php do_action('adflow_reports_ad_columns_head'); ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Busiest ads first.
                        uksort($units, function ($a, $b) use ($by_ad) {
                            return (isset($by_ad[$b]) ? $by_ad[$b]['impressions'] : 0) - (isset($by_ad[$a]) ? $by_ad[$a]['impressions'] : 0);
                        });
                        foreach ($units as $id => $unit) :
                            if ($args['ad_id'] && (int) $args['ad_id'] !== (int) $id) {
                                continue;
                            }
                            $row = isset($by_ad[$id]) ? $by_ad[$id] : Simple_Google_Adsense_Stats::empty_row();
                            $status = Simple_Google_Adsense_Ad_Units::status($unit);
                            ?>
                            <tr>
                                <td>
                                    <a href="<?php echo esc_url((string) get_edit_post_link($id)); ?>"><strong><?php echo esc_html($unit['title']); ?></strong></a>
                                    <span class="adflow-table__sub"><?php echo esc_html(isset($types[$unit['type']]) ? strtok($types[$unit['type']], '(') : $unit['type']); ?></span>
                                </td>
                                <td><span class="adflow-pill adflow-pill--<?php echo esc_attr('running' === $status ? 'ok' : ('expired' === $status ? 'error' : 'info')); ?>"><?php echo esc_html($statuses[$status]); ?></span></td>
                                <td class="num"><?php echo esc_html(number_format_i18n($row['impressions'])); ?></td>
                                <td class="num"><?php echo esc_html(Simple_Google_Adsense_Stats::format_ctr($row['viewability'])); ?></td>
                                <td class="num"><?php echo esc_html(number_format_i18n($row['clicks'])); ?></td>
                                <td class="num"><?php echo esc_html(Simple_Google_Adsense_Stats::format_ctr($row['ctr'])); ?></td>
                                <?php
                                /**
                                 * Fires in each ad row (Pro: revenue / eCPM).
                                 *
                                 * @param array $unit
                                 * @param array $row
                                 * @param array $args
                                 * @since 1.4.0
                                 */
                                do_action('adflow_reports_ad_columns', $unit, $row, $args);
                                ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <?php
            /**
             * Fires below the report (Pro: placements, advertisers, devices).
             *
             * @param array $args
             * @since 1.4.0
             */
            do_action('adflow_reports_after', $args);
            ?>
        </div>
        <?php
    }
}
