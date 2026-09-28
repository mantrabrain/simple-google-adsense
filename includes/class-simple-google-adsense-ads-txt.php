<?php
/**
 * Simple_Google_Adsense ads.txt manager
 *
 * Serves a virtual /ads.txt containing the AdSense line (plus any custom
 * lines) and checks that the live file actually authorises the publisher -
 * the cause of AdSense's "Earnings at risk" warning.
 *
 * @package Simple_Google_Adsense
 * @since   1.4.0
 */

defined('ABSPATH') || exit;

/**
 * Simple_Google_Adsense_Ads_Txt Class.
 *
 * @class Simple_Google_Adsense_Ads_Txt
 */
final class Simple_Google_Adsense_Ads_Txt
{

    /**
     * Option holding ads.txt settings.
     */
    const OPTION_NAME = 'simple_google_adsense_ads_txt';

    /**
     * Admin page slug.
     */
    const PAGE_SLUG = 'adflow-ads-txt';

    /**
     * Google's certification authority ID for AdSense.
     */
    const GOOGLE_CERT_ID = 'f08c47fec0942fa0';

    /**
     * Transient holding the last live check result.
     */
    const CHECK_TRANSIENT = 'adflow_ads_txt_check';

    /**
     * The single instance of the class.
     *
     * @var Simple_Google_Adsense_Ads_Txt
     * @since 1.4.0
     */
    protected static $_instance = null;

    /**
     * Main Simple_Google_Adsense_Ads_Txt Instance.
     *
     * @return Simple_Google_Adsense_Ads_Txt
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
     * Simple_Google_Adsense_Ads_Txt Constructor.
     */
    public function __construct()
    {
        // Early, before WordPress routes the request to a 404.
        add_action('init', array($this, 'maybe_serve'), 1);
        add_action('admin_init', array($this, 'register_setting'));
        add_filter('adflow_settings_tabs', array($this, 'register_tab'), 20);
        add_action('admin_post_adflow_check_ads_txt', array($this, 'handle_check'));
        // add_option_* too: the very first save creates the option instead of updating it.
        foreach (array(self::OPTION_NAME, Simple_Google_Adsense_Settings::OPTION_NAME) as $option) {
            add_action('add_option_' . $option, array($this, 'clear_check'));
            add_action('update_option_' . $option, array($this, 'clear_check'));
        }
    }

    /**
     * Settings merged over defaults.
     *
     * Serving is opt-in so upgrading never changes what /ads.txt returns.
     *
     * @return array
     * @since 1.4.0
     */
    public static function get_settings()
    {
        $settings = get_option(self::OPTION_NAME);

        return wp_parse_args(is_array($settings) ? $settings : array(), array(
            'enabled' => false,
            'custom_lines' => '',
        ));
    }

    /**
     * The AdSense line for this publisher.
     *
     * @return string Empty when no Publisher ID is set.
     * @since 1.4.0
     */
    public static function get_adsense_line()
    {
        $publisher_id = Simple_Google_Adsense_Settings::get_publisher_id();

        if ('' === $publisher_id) {
            return '';
        }

        return 'google.com, ' . $publisher_id . ', DIRECT, ' . self::GOOGLE_CERT_ID;
    }

    /**
     * Full ads.txt body.
     *
     * @return string
     * @since 1.4.0
     */
    public static function get_content()
    {
        $settings = self::get_settings();
        $lines = array();
        $adsense_line = self::get_adsense_line();

        if ('' !== $adsense_line) {
            $lines[] = $adsense_line;
        }

        $custom = trim((string) $settings['custom_lines']);

        // Lines Google would reject are left out (the settings screen lists them).
        $invalid = self::invalid_lines($custom);
        if ($invalid) {
            $custom = trim(implode("\n", array_diff(preg_split('/\r\n|\r|\n/', $custom), $invalid)));
        }

        if ('' !== $custom) {
            $lines[] = $custom;
        }

        /**
         * Filters the ads.txt body served by AdFlow.
         *
         * @param string $content
         * @since 1.4.0
         */
        return apply_filters('adflow_ads_txt_content', implode("\n", $lines) . "\n");
    }

    /**
     * ads.txt must live at the domain root. A site in a subdirectory
     * (example.com/blog) cannot serve it from WordPress.
     *
     * @return bool
     * @since 1.4.0
     */
    public static function is_root_install()
    {
        $path = wp_parse_url(home_url('/'), PHP_URL_PATH);

        return empty($path) || '/' === $path;
    }

