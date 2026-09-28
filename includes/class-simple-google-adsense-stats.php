<?php
/**
 * Simple_Google_Adsense stats (impressions, viewable impressions & clicks of your own ads)
 *
 * Measurement definitions (aligned with IAB/MRC guidelines):
 * - Impression: the ad was rendered on a page a real browser displayed
 *   (not hidden, not a prerender or prefetch).
 * - Viewable impression: at least 50% of the ad was on screen for at least
 *   one continuous second while the tab was visible.
 * - Click: the visitor activated a link in the ad (mouse, touch tap,
 *   keyboard or middle click) - not a scroll that started on the ad.
 *
 * Privacy: counted in the browser and sent as batched beacons; only daily
 * totals are stored (one row per day/ad/placement/device) - no cookies, IP
 * addresses or visitor IDs. Works behind page caches.
 *
 * Invalid traffic: requests from known bots, prefetchers and headless
 * browsers are ignored, as are logged-in administrators (checked on the
 * server, so it works on cached pages too).
 *
 * @package Simple_Google_Adsense
 * @since   1.4.0
 */

defined('ABSPATH') || exit;

/**
 * Simple_Google_Adsense_Stats Class.
 *
 * @class Simple_Google_Adsense_Stats
 */
final class Simple_Google_Adsense_Stats
{

    /**
     * Schema version.
     */
    const DB_VERSION = '2';

    /**
     * Option holding the installed schema version.
     */
    const DB_VERSION_OPTION = 'simple_google_adsense_stats_db';

    /**
     * Option holding tracking settings.
     */
    const OPTION_NAME = 'simple_google_adsense_tracking';

    /**
     * Option holding the endpoint self-test result.
     */
    const TRANSPORT_OPTION = 'simple_google_adsense_stats_transport';

    /**
     * REST namespace - deliberately neutral so ad blockers do not block it.
     */
    const REST_NAMESPACE = 'afx/v1';

    /**
     * admin-ajax action used when the REST API is blocked.
     */
    const AJAX_ACTION = 'afx_e';

    /**
     * Max events accepted per request.
     */
    const MAX_EVENTS = 50;

    /**
     * The single instance of the class.
     *
     * @var Simple_Google_Adsense_Stats
     * @since 1.4.0
     */
    protected static $_instance = null;

    /**
     * Main Simple_Google_Adsense_Stats Instance.
     *
     * @return Simple_Google_Adsense_Stats
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
     * Simple_Google_Adsense_Stats Constructor.
     */
    public function __construct()
    {
        add_action('plugins_loaded', array(__CLASS__, 'maybe_install'), 5);
        add_action('rest_api_init', array($this, 'routes'));
        add_action('wp_ajax_' . self::AJAX_ACTION, array($this, 'collect_ajax'));
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, array($this, 'collect_ajax'));
        add_action('admin_init', array($this, 'register_setting'));
        add_action('admin_init', array($this, 'privacy_policy'));
        add_filter('adflow_settings_tabs', array($this, 'register_tab'), 25);
        add_filter('adflow_health_checks', array($this, 'health_check'));
        add_action('adflow_daily_maintenance', array($this, 'prune'));
        add_action('adflow_daily_maintenance', array(__CLASS__, 'self_test'));
        add_action('adflow_stats_self_test', array(__CLASS__, 'self_test'));
        add_action('before_delete_post', array($this, 'forget_deleted_ad'));
        add_action('init', array($this, 'handle_redirect'), 0);

