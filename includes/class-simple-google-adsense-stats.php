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
 * addresses or visitor IDs. Works behind page caches. Abuse limits use a
 * fixed-size table of salted one-way numbers shared by many visitors.
 *
 * Abuse: the counter is public, so forging can be made costly, not impossible.
 * Each request needs the page's long-lived token, a same-site Origin, a small
 * body and known placements; requests per client are limited; and a day's
 * clicks on an ad may not exceed its impressions (the rest are set aside).
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
    const DB_VERSION = '3';

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
     * Largest request body read, in bytes. 50 events fit in about 4 KB.
     */
    const MAX_BODY = 16384;

    /**
     * Option holding the token key and when tokens were introduced on this site.
     */
    const KEY_OPTION = 'simple_google_adsense_stats_key';

    /**
     * Option holding the report cache generation.
     */
    const CACHE_OPTION = 'simple_google_adsense_stats_cache';

    /**
     * Length of one token window, in seconds (30 days).
     */
    const TOKEN_WINDOW = 2592000;

    /**
     * How many older token windows are still accepted (about a year of page cache).
     */
    const TOKEN_OLD_WINDOWS = 12;

    /**
     * After an upgrade, how long pages cached without a token still count (30 days).
     */
    const TOKEN_GRACE = 2592000;

    /**
     * Rate limit: weighted events per client per minute (a click weighs 5).
     */
    const RATE_LIMIT = 600;

    /**
     * Fixed table size: rate-limit numbers.
     */
    const RATE_BUCKETS = 4096;

    /**
     * Fixed table size: repeat-click numbers.
     */
    const REPEAT_BUCKETS = 65536;

    /**
     * Longest report range in days (the longest retention, 120 months).
     */
    const MAX_RANGE_DAYS = 3660;

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
        add_filter('rest_pre_dispatch', array($this, 'refuse_oversized'), 1, 3);
        add_action('wp_ajax_' . self::AJAX_ACTION, array($this, 'collect_ajax'));
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, array($this, 'collect_ajax'));
        add_action('admin_init', array($this, 'register_setting'));
        add_action('admin_init', array($this, 'privacy_policy'));
        add_filter('wp_privacy_personal_data_exporters', array($this, 'register_exporter'));
        add_filter('wp_privacy_personal_data_erasers', array($this, 'register_eraser'));
        add_filter('adflow_settings_tabs', array($this, 'register_tab'), 25);
        add_filter('adflow_health_checks', array($this, 'health_check'));
        add_action('adflow_daily_maintenance', array($this, 'prune'));
        add_action('adflow_daily_maintenance', array(__CLASS__, 'reconcile_held_clicks'));
        add_action('adflow_daily_maintenance', array(__CLASS__, 'self_test'));
        add_action('adflow_stats_self_test', array(__CLASS__, 'self_test'));
        add_action('before_delete_post', array($this, 'forget_deleted_ad'));
        // After add-ons have registered their placements on init.
        add_action('init', array($this, 'handle_redirect'), 20);

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
     * Fixed-size table behind the rate limit and repeat-click check.
     *
     * @return string
     * @since 1.6.0
     */
    public static function limits_table()
    {
        global $wpdb;

        return $wpdb->prefix . 'afx_limits';
    }

    /**
     * Clicks set aside because the day's impressions did not cover them.
     *
     * @return string
     * @since 1.6.0
     */
    public static function held_table()
    {
        global $wpdb;

        return $wpdb->prefix . 'afx_held';
    }

    /**
     * Create or upgrade the table (activation does not run on plugin updates).
     *
     * @since 1.4.0
     */
    public static function maybe_install()
    {
        // One query instead of one each, for flags most pages read.
        if (function_exists('wp_prime_option_caches')) {
            wp_prime_option_caches(array(
                self::DB_VERSION_OPTION,
                self::OPTION_NAME,
                self::KEY_OPTION,
                'simple_google_adsense_settings',
                'simple_google_adsense_placements',
                'adflow_consent',
                'adflow_import_map',
            ));
        }

        $installed = get_option(self::DB_VERSION_OPTION);

        if (self::DB_VERSION === $installed) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        $limits = self::limits_table();
        $held = self::held_table();
        $charset = $wpdb->get_charset_collate();

        // Only adds tables: the statistics table itself is not altered.
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
) {$charset};
CREATE TABLE {$limits} (
  bucket int(10) unsigned NOT NULL,
  win int(10) unsigned NOT NULL DEFAULT 0,
  hits int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (bucket)
) {$charset};
CREATE TABLE {$held} (
  day date NOT NULL,
  ad_id bigint(20) unsigned NOT NULL,
  placement varchar(40) NOT NULL DEFAULT '',
  device tinyint(1) unsigned NOT NULL DEFAULT 0,
  clicks int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,ad_id,placement,device)
) {$charset};");

        // A new site has no cached pages, so tokens apply from the first request.
        add_option(self::KEY_OPTION, array('key' => wp_generate_password(64, false, false), 'since' => $installed ? time() : 0), '', true);

        // Repeat-click flags from older versions (one options row per click).
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_afx\\_c\\_%' OR option_name LIKE '\\_transient\\_timeout\\_afx\\_c\\_%'"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        // Small flags read on most pages no longer need a query of their own.
        if (function_exists('wp_set_option_autoload_values')) {
            wp_set_option_autoload_values(array(
                self::OPTION_NAME => true,
                'simple_google_adsense_settings' => true,
                'simple_google_adsense_placements' => true,
                'adflow_consent' => true,
            ));
        }

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, true);
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

        $ad_id = is_scalar($_GET['afx-go']) ? absint($_GET['afx-go']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $placement = isset($_GET['afx-p']) ? self::clean_placement(wp_unslash($_GET['afx-p'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned by the helper.
        $unit = Simple_Google_Adsense_Ad_Units::get($ad_id);

        if (!$unit || !in_array($unit['type'], array('image', 'text'), true) || '' === (string) $unit['url']) {
            return;
        }

        // Old links keep working in the in-page mode, but the browser counts
        // those clicks, so a request here counts only in the redirect mode.
        if (!empty($unit['track']) && self::uses_redirect() && !self::is_invalid_traffic() && !self::is_excluded_user() && !self::rate_exceeded(5)) {
            // Ignore repeats from the same browser within 10 seconds (double clicks, reloads).
            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
            $bucket = self::RATE_BUCKETS + (self::bucket_hash(self::client_ip() . '|' . $ua . '|' . $ad_id) % self::REPEAT_BUCKETS);

            if (1 === self::hit($bucket, 10, 1)) {
                self::add_clicks(array(array('ad_id' => $ad_id, 'placement' => $placement, 'device' => wp_is_mobile() ? 1 : 0, 'c' => 1)));
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
            'url' => esc_url_raw(add_query_arg('k', self::token(), self::endpoint())),
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

        // Real browsers and apps must not match: DuckDuckGo, the Pinterest and
        // Yandex apps, Cubot and Tecno Phantom phones, smart "Monitor" devices.
        $pattern = apply_filters('adflow_bot_pattern', '/(?<!cu)bot\b|bot\/|crawl|spider|slurp|mediapartners|adsbot|facebookexternalhit|embedly|quora link|outbrain|pinterest\/0\.|preview|fetch|monitoring|monitor\/|-monitor|site24x7|statuscake|uptime|pingdom|gtmetrix|lighthouse|pagespeed|headless|phantomjs|puppeteer|playwright|selenium|curl|wget|python|java\/|go-http|okhttp|axios|node-fetch|scrapy|httpclient|libwww|ahrefs|semrush|mj12|dotbot|petalbot|bytespider|yandex(?!search|browser)|baiduspider|applebot|bingpreview|gptbot|claudebot|ccbot|perplexity/i');

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
        // Read one byte past the limit (the header can be absent or wrong).
        $body = (int) (isset($_SERVER['CONTENT_LENGTH']) ? $_SERVER['CONTENT_LENGTH'] : 0) > self::MAX_BODY ? '' : file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.Security.ValidatedSanitizedInput

        if ($this->request_allowed()) {
            $this->process($body);
        }

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

        if ('' === $body && is_string($request->get_param('d'))) {
            $body = $request->get_param('d');
        }

        if ($this->request_allowed()) {
            $this->process($body);
        }

        return new WP_REST_Response(null, 204);
    }

    /**
     * Answer an oversized beacon before WordPress decodes its JSON body
     * (core decodes it while matching the route, before the callback runs).
     *
     * @param mixed $result Result.
     * @param WP_REST_Server $server Server.
     * @param WP_REST_Request $request Request.
     * @return mixed
     * @since 1.6.0
     */
    public function refuse_oversized($result, $server, $request)
    {
        if (null === $result && 0 === strcasecmp(untrailingslashit($request->get_route()), '/' . self::REST_NAMESPACE . '/e') && strlen((string) $request->get_body()) > self::MAX_BODY) {
            // Core reads parameters while finishing the response, which would decode it.
            $request->set_body('');

            return new WP_REST_Response(null, 413);
        }

        return $result;
    }

    /**
     * Token for the current window, printed in the page. The same for every
     * visitor, so it is safe in cached pages.
     *
     * @param int|null $time Time (default now).
     * @return string
     * @since 1.6.0
     */
    public static function token($time = null)
    {
        $window = (int) floor((null === $time ? time() : (int) $time) / self::TOKEN_WINDOW);

        return $window . '.' . self::sign($window);
    }

    /**
     * Signature of a token window.
     *
     * @param int $window Window number.
     * @return string
     * @since 1.6.0
     */
    private static function sign($window)
    {
        return substr(hash_hmac('sha256', 'afx|' . (int) $window, self::secret()), 0, 20);
    }

    /**
     * The stored site key, created on first use. Not derived from the WordPress
     * salts, so rotating those does not stop cached pages from counting.
     *
     * @return string
     * @since 1.6.0
     */
    private static function secret()
    {
        $stored = get_option(self::KEY_OPTION);

        if (is_array($stored) && !empty($stored['key'])) {
            return (string) $stored['key'];
        }

        $key = wp_generate_password(64, false, false);
        add_option(self::KEY_OPTION, array('key' => $key, 'since' => 0), '', true);
        $stored = get_option(self::KEY_OPTION);

        return is_array($stored) && !empty($stored['key']) ? (string) $stored['key'] : $key;
    }

    /**
     * Whether a token is valid now: signed by this site, not from the future,
     * and from one of the windows a cached page could still be using.
     *
     * @param mixed $token Token.
     * @param int|null $time Time (default now).
     * @return bool
     * @since 1.6.0
     */
    public static function token_valid($token, $time = null)
    {
        if (!is_string($token) || !preg_match('/^(\d{1,10})\.([0-9a-f]{20})$/', $token, $parts)) {
            return false;
        }

        $current = (int) floor((null === $time ? time() : (int) $time) / self::TOKEN_WINDOW);
        $window = (int) $parts[1];

        return $window <= $current && $window >= $current - self::TOKEN_OLD_WINDOWS && hash_equals(self::sign($window), $parts[2]);
    }

    /**
     * Whether this request may add to the counters.
     *
     * Refusals still answer 204: nothing tells a forger why it did not count.
     *
     * @return bool
     * @since 1.6.0
     */
    private function request_allowed()
    {
        if (!self::origin_allowed(isset($_SERVER['HTTP_ORIGIN']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_ORIGIN'])) : '')) {
            return false;
        }

        /**
         * Filters whether beacons need the page's token. Turn off only for pages
         * kept for more than a year (a static export, for example).
         *
         * @param bool $required
         * @since 1.6.0
         */
        if (!apply_filters('adflow_stats_require_token', true)) {
            return true;
        }

        $token = isset($_GET['k']) ? wp_unslash($_GET['k']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- checked against the signature.

        if ('' !== $token) {
            return self::token_valid($token);
        }

        // Pages cached before the upgrade have no token yet.
        $stored = get_option(self::KEY_OPTION);

        return is_array($stored) && !empty($stored['since']) && time() - (int) $stored['since'] < self::TOKEN_GRACE;
    }

    /**
     * Whether a request's Origin header belongs to this site. No header, or the
     * opaque "null", is not a mismatch: only another site's name is refused.
     *
     * Subdomains count as this site (www, language subdomains). Other domains of
     * the same site come from the core allowed_redirect_hosts filter, which
     * multi-domain translation plugins already use.
     *
     * @param string $origin Origin header.
     * @return bool
     * @since 1.6.0
     */
    public static function origin_allowed($origin)
    {
        if ('' === (string) $origin || 'null' === $origin) {
            return true;
        }

        $host = strtolower((string) wp_parse_url($origin, PHP_URL_HOST));

        if ('' === $host) {
            return false;
        }

        $own = array_filter(array(wp_parse_url(home_url(), PHP_URL_HOST), wp_parse_url(site_url(), PHP_URL_HOST)));

        // Merged: on multisite core's filter replaces the list with the network's domain.
        foreach (array_merge($own, (array) apply_filters('allowed_redirect_hosts', $own, $host)) as $allowed) {
            $allowed = preg_replace(array('/^www\./', '/:\d+$/'), '', strtolower((string) $allowed));
            $check = preg_replace('/^www\./', '', $host);

            if ('' !== $allowed && ($check === $allowed || substr('.' . $check, -strlen('.' . $allowed)) === '.' . $allowed || substr('.' . $allowed, -strlen('.' . $check)) === '.' . $check)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cloudflare's published address ranges (cloudflare.com/ips).
     *
     * @return string[]
     * @since 1.6.0
     */
    private static function cloudflare_ranges()
    {
        return array(
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
        );
    }

    /**
     * Whether an address is inside a CIDR range.
     *
     * @param string $ip Address.
     * @param string $range Address or CIDR range.
     * @return bool
     * @since 1.6.0
     */
    private static function ip_in_range($ip, $range)
    {
        $parts = explode('/', (string) $range, 2);
        $ip_bin = @inet_pton($ip); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input returns false.
        $net_bin = @inet_pton($parts[0]); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        if (false === $ip_bin || false === $net_bin || strlen($ip_bin) !== strlen($net_bin)) {
            return false;
        }

        $bits = isset($parts[1]) ? min((int) $parts[1], strlen($ip_bin) * 8) : strlen($ip_bin) * 8;
        $bytes = (int) floor($bits / 8);

        if (substr($ip_bin, 0, $bytes) !== substr($net_bin, 0, $bytes)) {
            return false;
        }

        if (0 === $bits % 8) {
            return true;
        }

        $mask = 0xFF << (8 - ($bits % 8)) & 0xFF;

        return (ord($ip_bin[$bytes]) & $mask) === (ord($net_bin[$bytes]) & $mask);
    }

    /**
     * Whether a connecting address is a proxy allowed to name the real client:
     * a private or loopback address (a reverse proxy on this network),
     * Cloudflare, or anything added through the adflow_trusted_proxies filter.
     *
     * @param string $ip Address.
     * @return bool
     * @since 1.6.0
     */
    private static function is_trusted_proxy($ip)
    {
        if (false === filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        if (false === filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true;
        }

        /**
         * Filters the proxies whose forwarding headers are believed.
         *
         * @param string[] $ranges Addresses or CIDR ranges. Default Cloudflare's.
         * @since 1.6.0
         */
        foreach ((array) apply_filters('adflow_trusted_proxies', self::cloudflare_ranges()) as $range) {
            if (self::ip_in_range($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The visitor's address, believing forwarding headers only when the
     * connection really comes from a trusted proxy (anyone can send the
     * headers). Used for abuse limits and never stored.
     *
     * @return string
     * @since 1.6.0
     */
    public static function client_ip()
    {
        $remote = isset($_SERVER['REMOTE_ADDR']) ? trim(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))) : '';
        $client = $remote;

        if (self::is_trusted_proxy($remote)) {
            $header = function ($name) {
                return isset($_SERVER[$name]) ? trim(sanitize_text_field(wp_unslash($_SERVER[$name]))) : '';
            };
            $cloudflare = false;

            foreach (self::cloudflare_ranges() as $range) {
                $cloudflare = $cloudflare || self::ip_in_range($remote, $range);
            }

            $connecting = $cloudflare ? $header('HTTP_CF_CONNECTING_IP') : '';

            if (false !== filter_var($connecting, FILTER_VALIDATE_IP)) {
                $client = $connecting;
            } else {
                // Each proxy appends what it saw: walk back to the first address
                // that is not a proxy. Anything further left is client-supplied.
                $hops = array_reverse(array_map('trim', explode(',', $header('HTTP_X_FORWARDED_FOR'))));
                $found = '';

                foreach ($hops as $hop) {
                    if (false === filter_var($hop, FILTER_VALIDATE_IP)) {
                        break;
                    }
                    $found = $hop;
                    if (!self::is_trusted_proxy($hop)) {
                        break;
                    }
                }

                $real = $header('HTTP_X_REAL_IP');
                $client = '' !== $found ? $found : (false !== filter_var($real, FILTER_VALIDATE_IP) ? $real : $remote);
            }
        }

        /**
         * Filters the address used for abuse limits.
         *
         * @param string $client Address.
         * @param string $remote The connecting address.
         * @since 1.6.0
         */
        return (string) apply_filters('adflow_client_ip', $client, $remote);
    }

    /**
     * A salted one-way number for a value. Many values share each number, so it
     * cannot be turned back into an address.
     *
     * @param string $value Value.
     * @return int
     * @since 1.6.0
     */
    private static function bucket_hash($value)
    {
        return (int) hexdec(substr(hash_hmac('sha256', (string) $value, self::secret()), 0, 7));
    }

    /**
     * Atomically add to a number's counter, starting over once $ttl seconds
     * have passed since its first hit.
     *
     * @param int $bucket Number.
     * @param int $ttl Seconds.
     * @param int $count Hits to add.
     * @return int The counter after this hit; $count when it cannot be read, so
     *             a broken table never stops counting.
     * @since 1.6.0
     */
    private static function hit($bucket, $ttl, $count)
    {
        global $wpdb;

        $table = self::limits_table();
        $now = time();
        $start = $now - (int) $ttl;
        $suppress = $wpdb->suppress_errors(true);

        // LAST_INSERT_ID(expr) hands the new value back without a second query.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $done = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (bucket, win, hits) VALUES (%d, %d, %d) ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(IF(win > %d, hits + VALUES(hits), VALUES(hits))), win = IF(win > %d, win, VALUES(win))",
            $bucket,
            $now,
            $count,
            $start,
            $start
        ));
        $total = (int) $wpdb->insert_id;

        $wpdb->suppress_errors($suppress);

        return false !== $done && $total > 0 ? $total : (int) $count;
    }

    /**
     * Add weighted events to this client's minute and say if it went over.
     *
     * Atomic, needs no persistent object cache, and adds no options rows.
     *
     * @param int $weight Events to add.
     * @return bool
     * @since 1.6.0
     */
    private static function rate_exceeded($weight)
    {
        /**
         * Filters the weighted events one client may send per minute (a click
         * weighs 5). Raise it behind a proxy not listed in adflow_trusted_proxies,
         * where all visitors share an address; 0 turns the limit off.
         *
         * @param int $limit
         * @since 1.6.0
         */
        $limit = (int) apply_filters('adflow_stats_rate_limit', self::RATE_LIMIT);

        if ($limit <= 0) {
            return false;
        }

        $ip = self::client_ip();
        $binary = false !== filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? inet_pton($ip) : false;

        // One person usually controls a whole IPv6 /64.
        $bucket = self::bucket_hash(false !== $binary ? substr($binary, 0, 8) : $ip) % self::RATE_BUCKETS;

        return self::hit($bucket, MINUTE_IN_SECONDS, (int) $weight) > $limit;
    }

    /**
     * A placement key safe to store: unknown keys count without a placement, so
     * visitors cannot create rows while third-party code keeps its totals.
     *
     * @param mixed $key Raw key.
     * @return string
     * @since 1.6.0
     */
    private static function clean_placement($key)
    {
        if (!is_string($key)) {
            return '';
        }

        $key = substr(sanitize_key($key), 0, 40);

        return Simple_Google_Adsense_Placements::is_valid_stats_key($key) ? $key : '';
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

        $body = (string) $body;

        // Refuse before decoding: decoding costs many times the body's size.
        if (strlen($body) > self::MAX_BODY) {
            return 0;
        }

        // A list of flat events needs no deeper nesting.
        $events = json_decode($body, true, 4);

        if (!is_array($events) || !$events) {
            return 0;
        }

        $events = array_slice($events, 0, self::MAX_EVENTS);
        $weight = 0;

        foreach ($events as $event) {
            $weight += is_array($event) && isset($event['t']) && 'c' === $event['t'] ? 5 : 1;
        }

        if (self::rate_exceeded($weight)) {
            return 0;
        }

        $rows = array();
        $clicks = array();
        $valid = array();
        $stored = 0;

        foreach ($events as $event) {
            if (!is_array($event) || empty($event['a']) || !is_scalar($event['a']) || !isset($event['t']) || !in_array($event['t'], array('i', 'v', 'c'), true)) {
                continue;
            }

            $ad_id = absint($event['a']);

            if (!isset($valid[$ad_id])) {
                $valid[$ad_id] = self::is_trackable($ad_id);
            }

            if (!$valid[$ad_id]) {
                continue;
            }

            $placement = isset($event['p']) ? self::clean_placement($event['p']) : '';
            $device = !empty($event['d']) ? 1 : 0;
            $key = $ad_id . '|' . $placement . '|' . $device;

            if ('c' === $event['t']) {
                if (!isset($clicks[$key])) {
                    $clicks[$key] = array('ad_id' => $ad_id, 'placement' => $placement, 'device' => $device, 'c' => 0);
                }
                $clicks[$key]['c']++;
            } else {
                if (!isset($rows[$key])) {
                    $rows[$key] = array('ad_id' => $ad_id, 'placement' => $placement, 'device' => $device, 'i' => 0, 'v' => 0, 'c' => 0);
                }
                $rows[$key][$event['t']]++;
            }

            $stored++;
        }

        // Impressions first: a click in the same batch needs its impression.
        self::write($rows);
        self::add_clicks($clicks);

        return $stored;
    }

    /**
     * Count clicks an ad's impressions cover today; set aside the rest. A later
     * impression (a slow beacon) lets reconcile_held_clicks() add them back.
     *
     * @param array[] $clicks Each: ad_id, placement, device, c.
     * @since 1.6.0
     */
    private static function add_clicks($clicks)
    {
        $by_ad = array();

        foreach ($clicks as $click) {
            $by_ad[(int) $click['ad_id']][] = $click;
        }

        $day = current_time('Y-m-d');

        foreach ($by_ad as $ad_id => $rows) {
            $lock = self::lock_ad($ad_id);
            $room = false === $lock ? 0 : self::click_room($day, $ad_id);
            $count = array();

            foreach ($rows as $row) {
                $take = min($row['c'], $room);
                $room -= $take;

                if ($take > 0) {
                    $count[] = array('ad_id' => $ad_id, 'placement' => $row['placement'], 'device' => $row['device'], 'i' => 0, 'v' => 0, 'c' => $take);
                }

                if ($row['c'] > $take) {
                    self::change_held($day, $ad_id, $row['placement'], $row['device'], $row['c'] - $take);
                }
            }

            self::write($count);
            self::unlock_ad($ad_id, $lock);
        }
    }

    /**
     * Serialise the check-then-write for one ad, so simultaneous requests cannot
     * all spend the same impression. Waits at most a second.
     *
     * @param int $ad_id Ad ID.
     * @return bool|null True locked, null locks unavailable here (some clusters), false timed out.
     * @since 1.6.0
     */
    private static function lock_ad($ad_id)
    {
        global $wpdb;

        $suppress = $wpdb->suppress_errors(true);
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 1)', self::lock_name($ad_id))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->suppress_errors($suppress);

        if ('1' === (string) $got) {
            return true;
        }

        return '0' === (string) $got ? false : null;
    }

    /**
     * Release lock_ad().
     *
     * @param int $ad_id Ad ID.
     * @param bool|null $lock What lock_ad() returned.
     * @since 1.6.0
     */
    private static function unlock_ad($ad_id, $lock)
    {
        global $wpdb;

        if (true === $lock) {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lock_name($ad_id))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        }
    }

    /**
     * Lock name, unique per database and site (at most 64 characters).
     *
     * @param int $ad_id Ad ID.
     * @return string
     * @since 1.6.0
     */
    private static function lock_name($ad_id)
    {
        global $wpdb;

        return 'afx_' . md5($wpdb->dbname . '|' . $wpdb->prefix . '|' . (int) $ad_id);
    }

    /**
     * How many more clicks an ad's impressions cover on a day.
     *
     * @param string $day Y-m-d.
     * @param int $ad_id Ad ID.
     * @return int
     * @since 1.6.0
     */
    private static function click_room($day, $ad_id)
    {
        global $wpdb;

        $table = self::table();

        return max(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(impressions), 0) - COALESCE(SUM(clicks), 0) FROM {$table} WHERE ad_id = %d AND day = %s", $ad_id, $day))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    /**
     * Add to the clicks set aside.
     *
     * @param string $day Y-m-d.
     * @param int $ad_id Ad ID.
     * @param string $placement Placement.
     * @param int $device Device.
     * @param int $count Clicks.
     * @since 1.6.0
     */
    private static function change_held($day, $ad_id, $placement, $device, $count)
    {
        global $wpdb;

        $table = self::held_table();
        $suppress = $wpdb->suppress_errors(true);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query($wpdb->prepare("INSERT INTO {$table} (day, ad_id, placement, device, clicks) VALUES (%s, %d, %s, %d, %d) ON DUPLICATE KEY UPDATE clicks = clicks + VALUES(clicks)", $day, $ad_id, $placement, $device ? 1 : 0, $count));

        $wpdb->suppress_errors($suppress);
    }

    /**
     * Move clicks that were set aside back into the totals where the day's
     * impressions now cover them. A click can beat its own impression to the
     * server (two beacons on separate connections), so totals stay exact.
     *
     * @since 1.6.0
     */
    public static function reconcile_held_clicks()
    {
        global $wpdb;

        $table = self::held_table();
        $suppress = $wpdb->suppress_errors(true);
        $rows = $wpdb->get_results("SELECT day, ad_id, placement, device, clicks FROM {$table} ORDER BY day, ad_id LIMIT 500", ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->suppress_errors($suppress);

        $moved = false;

        foreach ((array) $rows as $row) {
            $ad_id = (int) $row['ad_id'];
            $lock = self::lock_ad($ad_id);

            if (false === $lock) {
                continue;
            }

            $take = min((int) $row['clicks'], self::click_room($row['day'], $ad_id));

            if ($take > 0) {
                self::write(array(array('ad_id' => $ad_id, 'placement' => $row['placement'], 'device' => $row['device'], 'i' => 0, 'v' => 0, 'c' => $take)), $row['day']);
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $wpdb->query($wpdb->prepare("UPDATE {$table} SET clicks = clicks - %d WHERE day = %s AND ad_id = %d AND placement = %s AND device = %d", $take, $row['day'], $ad_id, $row['placement'], $row['device']));
                $moved = true;
            }

            self::unlock_ad($ad_id, $lock);
        }

        if ($moved) {
            $wpdb->query("DELETE FROM {$table} WHERE clicks = 0"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            self::flush_report_cache();
        }
    }

    /**
     * Clicks set aside in a report range.
     *
     * @param array $args Range and filters.
     * @return int
     * @since 1.6.0
     */
    public static function held_clicks($args = array())
    {
        global $wpdb;

        self::reconcile_held_clicks();

        $args = self::range($args);
        $table = self::held_table();
        $suppress = $wpdb->suppress_errors(true);
        $held = (int) $wpdb->get_var("SELECT SUM(clicks) FROM {$table} WHERE " . self::where($args)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- where() uses $wpdb->prepare().
        $wpdb->suppress_errors($suppress);

        return $held;
    }

    /**
     * Plain statement of what these numbers are, for anywhere an advertiser or
     * site owner reads them.
     *
     * @return string
     * @since 1.6.0
     */
    public static function estimate_note()
    {
        return __('These figures are estimates counted in visitors\' browsers. They are not audited, and bad actors can add to them.', 'simple-google-adsense');
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
     * @param string|null $day Y-m-d (default today).
     * @since 1.4.0
     */
    public static function write($rows, $day = null)
    {
        global $wpdb;

        if (!$rows) {
            return;
        }

        $day = null === $day ? current_time('Y-m-d') : $day;
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

        $held = self::held_table();
        $limits = self::limits_table();
        $cutoff = gmdate('Y-m-d', strtotime('-' . $months . ' months'));

        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE day < %s", $cutoff)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $suppress = $wpdb->suppress_errors(true);
        $wpdb->query($wpdb->prepare("DELETE FROM {$held} WHERE day < %s", $cutoff)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query($wpdb->prepare("DELETE FROM {$limits} WHERE win < %d", time() - DAY_IN_SECONDS)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->suppress_errors($suppress);

        self::flush_report_cache();
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

        $held = self::held_table();

        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE ad_id = %d", $post_id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $suppress = $wpdb->suppress_errors(true);
        $wpdb->query($wpdb->prepare("DELETE FROM {$held} WHERE ad_id = %d", $post_id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->suppress_errors($suppress);

        self::flush_report_cache();
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

        if (!self::is_date($args['from']) || !self::is_date($args['to'])) {
            $days = is_scalar($args['days']) ? (int) $args['days'] : 30;
            $args['to'] = current_time('Y-m-d');
            $args['from'] = gmdate('Y-m-d', strtotime($args['to'] . ' 00:00:00 UTC -' . (min(self::MAX_RANGE_DAYS, max(1, $days)) - 1) . ' days'));

            return $args;
        }

        if ($args['from'] > $args['to']) {
            $from = $args['from'];
            $args['from'] = $args['to'];
            $args['to'] = $from;
        }

        // A longer range cannot hold data (retention tops out at 120 months)
        // and would make the per-day fill below run out of memory.
        $earliest = gmdate('Y-m-d', strtotime($args['to'] . ' 00:00:00 UTC -' . (self::MAX_RANGE_DAYS - 1) . ' days'));

        if ($args['from'] < $earliest) {
            $args['from'] = $earliest;
        }

        return $args;
    }

    /**
     * Whether a value is a real Y-m-d date.
     *
     * @param mixed $value Value.
     * @return bool
     * @since 1.6.0
     */
    public static function is_date($value)
    {
        return is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * Summed counts, in one row or grouped by a column.
     *
     * Days before today can no longer change, so their sum is cached (a year on
     * a busy site takes seconds to add up). Today is always read live, so reports
     * stay exact. Short ranges are cheap and not worth a cache entry.
     *
     * @param string $column ad_id|placement|device|day, or '' for one row.
     * @param array $args Normalised range and filters.
     * @return array[] key => i, v, c
     * @since 1.6.0
     */
    private static function sums($column, $args)
    {
        $today = current_time('Y-m-d');
        $yesterday = gmdate('Y-m-d', strtotime($today . ' 00:00:00 UTC -1 day'));
        $past_to = min($args['to'], $yesterday);
        $out = array();

        if ($args['from'] <= $past_to) {
            $past = array_merge($args, array('to' => $past_to));

            if (strtotime($past_to . ' 00:00:00 UTC') - strtotime($args['from'] . ' 00:00:00 UTC') < 14 * DAY_IN_SECONDS) {
                $out = self::sums_query($column, $past);
            } else {
                $ids = !empty($args['ad_id']) ? array_map('absint', (array) $args['ad_id']) : array();
                sort($ids);
                $key = 'afx_r_' . md5(wp_json_encode(array($column, $args['from'], $past_to, $ids, isset($args['placement']) ? $args['placement'] : '', get_option(self::CACHE_OPTION, 1))));
                $out = get_transient($key);

                if (!is_array($out)) {
                    $out = self::sums_query($column, $past);

                    // A pile of junk placements is not worth keeping in options.
                    if (count($out) <= 4000) {
                        set_transient($key, $out, 6 * HOUR_IN_SECONDS);
                    }
                }
            }
        }

        if ($args['to'] >= $today) {
            $live = self::sums_query($column, array_merge($args, array('from' => max($args['from'], $today))));

            foreach ($live as $key => $row) {
                foreach (array('i', 'v', 'c') as $metric) {
                    $out[$key][$metric] = (isset($out[$key][$metric]) ? $out[$key][$metric] : 0) + $row[$metric];
                }
            }

            if ($out && $live && '' !== $column) {
                ksort($out);
            }
        }

        return $out;
    }

    /**
     * Run the sum query.
     *
     * @param string $column Whitelisted column, or ''.
     * @param array $args Normalised range and filters.
     * @return array[] key => i, v, c
     * @since 1.6.0
     */
    private static function sums_query($column, $args)
    {
        global $wpdb;

        $table = self::table();
        $select = '' !== $column ? "{$column} AS k, " : "'' AS k, ";
        $group = '' !== $column ? " GROUP BY {$column} ORDER BY {$column}" : '';
        $results = $wpdb->get_results("SELECT {$select}SUM(impressions) AS i, SUM(viewable) AS v, SUM(clicks) AS c FROM {$table} WHERE " . self::where($args) . $group, ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $column is whitelisted by the caller; where() uses $wpdb->prepare().
        $out = array();

        foreach ((array) $results as $row) {
            $out[$row['k']] = array('i' => (int) $row['i'], 'v' => (int) $row['v'], 'c' => (int) $row['c']);
        }

        return $out;
    }

    /**
     * Forget cached report sums (after anything changes past days).
     *
     * @since 1.6.0
     */
    public static function flush_report_cache()
    {
        update_option(self::CACHE_OPTION, time() . '-' . wp_rand(), false);
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
        $args = self::range($args);
        $sums = self::sums('', $args);
        $row = isset($sums['']) ? $sums[''] : array('i' => 0, 'v' => 0, 'c' => 0);

        return self::with_rates(array('impressions' => $row['i'], 'viewable' => $row['v'], 'clicks' => $row['c']));
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
        if (!in_array($column, array('ad_id', 'placement', 'device', 'day'), true)) {
            return array();
        }

        $args = self::range($args);
        $out = array();

        foreach (self::sums($column, $args) as $key => $row) {
            $out[$key] = self::with_rates(array('impressions' => $row['i'], 'viewable' => $row['v'], 'clicks' => $row['c']));
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
                <?php $A::field_start(__('Statistics', 'simple-google-adsense'), __('Cookieless. Reports store daily totals only - no IP addresses or visitor IDs. Works with page caching. To stop abuse of the counter, a one-way number shared by many visitors is kept for up to a day.', 'simple-google-adsense')); ?>
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
                        <li><?php esc_html_e('Limits: requests from other sites and very fast repeats are ignored, and a day\'s clicks on an ad cannot pass its impressions. The rest are set aside and shown in Reports.', 'simple-google-adsense'); ?></li>
                        <li><?php esc_html_e('Days follow your site\'s time zone (Settings → General).', 'simple-google-adsense'); ?></li>
                        <li><?php echo esc_html(self::estimate_note()); ?></li>
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

        $text = __('This site shows advertisements, including Google AdSense. Google may use cookies to serve ads - see https://policies.google.com/technologies/ads.

For its own advertisements, this site counts how often each ad is shown, seen and clicked. The reports keep only daily totals per ad, with no IP addresses or visitor IDs, and the counting sets no cookies.

To limit abuse of this counter, a salted one-way number worked out from the visitor\'s IP address (and, for redirect links, the browser name) is kept in this site\'s database for up to one day. Many visitors share each number, and it cannot be turned back into an address.

If an ad rotation shows ads in a fixed order, the browser\'s local storage remembers the position in that order. Nothing is sent to this site.', 'simple-google-adsense');

        wp_add_privacy_policy_content('AdFlow', wp_kses_post(wpautop($text)));
    }

    /**
     * Register the personal data exporter.
     *
     * @param array $exporters Exporters.
     * @return array
     * @since 1.6.0
     */
    public function register_exporter($exporters)
    {
        $exporters['adflow'] = array(
            'exporter_friendly_name' => __('AdFlow ad units', 'simple-google-adsense'),
            'callback' => array($this, 'export_personal_data'),
        );

        return $exporters;
    }

    /**
     * Register the personal data eraser.
     *
     * @param array $erasers Erasers.
     * @return array
     * @since 1.6.0
     */
    public function register_eraser($erasers)
    {
        $erasers['adflow'] = array(
            'eraser_friendly_name' => __('AdFlow ad units', 'simple-google-adsense'),
            'callback' => array($this, 'erase_personal_data'),
        );

        return $erasers;
    }

    /**
     * IDs of the ad units a user wrote, one batch at a time.
     *
     * @param int $user_id User ID.
     * @param int $page Page.
     * @return int[]
     * @since 1.6.0
     */
    private static function authored_ads($user_id, $page)
    {
        return get_posts(array(
            'post_type' => Simple_Google_Adsense_Ad_Units::POST_TYPE,
            'post_status' => array_keys(get_post_stati()),
            'author' => (int) $user_id,
            'posts_per_page' => 100,
            'paged' => (int) $page,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
        ));
    }

    /**
     * Export what ties a person to AdFlow. Visitor statistics are anonymous
     * totals and cannot be matched to an email address, so the only personal
     * link in the free plugin is the author of an ad unit.
     *
     * @param string $email Email address.
     * @param int $page Page.
     * @return array data, done
     * @since 1.6.0
     */
    public function export_personal_data($email, $page = 1)
    {
        $user = get_user_by('email', $email);

        if (!$user) {
            return array('data' => array(), 'done' => true);
        }

        $ids = self::authored_ads($user->ID, $page);
        $data = array();

        foreach ($ids as $id) {
            $data[] = array(
                'group_id' => 'adflow-ad-units',
                'group_label' => __('Ad units', 'simple-google-adsense'),
                'item_id' => 'adflow-ad-' . $id,
                'data' => array(
                    array('name' => __('Ad unit', 'simple-google-adsense'), 'value' => get_the_title($id)),
                    array('name' => __('Created', 'simple-google-adsense'), 'value' => get_the_date('Y-m-d', $id)),
                ),
            );
        }

        return array('data' => $data, 'done' => count($ids) < 100);
    }

    /**
     * Remove a person's link to the ad units they wrote. The ads keep running:
     * they belong to the site, and carry no author on the front end.
     *
     * @param string $email Email address.
     * @param int $page Page (each pass removes what it handled, so it stays at the first batch).
     * @return array items_removed, items_retained, messages, done
     * @since 1.6.0
     */
    public function erase_personal_data($email, $page = 1)
    {
        $user = get_user_by('email', $email);

        if (!$user) {
            return array('items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true);
        }

        global $wpdb;

        $ids = self::authored_ads($user->ID, 1);

        foreach ($ids as $id) {
            // Directly: saving through the ad screen's hooks would change the ad.
            $wpdb->update($wpdb->posts, array('post_author' => 0), array('ID' => $id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            clean_post_cache($id);
        }

        return array('items_removed' => (bool) $ids, 'items_retained' => false, 'messages' => array(), 'done' => count($ids) < 100);
    }
}