    /**
     * Whether a physical ads.txt file exists (the web server serves it
     * directly, so the virtual one is never reached).
     *
     * @return bool
     * @since 1.4.0
     */
    public static function physical_file_exists()
    {
        // The web root, which differs from ABSPATH when WordPress lives in its own folder.
        if (!function_exists('get_home_path')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        return file_exists(trailingslashit(get_home_path()) . 'ads.txt') || file_exists(trailingslashit(ABSPATH) . 'ads.txt');
    }

    /**
     * Serve the virtual ads.txt.
     *
     * @since 1.4.0
     */
    public function maybe_serve()
    {
        if (empty($_SERVER['REQUEST_URI'])) {
            return;
        }

        $path = wp_parse_url(esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])), PHP_URL_PATH);

        if ('/ads.txt' !== $path) {
            return;
        }

        $settings = self::get_settings();

        if (empty($settings['enabled']) || !self::is_root_install()) {
            return;
        }

        if (!headers_sent()) {
            status_header(200);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: public, max-age=3600');
            header('X-Robots-Tag: noindex');
            header('X-Content-Type-Options: nosniff');
        }

        echo self::get_content(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text file.
        exit;
    }

    /**
     * Fetch the live /ads.txt and check it authorises this publisher.
     *
     * @param bool $force Skip the cached result.
     * @return array status (ok|missing_line|not_found|error|no_publisher|not_root), message, checked_at
     * @since 1.4.0
     */
    public static function check($force = false)
    {
        if (!$force) {
            $cached = get_transient(self::CHECK_TRANSIENT);

            if (is_array($cached)) {
                return $cached;
            }
        }

        $publisher_id = Simple_Google_Adsense_Settings::get_publisher_id();

        if ('' === $publisher_id) {
            $result = array(
                'status' => 'no_publisher',
                'message' => __('Add your Publisher ID first.', 'simple-google-adsense'),
            );
        } elseif (!self::is_root_install()) {
            $result = array(
                'status' => 'not_root',
                'message' => __('WordPress is installed in a subdirectory. ads.txt must be uploaded to your domain root manually.', 'simple-google-adsense'),
            );
        } else {
            $url = self::get_url();
            $response = wp_remote_get($url, array(
                'timeout' => 5,
                'redirection' => 3,
                'sslverify' => apply_filters('https_local_ssl_verify', false),
            ));

            if (is_wp_error($response)) {
                $result = array(
                    'status' => 'error',
                    /* translators: %s: error message */
                    'message' => sprintf(__('Could not fetch ads.txt: %s', 'simple-google-adsense'), $response->get_error_message()),
                );
            } elseif (200 !== (int) wp_remote_retrieve_response_code($response)) {
                $result = array(
                    'status' => 'not_found',
                    /* translators: %d: HTTP status code */
                    'message' => sprintf(__('ads.txt returned HTTP %d. AdSense will show "Earnings at risk".', 'simple-google-adsense'), (int) wp_remote_retrieve_response_code($response)),
                );
            } elseif (!self::body_contains_publisher(wp_remote_retrieve_body($response), $publisher_id)) {
                $result = array(
                    'status' => 'missing_line',
                    'message' => __('ads.txt exists but does not contain your AdSense line.', 'simple-google-adsense'),
                );
            } else {
                $result = array(
                    'status' => 'ok',
                    'message' => __('ads.txt is live and authorises your Publisher ID.', 'simple-google-adsense'),
                );
            }
        }

        $result['checked_at'] = time();
        // Keep good results for 12 hours; re-check problems sooner (unreachable hosts: hourly).
        set_transient(self::CHECK_TRANSIENT, $result, 'ok' === $result['status'] ? 12 * HOUR_IN_SECONDS : ('error' === $result['status'] ? HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS));

        return $result;
    }

    /**
     * Whether an ads.txt body has a google.com line for the publisher.
     *
     * @param string $body File body.
     * @param string $publisher_id pub-XXXX.
     * @return bool
     * @since 1.4.0
     */
    public static function body_contains_publisher($body, $publisher_id)
    {
        foreach (preg_split('/\r\n|\r|\n/', (string) $body) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            $fields = array_map('trim', explode(',', $line));

            if (count($fields) >= 3 && 'google.com' === strtolower($fields[0]) && strtolower($publisher_id) === strtolower($fields[1])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lines that do not follow the ads.txt format.
     *
     * @param string $text Lines.
     * @return string[] Invalid lines.
     * @since 1.4.0
     */
    public static function invalid_lines($text)
    {
        $invalid = array();

        foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
            $clean = trim(preg_replace('/#.*$/', '', $line));

            // Blank lines, comments and variables (contact=, subdomain=, …) are valid.
            if ('' === $clean || preg_match('/^[a-z]+=\S/i', $clean)) {
                continue;
            }

            $fields = array_map('trim', explode(',', $clean));

            if (count($fields) < 3 || '' === $fields[0] || '' === $fields[1] || !in_array(strtoupper($fields[2]), array('DIRECT', 'RESELLER'), true)) {
                $invalid[] = $line;
            }
        }

        return $invalid;
    }

    /**
     * Public ads.txt URL.
     *
     * @return string
     * @since 1.4.0
     */
    public static function get_url()
    {
        $parts = wp_parse_url(home_url());

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/ads.txt';
    }

    /**
     * Clear the cached check.
     *
     * @since 1.4.0
     */
    public function clear_check()
    {
        delete_transient(self::CHECK_TRANSIENT);
    }

    /**
     * Handle the "Check now" button.
     *
     * @since 1.4.0
     */
    public function handle_check()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'simple-google-adsense'));
        }

        check_admin_referer('adflow_check_ads_txt');

        self::check(true);

        $redirect = isset($_POST['redirect']) ? esc_url_raw(wp_unslash($_POST['redirect'])) : '';

        wp_safe_redirect($redirect ? $redirect : Simple_Google_Adsense_Admin::settings_url('ads-txt'));
        exit;
    }

    /**
     * Register the setting.
     *
     * @since 1.4.0
     */
    public function register_setting()
    {
        register_setting('adflow_ads_txt', self::OPTION_NAME, array(
            'sanitize_callback' => array($this, 'sanitize'),
        ));
    }

    /**
     * Sanitize settings.
     *
     * @param array $input Raw input.
     * @return array
     * @since 1.4.0
     */
    public function sanitize($input)
    {
        $input = is_array($input) ? $input : array();
        $lines = isset($input['custom_lines']) ? preg_split('/\r\n|\r|\n/', (string) $input['custom_lines']) : array();

        return array(
            'enabled' => !empty($input['enabled']),
            'custom_lines' => implode("\n", array_map('sanitize_text_field', $lines)),
        );
    }

    /**
     * Add the ads.txt tab to Settings.
     *
     * @param array $tabs Tabs.
     * @return array
     * @since 1.4.0
     */
    public function register_tab($tabs)
    {
        $tabs['ads-txt'] = array(
            'label' => __('ads.txt', 'simple-google-adsense'),
            'callback' => array($this, 'render_tab'),
            'priority' => 20,
        );

        return $tabs;
    }

    /**
     * Render a check result line.
     *
     * @param array $result Result from check().
     * @param string $redirect Where the "Check now" button returns to.
     * @since 1.4.0
     */
    public static function render_status($result, $redirect)
    {
        $ok = 'ok' === $result['status'];
        ?>
        <div class="adflow-status-line">
            <span class="adflow-pill adflow-pill--<?php echo $ok ? 'ok' : 'warning'; ?>"><?php echo $ok ? esc_html__('Authorized', 'simple-google-adsense') : esc_html__('Needs attention', 'simple-google-adsense'); ?></span>
            <span><?php echo esc_html($result['message']); ?></span>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="adflow-inline-form">
                <input type="hidden" name="action" value="adflow_check_ads_txt">
                <input type="hidden" name="redirect" value="<?php echo esc_attr($redirect); ?>">
                <?php wp_nonce_field('adflow_check_ads_txt'); ?>
                <button type="submit" class="button button-small"><?php esc_html_e('Check again', 'simple-google-adsense'); ?></button>
            </form>
        </div>
        <?php
    }

    /**
     * Render the ads.txt tab.
     *
     * @since 1.4.0
     */
    public function render_tab()
    {
        $settings = self::get_settings();
        $result = self::check();
        $adsense_line = self::get_adsense_line();
        $name = self::OPTION_NAME;
        $invalid = self::invalid_lines($settings['custom_lines']);
        ?>
        <section class="adflow-panel">
            <header class="adflow-panel__head">
                <div>
                    <h2><?php esc_html_e('Status', 'simple-google-adsense'); ?></h2>
                    <p><?php esc_html_e('Google requires an ads.txt file that lists your Publisher ID. Without it AdSense shows "Earnings at risk" and may limit ads.', 'simple-google-adsense'); ?></p>
                </div>
            </header>
            <div class="adflow-panel__body adflow-panel__body--padded">
                <?php self::render_status($result, Simple_Google_Adsense_Admin::settings_url('ads-txt')); ?>
                <?php if (!self::is_root_install()) : ?>
                    <p class="description"><?php esc_html_e('This site lives in a subdirectory, so WordPress cannot answer requests for /ads.txt at the domain root. Upload the file shown below to the root of your domain instead.', 'simple-google-adsense'); ?></p>
                <?php elseif (self::physical_file_exists()) : ?>
                    <p class="description"><?php esc_html_e('A physical ads.txt file exists on your server. It is served instead of AdFlow\'s, so the settings below have no effect until you delete or rename it.', 'simple-google-adsense'); ?></p>
                <?php endif; ?>
            </div>
        </section>

        <form action="options.php" method="post">
            <?php settings_fields('adflow_ads_txt'); ?>

            <?php Simple_Google_Adsense_Admin::panel_start(__('ads.txt file', 'simple-google-adsense')); ?>
                <?php Simple_Google_Adsense_Admin::field_start(__('Serve ads.txt', 'simple-google-adsense'), __('AdFlow answers requests for /ads.txt - no FTP needed.', 'simple-google-adsense')); ?>
                    <?php Simple_Google_Adsense_Admin::toggle($name . '[enabled]', !empty($settings['enabled']), __('Let AdFlow manage ads.txt', 'simple-google-adsense')); ?>
                    <p class="description"><a href="<?php echo esc_url(self::get_url()); ?>" target="_blank" rel="noopener"><?php echo esc_html(self::get_url()); ?></a></p>
                <?php Simple_Google_Adsense_Admin::field_end(); ?>

                <?php Simple_Google_Adsense_Admin::field_start(__('AdSense line', 'simple-google-adsense'), __('Added automatically from your Publisher ID.', 'simple-google-adsense')); ?>
                    <?php if ('' !== $adsense_line) : ?>
                        <code class="adflow-code"><?php echo esc_html($adsense_line); ?></code>
                    <?php else : ?>
                        <a href="<?php echo esc_url(Simple_Google_Adsense_Admin::settings_url()); ?>"><?php esc_html_e('Add your Publisher ID first', 'simple-google-adsense'); ?></a>
                    <?php endif; ?>
                <?php Simple_Google_Adsense_Admin::field_end(); ?>

                <?php Simple_Google_Adsense_Admin::field_start(__('Other ad networks', 'simple-google-adsense'), __('One line per network, exactly as the network gives it to you.', 'simple-google-adsense'), 'adflow-ads-txt-custom'); ?>
                    <textarea id="adflow-ads-txt-custom" name="<?php echo esc_attr($name); ?>[custom_lines]" rows="6" class="large-text code" placeholder="example.com, 12345, DIRECT"><?php echo esc_textarea($settings['custom_lines']); ?></textarea>
                    <?php if ($invalid) : ?>
                        <p class="adflow-field__error">
                            <?php esc_html_e('These lines do not follow the ads.txt format (domain, account ID, DIRECT or RESELLER) and will be ignored:', 'simple-google-adsense'); ?>
                            <code><?php echo esc_html(implode(' | ', $invalid)); ?></code>
                        </p>
                    <?php endif; ?>
                <?php Simple_Google_Adsense_Admin::field_end(); ?>

                <?php Simple_Google_Adsense_Admin::field_start(__('Preview', 'simple-google-adsense')); ?>
                    <pre class="adflow-code"><?php echo esc_html(self::get_content()); ?></pre>
                <?php Simple_Google_Adsense_Admin::field_end(); ?>
            <?php Simple_Google_Adsense_Admin::panel_end(); ?>

            <div class="adflow-savebar">
                <?php submit_button(__('Save changes', 'simple-google-adsense'), 'primary', 'submit', false); ?>
            </div>
        </form>
        <?php
    }
}