        if (!wp_next_scheduled('adflow_daily_maintenance')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'adflow_daily_maintenance');
        }
    }

    /**
     * Stats table name.
     *
     * @return string
     * @since 1.4.0
     */
    public static function table()
    {
        global $wpdb;

        return $wpdb->prefix . 'afx_stats';
    }

    /**
     * Create or upgrade the table (activation does not run on plugin updates).
     *
     * @since 1.4.0
     */
    public static function maybe_install()
    {
        if (self::DB_VERSION === get_option(self::DB_VERSION_OPTION)) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$table} (
  day date NOT NULL,
  ad_id bigint(20) unsigned NOT NULL,
  placement varchar(40) NOT NULL DEFAULT '',
  device tinyint(1) unsigned NOT NULL DEFAULT 0,
  impressions int(10) unsigned NOT NULL DEFAULT 0,
  viewable int(10) unsigned NOT NULL DEFAULT 0,
  clicks int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,ad_id,placement,device),
  KEY ad_day (ad_id,day)
) {$charset};");

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    /**
     * Tracking settings.
     *
     * @return array
     * @since 1.4.0
     */
    public static function settings()
    {
        $settings = get_option(self::OPTION_NAME);

        return wp_parse_args(is_array($settings) ? $settings : array(), array(
            'enabled' => true,
            'exclude_admins' => true,
            'wait_for_consent' => false,
            'retention_months' => 24,
            'click_mode' => 'beacon',
        ));
    }

    /**
     * Whether clicks are counted through the redirect link.
     *
     * @return bool
     * @since 1.4.0
     */
    public static function uses_redirect()
    {
        $settings = self::settings();

        return self::is_enabled() && 'redirect' === $settings['click_mode'];
    }

    /**
     * Replace tracking macros in a destination URL.
     *
     * {ad_id}, {placement}, {cachebuster}, {site}
     *
     * @param string $url URL.
     * @param int $ad_id Ad ID.
     * @param string $placement Placement.
     * @return string
     * @since 1.4.0
     */
    public static function expand_macros($url, $ad_id, $placement)
    {
        // Saved URLs keep the braces URL-encoded.
        $url = str_ireplace(array('%7B', '%7D'), array('{', '}'), (string) $url);

        return strtr($url, array(
            '{ad_id}' => rawurlencode((string) (int) $ad_id),
            '{placement}' => rawurlencode('' !== (string) $placement ? (string) $placement : 'manual'),
            '{cachebuster}' => (string) wp_rand(100000000, 999999999),
            '{site}' => rawurlencode((string) wp_parse_url(home_url(), PHP_URL_HOST)),
        ));
    }

    /**
     * The redirect link for an ad.
     *
     * @param int $ad_id Ad ID.
     * @param string $placement Placement.
     * @return string
     * @since 1.4.0
     */
    public static function redirect_url($ad_id, $placement)
    {
        $args = array('afx-go' => (int) $ad_id);

        if ('' !== (string) $placement) {
            $args['afx-p'] = sanitize_key($placement);
        }

        return add_query_arg($args, home_url('/'));
    }

    /**
     * Count the click and forward the visitor (runs before WordPress routes).
     *
     * @since 1.4.0
     */
    public function handle_redirect()
    {
        if (empty($_GET['afx-go'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        $ad_id = absint($_GET['afx-go']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $placement = isset($_GET['afx-p']) ? substr(sanitize_key(wp_unslash($_GET['afx-p'])), 0, 40) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $unit = Simple_Google_Adsense_Ad_Units::get($ad_id);

        if (!$unit || !in_array($unit['type'], array('image', 'text'), true) || '' === (string) $unit['url']) {
            return;
        }

        if (!empty($unit['track']) && self::is_enabled() && !self::is_invalid_traffic() && !self::is_excluded_user()) {
            // Ignore repeats from the same browser within 10 seconds (double clicks, reloads).
            $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
            $key = 'afx_c_' . md5($ip . '|' . $ua . '|' . $ad_id . '|' . wp_salt('nonce'));

            if (!get_transient($key)) {
                set_transient($key, 1, 10);
                self::write(array(array('ad_id' => $ad_id, 'placement' => $placement, 'device' => wp_is_mobile() ? 1 : 0, 'i' => 0, 'v' => 0, 'c' => 1)));
            }
        }

        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow');
        wp_redirect(esc_url_raw(self::expand_macros($unit['url'], $ad_id, $placement)), 302, 'AdFlow'); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- destination set by an ad manager.
        exit;
    }

    /**
     * Whether tracking is on.
     *
     * @return bool
     * @since 1.4.0
     */
    public static function is_enabled()
    {
        $settings = self::settings();

        return !empty($settings['enabled']);
    }

    /**
     * Endpoint the browser sends events to: REST, or admin-ajax when the
     * daily self-test found the REST API blocked (security plugins often do).
     *
     * @return string
     * @since 1.4.0
     */
    public static function endpoint()
    {
        return 'ajax' === get_option(self::TRANSPORT_OPTION)
            ? admin_url('admin-ajax.php?action=' . self::AJAX_ACTION)
            : rest_url(self::REST_NAMESPACE . '/e');
    }

    /**
     * Front-end script config. Identical for every visitor, so it is safe
     * in cached pages; who is excluded is decided on the server.
     *
     * @return array
     * @since 1.4.0
     */
    public static function script_config()
    {
        $settings = self::settings();

        /**
         * Filters the front-end script config (Pro adds the country lookup URL).
         *
         * @param array $config
         * @since 1.4.0
         */
        return apply_filters('adflow_fx_config', array(
            'url' => esc_url_raw(self::endpoint()),
            'track' => self::is_enabled(),
            'consent' => !empty($settings['wait_for_consent']),
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Collecting
    |--------------------------------------------------------------------------
    */

    /**
     * REST route.
     *
     * @since 1.4.0
     */
    public function routes()
    {
        register_rest_route(self::REST_NAMESPACE, '/e', array(
            'methods' => 'POST',
            'callback' => array($this, 'collect'),
            // Public by design: counts only, validated against published ad units.
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * Whether the request comes from a bot, prefetcher or headless browser.
     *
     * @return bool
     * @since 1.4.0
     */
    public static function is_invalid_traffic()
    {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))) : '';

        if ('' === $ua || strlen($ua) < 20) {
            return true;
        }

        foreach (array('HTTP_PURPOSE', 'HTTP_SEC_PURPOSE', 'HTTP_X_PURPOSE', 'HTTP_X_MOZ') as $header) {
            if (!empty($_SERVER[$header]) && false !== stripos(sanitize_text_field(wp_unslash($_SERVER[$header])), 'prefetch')) {
                return true;
            }
        }

        $pattern = apply_filters('adflow_bot_pattern', '/bot\b|bot\/|crawl|spider|slurp|mediapartners|adsbot|facebookexternalhit|embedly|quora link|outbrain|pinterest|preview|fetch|monitor|uptime|pingdom|gtmetrix|lighthouse|pagespeed|headless|phantom|puppeteer|playwright|selenium|curl|wget|python|java\/|go-http|okhttp|axios|node-fetch|scrapy|httpclient|libwww|ahrefs|semrush|mj12|dotbot|petalbot|bytespider|yandex|baiduspider|applebot|duckduck|bingpreview|gptbot|claudebot|ccbot|perplexity/i');

        return (bool) preg_match($pattern, $ua);
    }

    /**
     * Whether the visitor is a logged-in administrator (checked from the
     * auth cookie - works even when the page itself came from a cache).
     *
     * @return bool
     * @since 1.4.0
     */
    private static function is_excluded_user()
    {
        $settings = self::settings();

        if (empty($settings['exclude_admins'])) {
            return false;
        }

        $user_id = is_user_logged_in() ? get_current_user_id() : wp_validate_auth_cookie('', 'logged_in');

        return $user_id && user_can($user_id, 'manage_options');
    }

    /**
     * admin-ajax transport.
     *
     * @since 1.4.0
     */
    public function collect_ajax()
    {
        $this->process(file_get_contents('php://input')); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        status_header(204);
        exit;
    }

    /**
     * REST transport.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     * @since 1.4.0
     */
    public function collect($request)
    {
        $body = (string) $request->get_body();

        if ('' === $body && $request->get_param('d')) {
            $body = (string) $request->get_param('d');
        }

        $this->process($body);

        return new WP_REST_Response(null, 204);
    }

    /**
     * Validate and store a batch of events.
     *
     * Event: { "a": ad id, "p": placement, "t": "i" (impression) | "v" (viewable) | "c" (click), "d": 0 desktop | 1 mobile }
     *
     * @param string $body JSON body.
     * @return int Number of events stored.
     * @since 1.4.0
     */
    public function process($body)
    {
        if (!self::is_enabled() || self::is_invalid_traffic() || self::is_excluded_user()) {
            return 0;
        }

        $events = json_decode((string) $body, true);

        if (!is_array($events) || !$events) {
            return 0;
        }

        // With a persistent object cache, cap events per visitor per minute
        // (the hashed address lives in memory for 60 seconds, never stored).
        if (wp_using_ext_object_cache()) {
            $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
            $key = 'afx_rl_' . md5($ip . wp_salt('nonce'));
            $count = (int) wp_cache_get($key, 'afx');

            if ($count > 300) {
                return 0;
            }

            wp_cache_set($key, $count + count($events), 'afx', MINUTE_IN_SECONDS);
        }

        $rows = array();
        $valid = array();
        $stored = 0;

        foreach (array_slice($events, 0, self::MAX_EVENTS) as $event) {
            if (!is_array($event) || empty($event['a']) || !isset($event['t']) || !in_array($event['t'], array('i', 'v', 'c'), true)) {
                continue;
            }

            $ad_id = absint($event['a']);

            if (!isset($valid[$ad_id])) {
                $valid[$ad_id] = self::is_trackable($ad_id);
            }

            if (!$valid[$ad_id]) {
                continue;
            }

            $placement = isset($event['p']) ? substr(sanitize_key($event['p']), 0, 40) : '';
            $device = !empty($event['d']) ? 1 : 0;
            $key = $ad_id . '|' . $placement . '|' . $device;

            if (!isset($rows[$key])) {
                $rows[$key] = array('ad_id' => $ad_id, 'placement' => $placement, 'device' => $device, 'i' => 0, 'v' => 0, 'c' => 0);
            }

            $rows[$key][$event['t']]++;
            $stored++;
        }

        self::write($rows);

        return $stored;
    }

    /**
     * Whether a unit exists, is published and has tracking on.
     *
     * @param int $ad_id Ad unit ID.
     * @return bool
     * @since 1.4.0
     */
    public static function is_trackable($ad_id)
    {
        $unit = Simple_Google_Adsense_Ad_Units::get($ad_id);

        return $unit && Simple_Google_Adsense_Ad_Units::is_own_ad($unit['type']) && !empty($unit['track']);
    }

    /**
     * Add rows to today's totals in one query.
     *
     * @param array[] $rows Each: ad_id, placement, device, i, v, c.
     * @since 1.4.0
     */
    public static function write($rows)
    {
        global $wpdb;

        if (!$rows) {
            return;
        }

        $day = current_time('Y-m-d');
        $values = array();

        foreach ($rows as $row) {
            $values[] = $wpdb->prepare('(%s, %d, %s, %d, %d, %d, %d)', $day, $row['ad_id'], $row['placement'], $row['device'] ? 1 : 0, $row['i'], $row['v'], $row['c']);
        }

        $table = self::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- values prepared above.
        $wpdb->query("INSERT INTO {$table} (day, ad_id, placement, device, impressions, viewable, clicks) VALUES " . implode(', ', $values) . ' ON DUPLICATE KEY UPDATE impressions = impressions + VALUES(impressions), viewable = viewable + VALUES(viewable), clicks = clicks + VALUES(clicks)');
    }

    /**
     * Backwards-compatible single-row write.
     *
     * @param int $ad_id Ad ID.
     * @param string $placement Placement key.
     * @param int $device 0 desktop, 1 mobile.
     * @param int $impressions Impressions.
     * @param int $clicks Clicks.
     * @param int $viewable Viewable impressions.
     * @since 1.4.0
     */
    public static function record($ad_id, $placement, $device, $impressions, $clicks, $viewable = 0)
    {
        self::write(array(array('ad_id' => $ad_id, 'placement' => $placement, 'device' => $device, 'i' => $impressions, 'v' => $viewable, 'c' => $clicks)));
    }

    /**
     * Delete rows older than the retention period.
     *
     * @since 1.4.0
     */
    public function prune()
    {
        global $wpdb;

        $settings = self::settings();
        $months = max(1, (int) $settings['retention_months']);
        $table = self::table();

        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE day < %s", gmdate('Y-m-d', strtotime('-' . $months . ' months')))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    /**
     * When an ad unit is deleted permanently, delete its statistics so
     * totals always match the per-ad rows.
     *
     * @param int $post_id Post ID.
     * @since 1.4.0
     */
    public function forget_deleted_ad($post_id)
    {
        if (Simple_Google_Adsense_Ad_Units::POST_TYPE !== get_post_type($post_id) || !apply_filters('adflow_delete_stats_with_ad', true, $post_id)) {
            return;
        }

        global $wpdb;
        $table = self::table();

        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE ad_id = %d", $post_id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    /**
     * Check that the site can receive events over REST; switch to
     * admin-ajax when a security plugin blocks it.
     *
     * @return string rest|ajax|none
     * @since 1.4.0
     */
    public static function self_test()
    {
        $args = array(
            'timeout' => 10,
            'headers' => array('Content-Type' => 'application/json'),
            'body' => '[]',
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'user-agent' => 'Mozilla/5.0 (AdFlow self-test; like Gecko) Chrome/140',
        );

        $rest = wp_remote_post(rest_url(self::REST_NAMESPACE . '/e'), $args);

        if (!is_wp_error($rest) && 204 === (int) wp_remote_retrieve_response_code($rest)) {
            $result = 'rest';
        } else {
            $ajax = wp_remote_post(admin_url('admin-ajax.php?action=' . self::AJAX_ACTION), $args);
            $result = !is_wp_error($ajax) && 204 === (int) wp_remote_retrieve_response_code($ajax) ? 'ajax' : 'none';
        }

        update_option(self::TRANSPORT_OPTION, $result, true);

        return $result;
    }

    /**
     * Health check line for statistics.
     *
     * @param array $checks Checks.
     * @return array
     * @since 1.4.0
     */
    public function health_check($checks)
    {
        if (!self::is_enabled()) {
            return $checks;
        }

        $transport = get_option(self::TRANSPORT_OPTION);

        // Never test on page load (two loopback requests can stall the screen):
        // run it in the background and report on the next visit.
        if (!$transport) {
            if (!wp_next_scheduled('adflow_stats_self_test')) {
                wp_schedule_single_event(time(), 'adflow_stats_self_test');
            }

            $checks['stats'] = array(
                'status' => 'info',
                'label' => __('Ad statistics', 'simple-google-adsense'),
                'message' => __('Checking that this server accepts statistics requests - reload in a minute.', 'simple-google-adsense'),
            );

            return $checks;
        }

        if ('none' === $transport) {
            $checks['stats'] = array(
                'status' => 'error',
                'label' => __('Ad statistics', 'simple-google-adsense'),
                'message' => __('Your server refuses statistics requests (REST API and admin-ajax both blocked, usually by a security plugin). Impressions and clicks cannot be counted until it allows them.', 'simple-google-adsense'),
                'action' => Simple_Google_Adsense_Admin::settings_url('tracking'),
                'action_label' => __('Settings', 'simple-google-adsense'),
            );
        } else {
            $checks['stats'] = array(
                'status' => 'ok',
                'label' => __('Ad statistics', 'simple-google-adsense'),
                'message' => 'ajax' === $transport
                    ? __('Counting works (the REST API is blocked, so admin-ajax is used instead).', 'simple-google-adsense')
                    : __('Counting works and is compatible with page caching.', 'simple-google-adsense'),
            );
        }

        return $checks;
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /**
     * Build a WHERE clause.
     *
     * @param array $args from, to (Y-m-d), ad_id (int|int[]), placement.
     * @return string Prepared SQL fragment.
     * @since 1.4.0
     */
    private static function where($args)
    {
        global $wpdb;

        $where = $wpdb->prepare('day BETWEEN %s AND %s', $args['from'], $args['to']);

        if (!empty($args['ad_id'])) {
            $ids = array_filter(array_map('absint', (array) $args['ad_id']));
            $where .= $ids ? ' AND ad_id IN (' . implode(',', $ids) . ')' : ' AND 1=0';
        }

        if (isset($args['placement']) && '' !== $args['placement']) {
            $where .= $wpdb->prepare(' AND placement = %s', $args['placement']);
        }

        return $where;
    }

    /**
     * Normalise a date range (site time zone).
     *
     * @param array $args Args.
     * @return array
     * @since 1.4.0
     */
    public static function range($args)
    {
        $args = wp_parse_args($args, array('days' => 30, 'from' => '', 'to' => ''));

        if ('' === $args['from'] || '' === $args['to']) {
            $args['to'] = current_time('Y-m-d');
            $args['from'] = gmdate('Y-m-d', strtotime($args['to'] . ' 00:00:00 UTC -' . (max(1, (int) $args['days']) - 1) . ' days'));
        }

        return $args;
    }

    /**
     * Metrics from summed counts.
     *
     * @param array $row impressions, viewable, clicks.
     * @return array + ctr, viewability
     * @since 1.4.0
     */
    private static function with_rates($row)
    {
        $row['ctr'] = $row['impressions'] > 0 ? $row['clicks'] / $row['impressions'] : 0;
        $row['viewability'] = $row['impressions'] > 0 ? min(1, $row['viewable'] / $row['impressions']) : 0;

        return $row;
    }

    /**
     * Empty metrics row.
     *
     * @return array
     * @since 1.4.0
     */
    public static function empty_row()
    {
        return self::with_rates(array('impressions' => 0, 'viewable' => 0, 'clicks' => 0));
    }

    /**
     * Totals for a range.
     *
     * @param array $args Range and filters.
     * @return array impressions, viewable, clicks, ctr, viewability
     * @since 1.4.0
     */
    public static function totals($args = array())
    {
        global $wpdb;

        $args = self::range($args);
        $table = self::table();
        $row = $wpdb->get_row("SELECT SUM(impressions) AS i, SUM(viewable) AS v, SUM(clicks) AS c FROM {$table} WHERE " . self::where($args), ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is internal; where() uses $wpdb->prepare().

        return self::with_rates(array('impressions' => (int) $row['i'], 'viewable' => (int) $row['v'], 'clicks' => (int) $row['c']));
    }

    /**
     * Totals grouped by a column.
     *
     * @param string $column ad_id|placement|device|day.
     * @param array $args Range and filters.
     * @return array[] key => impressions, viewable, clicks, ctr, viewability
     * @since 1.4.0
     */
    public static function grouped($column, $args = array())
    {
        global $wpdb;

        if (!in_array($column, array('ad_id', 'placement', 'device', 'day'), true)) {
            return array();
        }

        $args = self::range($args);
        $table = self::table();
        $results = $wpdb->get_results("SELECT {$column} AS k, SUM(impressions) AS i, SUM(viewable) AS v, SUM(clicks) AS c FROM {$table} WHERE " . self::where($args) . " GROUP BY {$column} ORDER BY {$column}", ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $column is whitelisted above; where() uses $wpdb->prepare().

        $out = array();

        foreach ((array) $results as $row) {
            $out[$row['k']] = self::with_rates(array('impressions' => (int) $row['i'], 'viewable' => (int) $row['v'], 'clicks' => (int) $row['c']));
        }

        if ('day' === $column) {
            // Fill empty days so charts have no gaps.
            $filled = array();
            for ($ts = strtotime($args['from'] . ' 00:00:00 UTC'); $ts <= strtotime($args['to'] . ' 00:00:00 UTC'); $ts += DAY_IN_SECONDS) {
                $day = gmdate('Y-m-d', $ts);
                $filled[$day] = isset($out[$day]) ? $out[$day] : self::empty_row();
            }
            $out = $filled;
        }

        return $out;
    }

    /**
     * Format a ratio as a percentage.
     *
     * @param float $ratio Ratio.
     * @return string
     * @since 1.4.0
     */
    public static function format_ctr($ratio)
    {
        return number_format_i18n($ratio * 100, 2) . '%';
    }

    /*
    |--------------------------------------------------------------------------
    | Settings tab & privacy
    |--------------------------------------------------------------------------
    */

    /**
     * Register the setting.
     *
     * @since 1.4.0
     */
    public function register_setting()
    {
        register_setting('adflow_tracking', self::OPTION_NAME, array(
            'sanitize_callback' => array($this, 'sanitize'),
        ));
    }

    /**
     * Sanitize settings.
     *
     * @param array $input Input.
     * @return array
     * @since 1.4.0
     */
    public function sanitize($input)
    {
        $input = is_array($input) ? $input : array();

        // Re-test the endpoint after any change.
        delete_option(self::TRANSPORT_OPTION);

        return array(
            'enabled' => !empty($input['enabled']),
            'exclude_admins' => !empty($input['exclude_admins']),
            'wait_for_consent' => !empty($input['wait_for_consent']),
            'retention_months' => isset($input['retention_months']) ? max(1, min(120, absint($input['retention_months']))) : 24,
            'click_mode' => isset($input['click_mode']) && 'redirect' === $input['click_mode'] ? 'redirect' : 'beacon',
        );
    }

    /**
     * Add the Tracking tab.
     *
     * @param array $tabs Tabs.
     * @return array
     * @since 1.4.0
     */
    public function register_tab($tabs)
    {
        $tabs['tracking'] = array(
            'label' => __('Tracking', 'simple-google-adsense'),
            'callback' => array($this, 'render_tab'),
            'priority' => 25,
        );

        return $tabs;
    }

    /**
     * Render the Tracking tab.
     *
     * @since 1.4.0
     */
    public function render_tab()
    {
        $s = self::settings();
        $name = self::OPTION_NAME;
        $A = 'Simple_Google_Adsense_Admin';
        ?>
        <form action="options.php" method="post">
            <?php settings_fields('adflow_tracking'); ?>

            <?php $A::panel_start(__('Statistics for your own ads', 'simple-google-adsense'), __('Impressions, viewable impressions and clicks of your image, text and custom code ads. AdSense statistics come from Google.', 'simple-google-adsense')); ?>
                <?php $A::field_start(__('Statistics', 'simple-google-adsense'), __('Cookieless: only daily totals are stored - no IP addresses, cookies or visitor IDs. Works with page caching.', 'simple-google-adsense')); ?>
                    <?php $A::toggle($name . '[enabled]', !empty($s['enabled']), __('Count impressions and clicks', 'simple-google-adsense')); ?>
                <?php $A::field_end(); ?>

                <?php $A::field_start(__('Your own visits', 'simple-google-adsense'), __('Checked on the server, so it also works on cached pages.', 'simple-google-adsense')); ?>
                    <?php $A::toggle($name . '[exclude_admins]', !empty($s['exclude_admins']), __('Do not count logged-in administrators', 'simple-google-adsense')); ?>
                <?php $A::field_end(); ?>

                <?php $A::field_start(__('Consent', 'simple-google-adsense'), __('Only count visitors who accepted statistics cookies in a consent plugin that supports the WP Consent API.', 'simple-google-adsense')); ?>
                    <?php $A::toggle($name . '[wait_for_consent]', !empty($s['wait_for_consent']), __('Wait for statistics consent', 'simple-google-adsense')); ?>
                    <?php if (!function_exists('wp_has_consent')) : ?>
                        <p class="description"><?php esc_html_e('No WP Consent API plugin detected - with this on, nothing is counted.', 'simple-google-adsense'); ?></p>
                    <?php endif; ?>
                <?php $A::field_end(); ?>

                <?php $A::field_start(__('Counting clicks', 'simple-google-adsense'), __('The redirect link counts on the server (IAB-preferred, works without JavaScript); the in-page method keeps the advertiser\'s link visible.', 'simple-google-adsense')); ?>
                    <fieldset>
                        <label style="display:block;margin-bottom:6px"><input type="radio" name="<?php echo esc_attr($name); ?>[click_mode]" value="beacon" <?php checked('redirect' !== $s['click_mode']); ?>> <?php esc_html_e('In the page - links go straight to the advertiser', 'simple-google-adsense'); ?></label>
                        <label style="display:block"><input type="radio" name="<?php echo esc_attr($name); ?>[click_mode]" value="redirect" <?php checked('redirect' === $s['click_mode']); ?>> <?php esc_html_e('Through a redirect link on your site (/?afx-go=…)', 'simple-google-adsense'); ?></label>
                    </fieldset>
                    <p class="description"><?php esc_html_e('Links can carry tracking tags: {ad_id}, {placement}, {cachebuster} - e.g. https://example.com/?utm_source=mysite&utm_content={placement}', 'simple-google-adsense'); ?></p>
                <?php $A::field_end(); ?>

                <?php $A::field_start(__('Keep data for', 'simple-google-adsense'), '', 'adflow-retention'); ?>
                    <div class="adflow-inline">
                        <input type="number" id="adflow-retention" class="small-text" min="1" max="120" name="<?php echo esc_attr($name); ?>[retention_months]" value="<?php echo esc_attr($s['retention_months']); ?>">
                        <?php esc_html_e('months', 'simple-google-adsense'); ?>
                    </div>
                <?php $A::field_end(); ?>
            <?php $A::panel_end(); ?>

            <?php $A::panel_start(__('How AdFlow measures', 'simple-google-adsense')); ?>
                <div class="adflow-panel__body--padded" style="padding-left:0;padding-right:0">
                    <ul class="adflow-ticks">
                        <li><?php esc_html_e('Impression: the ad was displayed on a page a real browser showed - not hidden, prefetched or prerendered.', 'simple-google-adsense'); ?></li>
                        <li><?php esc_html_e('Viewable impression: at least 50% of the ad was on screen for at least one continuous second (IAB/MRC standard).', 'simple-google-adsense'); ?></li>
                        <li><?php esc_html_e('Click: a link in the ad was opened - a scroll that starts on the ad is not a click.', 'simple-google-adsense'); ?></li>
                        <li><?php esc_html_e('Not counted: known bots and crawlers, headless browsers, link previews, prefetches and logged-in administrators.', 'simple-google-adsense'); ?></li>
                        <li><?php esc_html_e('Days follow your site\'s time zone (Settings → General).', 'simple-google-adsense'); ?></li>
                    </ul>
                </div>
            <?php $A::panel_end(); ?>

            <div class="adflow-savebar">
                <?php submit_button(__('Save changes', 'simple-google-adsense'), 'primary', 'submit', false); ?>
            </div>
        </form>
        <?php
    }

    /**
     * Suggested privacy policy text.
     *
     * @since 1.4.0
     */
    public function privacy_policy()
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        wp_add_privacy_policy_content(
            'AdFlow',
            wp_kses_post(wpautop(__('This site shows advertisements, including Google AdSense. Google may use cookies to serve ads - see https://policies.google.com/technologies/ads.

For its own advertisements, this site counts how often each ad is shown, seen and clicked. Only daily totals are stored; no cookies are set and no IP addresses or other personal data are recorded for this.', 'simple-google-adsense')))
        );
    }
}
